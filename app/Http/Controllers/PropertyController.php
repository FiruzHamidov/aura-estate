<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class PropertyController extends Controller
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    // ==== Список (как у тебя), но на общих методах ====
    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        $this->applyFilters($query, $request);
        $this->applySorts($query, $request->input('sort'), $request->input('dir'));
        $perPage = (int)$request->input('per_page', 20);
        return response()->json($query->latest()->paginate($perPage));
    }

    // ==== Общая база для index/map: роли, связи, базовые статусы ====
    private function baseQuery(Request $request): Builder
    {
        $user = auth()->user();
        $query = Property::query()->with(['type', 'status', 'location', 'repairType', 'photos', 'creator']);

        $hasStatusFilter = $request->filled('moderation_status');

        if ($user && $user->hasRole('admin')) {
            // без ограничений
        } elseif (!$user) {
            $query->where('moderation_status', 'approved');
        } elseif ($user->hasRole('agent')) {
            $query->where('created_by', $user->id);
            if (!$hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        } elseif ($user->hasRole('client')) {
            if (!$hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        }

        return $query;
    }

    // ==== Карта: bbox + zoom + кластеризация/точки ====
    public function map(Request $request)
    {
        // bbox: south,west,north,east
        $bboxRaw = $request->query('bbox', '');
        $parts = array_map('trim', explode(',', $bboxRaw));
        if (count($parts) !== 4) {
            return response()->json(['error' => 'Invalid bbox. Expected south,west,north,east'], 400);
        }
        [$south, $west, $north, $east] = array_map('floatval', $parts);

        // Нормализация (на случай перепутанных значений)
        if ($south > $north) [$south, $north] = [$north, $south];
        if ($west > $east) [$west, $east] = [$east, $west];

        $zoom = (int)$request->query('zoom', 12);
        $zoom = max(1, min(22, $zoom));

        // Ключ кэша: bbox округлим до сетки, чтобы лучше переиспользовать
        $round = fn(float $n) => round($n * 400) / 400; // ~0.0025°
        $bboxKey = implode(',', [$round($south), $round($west), $round($north), $round($east)]);
        $filtersKey = md5(json_encode($request->except(['bbox', 'zoom'])));

        $cacheKey = "map:{$bboxKey}:z{$zoom}:{$filtersKey}";
        $ttl = now()->addSeconds(20);

        return Cache::remember($cacheKey, $ttl, function () use ($request, $south, $west, $north, $east, $zoom) {
            $query = $this->baseQuery($request);

            // Ограничение по bbox (полям latitude/longitude)
            $query->whereBetween('latitude', [$south, $north])
                ->whereBetween('longitude', [$west, $east]);

            // Применяем те же фильтры, что и в списке
            $this->applyFilters($query, $request);

            // Safety cap (не отдавать десятки тысяч)
            $limit = 5000;

            // Низкие зумы: грубая кластеризация "по сетке"
            if ($zoom <= 11) {
                $cell = 0.02; // шаг сетки ~2 км (подберите под город)
                $rows = $query
                    ->selectRaw("
                        FLOOR(latitude  / {$cell}) as gx,
                        FLOOR(longitude / {$cell}) as gy,
                        COUNT(*) as cnt,
                        AVG(latitude)  as lat_avg,
                        AVG(longitude) as lng_avg
                    ")
                    ->groupBy('gx', 'gy')
                    ->limit($limit)
                    ->get();

                $features = $rows->map(function ($r) {
                    return [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            // ВНИМАНИЕ: проверь порядок в вашей карте. Для Yandex чаще [lat, lng]
                            'coordinates' => [(float)$r->lat_avg, (float)$r->lng_avg],
                        ],
                        'property' => [
                            'cluster' => true,
                            'point_count' => (int)$r->cnt,
                        ],
                    ];
                })->values();

                return response()->json([
                    'type' => 'FeatureCollection',
                    'features' => $features,
                ]);
            }

            // Высокие зумы: отдаём точки
            $points = $query
                ->select(['id', 'title', 'price', 'latitude', 'longitude'])
                ->limit($limit)
                ->get();

            $features = $points->map(function ($p) {
                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float)$p->latitude, (float)$p->longitude],
                    ],
                    'property' => [
                        'id' => (int)$p->id,
                        'title' => (string)$p->title,
                    ],
                ];
            })->values();

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);
        });
    }

    // ==== Единая фильтрация для списка и карты ====
    private function applyFilters(Builder $query, Request $request): void
    {
        $toArray = function ($value) {
            if ($value === null || $value === '') return [];
            if (is_array($value)) return array_values(array_filter($value, fn($v) => $v !== '' && $v !== null));
            return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
        };

        // Статусы (мульти)
        if ($request->filled('moderation_status')) {
            $available = ['pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented', 'sold_by_owner', 'denied'];
            $statuses = array_values(array_intersect($toArray($request->input('moderation_status')), $available));
            if (!empty($statuses)) {
                $query->whereIn('moderation_status', $statuses);
            }
        }

        // ---- districts (мультиселект) с похожестью ≥ 0.7 ----
        if ($request->has('districts')) {
            $selected = $toArray($request->input('districts'));
            $selected = array_values(array_filter($selected, fn($v) => $v !== ''));

            if (!empty($selected)) {
                // 1) Грубая выборка кандидатов по LIKE (по первым 3 символам каждого значения)
                $coarse = Property::query()->select(['id', 'district']);

                $coarse->where(function ($q) use ($selected) {
                    foreach ($selected as $d) {
                        $needle = mb_strtolower(trim($d), 'UTF-8');
                        if ($needle === '') continue;
                        $prefix = mb_substr($needle, 0, 3, 'UTF-8'); // берём первые 3 символа
                        if ($prefix !== '') {
                            $q->orWhereRaw('LOWER(district) LIKE ?', ['%'.$prefix.'%']);
                        }
                    }
                });

                // можно сузить по другим фильтрам, если уже заданы (город, тип и т.п.)
                // но просто применим базовые ограничения ролей:
                // (важно: НЕ копируем все applyFilters, чтобы не задвоить; достаточно чернового ограничения)
                // Либо оставьте как есть.

                $candidates = $coarse->limit(5000)->get(); // safety cap

                // 2) Тонкая фильтрация (Jaccard по 3-граммам), порог 0.7
                $THRESHOLD = 0.70;
                $ids = [];

                foreach ($candidates as $row) {
                    $cand = (string)($row->district ?? '');
                    foreach ($selected as $needle) {
                        if ($this->jaccard($cand, (string)$needle, 3) >= $THRESHOLD) {
                            $ids[] = (int)$row->id;
                            break;
                        }
                    }
                }

                // если нет совпадений — заведомо пустой результат
                if (empty($ids)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                // Применяем к основному запросу, чтобы пагинация и сортировки работали как обычно
                $query->whereIn('id', array_values(array_unique($ids)));
            }
        }

        // Текстовые поля (like, поддержка массива термов: OR)
        foreach (['title', 'description', 'district', 'address', 'landmark', 'condition', 'apartment_type', 'owner_phone'] as $field) {
            if ($request->has($field)) {
                $terms = $toArray($request->input($field));
                if (empty($terms)) {
                    $val = $request->input($field);
                    if ($val !== null && $val !== '') {
                        $query->where($field, 'like', '%' . $val . '%');
                    }
                } else {
                    $query->where(function ($q) use ($field, $terms) {
                        foreach ($terms as $t) {
                            $q->orWhere($field, 'like', '%' . $t . '%');
                        }
                    });
                }
            }
        }

        // Точные поля (включая мультиселект через whereIn)
        $exactFields = [
            'type_id', 'status_id', 'location_id', 'repair_type_id',
            'currency', 'offer_type',
            'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
            'agent_id', 'listing_type', 'created_by', 'contract_type_id',
            // при желании можно и lat/lng, но для карты они задаются bbox'ом
        ];
        foreach ($exactFields as $field) {
            if ($request->has($field)) {
                $vals = $toArray($request->input($field));
                if (empty($vals)) {
                    $val = $request->input($field);
                    if ($val !== null && $val !== '') {
                        $query->where($field, $val);
                    }
                } else {
                    $query->whereIn($field, $vals);
                }
            }
        }

        // Алиасы
        $aliases = ['area' => 'total_area'];

        // Диапазоны
        foreach ([
                     'price' => 'price',
                     'rooms' => 'rooms',
                     'total_area' => 'total_area',
                     'living_area' => 'living_area',
                     'floor' => 'floor',
                     'total_floors' => 'total_floors',
                     'year_built' => 'year_built',
                     'area' => $aliases['area'],
                 ] as $param => $column) {
            $from = $request->input($param . 'From');
            $to = $request->input($param . 'To');
            if ($from !== null && $from !== '') $query->where($column, '>=', $from);
            if ($to !== null && $to !== '') $query->where($column, '<=', $to);
        }
    }

    public function store(Request $request)
    {
        $validated = $this->validateProperty($request);

        // --- Дубликаты: пропускаем только если force=1
        $force = (bool)$request->boolean('force', false);

        if (!$force) {
            $dups = $this->findDuplicateCandidates($validated);

            if ($dups->count() > 0) {
                return response()->json([
                    'message' => 'Найдены возможные дубликаты (телефон/адрес/гео/этаж/площадь)',
                    'duplicates' => $dups->take(10)->values(),
                ], 409);
            }
        }

        $validated['created_by'] = auth()->id();
        $validated['moderation_status'] = auth()->user()->hasRole('client') ? 'pending' : 'approved';
        $validated['listing_type'] = $request->input('listing_type', 'regular');

        $property = Property::create($validated);

        $this->storePhotosFromRequest($request, $property);

        return response()->json($property->load('photos'));
    }

    /**
     * Кандидаты-дубликаты:
     *  - Совпадает телефон владельца (owner_phone) И
     *  - Совпадает этаж (floor) И
     *  - Площадь близка (±1 м²) — порог можно вынести в конфиг
     *  - (Опционально) тот же created_by/agent_id/адрес, если нужно ужесточить
     */

    public function update(Request $request, Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $this->validateProperty($request, isUpdate: true);
        $property->update($validated);

        // Optional: allow adding more photos on update
        $this->storePhotosFromRequest($request, $property, append: true);

        // Optional: reorder via `photo_order` = [photoId1, photoId2, ...]
        if ($request->filled('photo_order') && is_array($request->photo_order)) {
            $this->applyOrder($property, $request->photo_order);
        }

        return response()->json($property->load('photos'));
    }

    private function storePhotosFromRequest(Request $request, Property $property, bool $append = false): void
    {
        // Delete selected photos if requested
        if ($request->filled('delete_photo_ids')) {
            foreach ($property->photos()->whereIn('id', $request->delete_photo_ids)->get() as $old) {
                \Storage::disk('public')->delete($old->file_path);
                $old->delete();
            }
        }

        if (!$request->hasFile('photos')) {
            return;
        }

        // Determine base position (append to the end)
        $basePos = $append ? (int)($property->photos()->max('position') ?? -1) + 1 : 0;

        $files = $request->file('photos');
        $positions = $request->input('photo_positions', []); // optional parallel array

        foreach (array_values($files) as $i => $photo) {
            $image = $this->imageManager->read($photo)->scaleDown(1600, null);
            $watermark = $this->imageManager->read(public_path('watermark/logo.png'))
                ->scale((int)round($image->width() * 0.14));
            $image->place($watermark, 'bottom-right', 36, 28);

            $binary = $image->encode(new JpegEncoder(50));
            $filename = 'properties/' . uniqid('', true) . '.jpg';
            \Storage::disk('public')->put($filename, $binary);

            $position = $positions[$i] ?? ($basePos + $i);

            $property->photos()->create([
                'file_path' => $filename,
                'position' => $position,
            ]);
        }

        // Normalize positions to be 0..N-1 with no gaps
        $this->normalizePositions($property);
    }

    private function applyOrder(Property $property, array $orderedIds): void
    {
        foreach ($orderedIds as $pos => $id) {
            $property->photos()->whereKey($id)->update(['position' => $pos]);
        }
        $this->normalizePositions($property);
    }

    private function normalizePositions(Property $property): void
    {
        $photos = $property->photos()->orderBy('position')->orderBy('id')->get();
        foreach ($photos as $idx => $p) {
            if ((int)$p->position !== $idx) {
                $p->update(['position' => $idx]);
            }
        }
    }

    public function show(Property $property)
    {
        $user = auth()->user();

        if (!$user && $property->moderation_status !== 'approved') {
            return response()->json(['message' => 'Объект недоступен'], 403);
        }

        if ($user && $user->hasRole('client') && $property->created_by !== $user->id) {
            return response()->json(['message' => 'Объект недоступен'], 403);
        }

        return response()->json($property->load(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'contractType']));
    }


    public function destroy(Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $property->update(['moderation_status' => 'deleted']);
        return response()->json(['message' => 'Объект помечен как удалён']);
    }

    public function updateModerationAndListingType(Request $request, Property $property)
    {
        $user = auth()->user();

        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('agent'))) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $request->validate([
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented,sold_by_owner,denied',
            'listing_type' => 'sometimes|in:regular,vip,urgent',
        ]);

        $property->update($validated);

        return response()->json([
            'message' => 'Обновлено успешно',
            'data' => $property->only(['id', 'moderation_status', 'listing_type']),
        ]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function validateProperty(Request $request, bool $isUpdate = false)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'created_by' => 'nullable|string',
            'district' => 'nullable|string',
            'address' => 'nullable|string',
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented,sold_by_owner','denied',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'type_id' => 'required|exists:property_types,id',
            'status_id' => 'required|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'price' => 'required|numeric',
            'currency' => 'required|in:TJS,USD',
            'offer_type' => 'required|in:rent,sale',
            'rooms' => 'nullable|integer|min:1|max:10',
            'youtube_link' => 'nullable|url',
            'total_area' => 'nullable|numeric',
            'land_size' => 'sometimes|nullable|integer|min:0|max:65535',
            'living_area' => 'nullable|numeric',
            'floor' => 'nullable|integer',
            'total_floors' => 'nullable|integer',
            'year_built' => 'nullable|integer|min:1900|max:' . date('Y'),
            'condition' => 'nullable|string',
            'apartment_type' => 'nullable|string',
            'has_garden' => 'sometimes|boolean',
            'has_parking' => 'sometimes|boolean',
            'is_mortgage_available' => 'sometimes|boolean',
            'is_from_developer' => 'sometimes|boolean',
            'landmark' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'agent_id' => 'nullable|exists:users,id',
            'owner_phone' => 'nullable|string|max:30',
            'listing_type' => 'sometimes|in:regular,vip,urgent',
            'owner_name' => 'nullable|string|max:255',
            'object_key' => 'nullable|string|max:255',

            // Photos (optional on update)
            'photos' => [$isUpdate ? 'sometimes' : 'nullable', 'array', 'max:40'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['integer', 'min:0'],

            // Reorder existing
            'photo_order' => ['sometimes', 'array'],
            'photo_order.*' => ['integer', 'exists:property_photos,id'],

            // Delete list
            'delete_photo_ids' => ['sometimes', 'array'],
            'delete_photo_ids.*' => ['integer', 'exists:property_photos,id'],
        ]);
        return $validated;
    }

    public function applySorts(Builder $query, ?string $sort = 'listing_type', ?string $dir = 'desc'): void
    {
        if ($sort === 'none') {
            return; // не сортировать вообще
        }

        if ($sort === 'listing_type') {
            $orderExpr = "CASE listing_type
            WHEN 'urgent' THEN 1
            WHEN 'vip' THEN 2
            WHEN 'regular' THEN 3
            ELSE 4 END";
            $query->orderByRaw($orderExpr);
        } else {
            $query->orderBy($sort ?? 'created_at', $dir ?? 'desc');
        }
    }

    public function trackView(Request $request, Property $property)
    {
        // Ключ "видел" = по объекту + IP + UA + текущая дата
        $fingerprint = sha1(
            ($request->ip() ?? '0.0.0.0') . '|' .
            (string)$request->userAgent() . '|' .
            now()->format('Y-m-d')
        );
        $cacheKey = "prop:{$property->id}:viewed:{$fingerprint}";

        // Инкрементим только если ещё не считали сегодня
        if (!Cache::has($cacheKey)) {
            $property->increment('views_count'); // атомарно
            Cache::put($cacheKey, 1, now()->addDay());
        }

        return response()->noContent();
    }

    // === НОРМАЛИЗАЦИЯ ===
    private function normalizePhone(?string $raw): string
    {
        if (!$raw) return '';
        // только цифры; для Таджикистана можно нормализовать префикс 992 при необходимости
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '992') === false && strlen($digits) === 9) {
            // пример: локальный -> добавим код страны (подстрой под свои правила)
            $digits = '992' . $digits;
        }
        return $digits;
    }

    private function normalizeAddress(?string $raw): string
    {
        if (!$raw) return '';
        $s = mb_strtolower($raw, 'UTF-8');
        $s = strtr($s, ['ё' => 'е']);                    // русские варианты
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s); // убрать знаки препинания
        $s = preg_replace('/\s+/u', ' ', trim($s));       // схлопнуть пробелы
        return $s;
    }

    /**
     * Быстрая грубая проверка: разложим адрес на токены (>=3 символов) и
     * потребуем совпадение хотя бы 2 токенов через LIKE в SQL (кора фильтр).
     */
    private function applyAddressCoarseFilter(\Illuminate\Database\Eloquent\Builder $q, string $addressNorm): void
    {
        if ($addressNorm === '') return;

        $tokens = array_values(array_filter(explode(' ', $addressNorm), fn($t) => mb_strlen($t, 'UTF-8') >= 3));
        if (count($tokens) === 0) return;

        // ограничимся первыми 3-4 токенами, чтобы не раздувать запрос
        $tokens = array_slice($tokens, 0, 4);

        // Нужны совпадения хотя бы двух токенов
        $q->where(function ($qq) use ($tokens) {
            foreach ($tokens as $i => $t) {
                $qq->orWhere('address', 'like', '%' . $t . '%');
            }
        });
    }

    /** Похожесть адресов для тонкой сортировки (уже в PHP) */
    private function addressSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        similar_text($a, $b, $pct); // 0..100
        return (float)$pct;
    }

    /** Быстрая проверка близости гео — ~150 м (можно подстроить) */
    private function withinGeoBox(float $lat, float $lng, float $candLat, float $candLng): bool
    {
        $dLat = 0.0015; // ~ 167 м
        $dLng = 0.0015 * max(0.2, cos(deg2rad(max(1e-6, $lat))));
        return abs($lat - $candLat) <= $dLat && abs($lng - $candLng) <= $dLng;
    }

    /**
     * Кандидаты-дубликаты.
     * Триггеры под подозрение:
     *  - Совпадает нормализованный телефон владельца И (плюс любая из: этаж/площадь/адрес/гео)
     *  - ИЛИ без телефона, но высокая похожесть по адресу + близкие координаты + (этаж/площадь)
     *
     * Возвращаем список с "score", отсортированный по вероятности (100..0).
     */
    private function findDuplicateCandidates(array $data)
    {
        $phoneNorm   = $this->normalizePhone($data['owner_phone'] ?? null);
        $addrNormNew = $this->normalizeAddress($data['address'] ?? null);

        $floor   = isset($data['floor']) ? (int)$data['floor'] : null;
        $area    = isset($data['total_area']) ? (float)$data['total_area'] : null;
        $latNew  = isset($data['latitude']) ? (float)$data['latitude'] : null;
        $lngNew  = isset($data['longitude']) ? (float)$data['longitude'] : null;

        $q = Property::query()
            ->select([
                'id','title','address','owner_name','owner_phone',
                'total_area','floor','price','currency','created_at','moderation_status',
                'latitude','longitude'
            ]);

        // --- Грубая SQL-фаза: сильно сузим кандидатов ---
        $q->where(function ($qq) use ($phoneNorm, $addrNormNew, $floor, $area, $latNew, $lngNew) {
            // 1) По телефону — нормализация на SQL стороне через REPLACE (MySQL 8: REGEXP_REPLACE, но сделаем совместимо)
            if ($phoneNorm !== '') {
                $qq->orWhere(function ($qPhone) use ($phoneNorm, $floor, $area) {
                    // убираем нецифры: +, -, пробелы, скобки
                    $normalizedSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(owner_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')";
                    $qPhone->whereRaw("$normalizedSql LIKE ?", ["%{$phoneNorm}%"]);
                    if ($floor !== null) $qPhone->where('floor', $floor);
                    if ($area !== null)  $qPhone->whereBetween('total_area', [$area - 1.0, $area + 1.0]);
                });
            }

            // 2) По адресу (хотя бы 2 токена LIKE) + доп. признаки
            if ($addrNormNew !== '') {
                $qq->orWhere(function ($qAddr) use ($addrNormNew, $floor, $area) {
                    $this->applyAddressCoarseFilter($qAddr, $addrNormNew);
                    if ($floor !== null) $qAddr->where('floor', $floor);
                    if ($area !== null)  $qAddr->whereBetween('total_area', [$area - 2.0, $area + 2.0]);
                });
            }

            // 3) По гео (узкая коробка) + этаж/площадь
            if ($latNew !== null && $lngNew !== null) {
                $dLat = 0.0015;
                $dLng = 0.0015 * max(0.2, cos(deg2rad(max(1e-6, $latNew))));
                $qq->orWhere(function ($qGeo) use ($latNew, $lngNew, $dLat, $dLng, $floor, $area) {
                    $qGeo->whereBetween('latitude',  [$latNew - $dLat, $latNew + $dLat])
                        ->whereBetween('longitude', [$lngNew - $dLng, $lngNew + $dLng]);
                    if ($floor !== null) $qGeo->where('floor', $floor);
                    if ($area !== null)  $qGeo->whereBetween('total_area', [$area - 2.0, $area + 2.0]);
                });
            }
        });

        // Не раздуваем ответ
        $candidates = $q->orderByDesc('created_at')->limit(100)->get();

        // --- Тонкая PHP-фаза: считаем score и фильтруем слабые совпадения ---
        $result = [];
        foreach ($candidates as $p) {
            $pPhoneNorm = $this->normalizePhone($p->owner_phone);
            $pAddrNorm  = $this->normalizeAddress($p->address);

            $phoneMatch   = ($phoneNorm !== '' && $pPhoneNorm !== '' && $pPhoneNorm === $phoneNorm);
            $floorMatch   = ($floor !== null && $p->floor !== null && (int)$p->floor === $floor);
            $areaDelta    = ($area !== null && $p->total_area !== null) ? abs((float)$p->total_area - $area) : null;
            $areaMatch    = ($areaDelta !== null && $areaDelta <= 1.5);
            $addrScore    = $this->addressSimilarity($addrNormNew, $pAddrNorm); // 0..100
            $geoNear      = ($latNew !== null && $lngNew !== null && $p->latitude !== null && $p->longitude !== null)
                ? $this->withinGeoBox($latNew, $lngNew, (float)$p->latitude, (float)$p->longitude) : false;

            // Композитный скор:
            // телефон — самый сильный сигнал; затем адрес; затем гео; бонусы за этаж/площадь
            $score = 0.0;
            if ($phoneMatch) $score += 55;
            $score += min(35.0, $addrScore * 0.35);     // макс +35
            if ($geoNear)    $score += 20;              // +20
            if ($floorMatch) $score += 8;               // +8
            if ($areaMatch)  $score += 8;               // +8
            $score = min(100.0, $score);

            // Порог: либо телефон совпал, либо общий скор >= 50
            if ($phoneMatch || $score >= 50.0) {
                $result[] = [
                    'id' => (int)$p->id,
                    'title' => $p->title,
                    'address' => $p->address,
                    'owner_name' => $p->owner_name,
                    'owner_phone' => $p->owner_phone,
                    'total_area' => $p->total_area,
                    'floor' => $p->floor,
                    'price' => $p->price,
                    'currency' => $p->currency,
                    'moderation_status' => $p->moderation_status,
                    'created_at' => $p->created_at,
                    'score' => round($score, 1),
                    'links' => [
                        'view' => url("https://aura.tj/apartment/{$p->id}"),
                    ],
                    'signals' => [
                        'phone_match' => $phoneMatch,
                        'address_similarity' => round($addrScore, 1),
                        'geo_near' => $geoNear,
                        'floor_match' => $floorMatch,
                        'area_delta' => $areaDelta,
                    ],
                ];
            }
        }

        // Отсортируем по score
        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);

        // Вернём коллекцию
        return collect($result);
    }

    /** Подготовка строки: нижний регистр, схлопнуть пробелы */
    private function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return $s ?? '';
    }

    /** 3-граммы для мультибайта */
    private function ngrams(string $s, int $n = 3): array
    {
        $len = mb_strlen($s, 'UTF-8');
        if ($len === 0) return [];
        if ($len < $n) return [$s]; // короткие строки целиком

        $grams = [];
        for ($i = 0; $i <= $len - $n; $i++) {
            $grams[] = mb_substr($s, $i, $n, 'UTF-8');
        }
        return $grams;
    }

    /** Jaccard-похожесть по n-граммам (0..1) */
    private function jaccard(string $a, string $b, int $n = 3): float
    {
        $a = $this->norm($a);
        $b = $this->norm($b);
        if ($a === '' || $b === '') return 0.0;

        $A = array_unique($this->ngrams($a, $n));
        $B = array_unique($this->ngrams($b, $n));

        if (empty($A) && empty($B)) return 1.0;
        if (empty($A) || empty($B)) return 0.0;

        $Ai = array_fill_keys($A, true);
        $inter = 0;
        foreach ($B as $g) if (isset($Ai[$g])) $inter++;

        $union = count($A) + count($B) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }
}
