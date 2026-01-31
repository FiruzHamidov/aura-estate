<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePropertyDealRequest;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function myProperties(Request $request)
    {
        $query = $this->baseQueryMyProperties($request);
        $this->applyFilters($query, $request);
        $this->applySorts($query, $request->input('sort'), $request->input('dir'));
        $perPage = (int)$request->input('per_page', 20);
        return response()->json($query->latest()->paginate($perPage));
    }

    private function baseQueryMyProperties(Request $request): Builder
    {
        $user = auth()->user();
        $query = Property::query()->with(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'heating', 'parking', 'saleAgents']);

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

    // ==== Общая база для index/map: роли, связи, базовые статусы ====
    private function baseQuery(Request $request): Builder
    {
        $user = auth()->user();
        $query = Property::query()->with(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'heating', 'parking']);

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
            $available = ['pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented', 'sold_by_owner', 'denied', 'deposit'];
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
            'is_business_owner', 'is_full_apartment', 'is_for_aura', 'developer_id',
            'heating_type_id', 'parking_type_id'
            // при желании можно и lat/lng, но для карты они задаются bbox'ом
        ];
        foreach ($exactFields as $field) {
            if ($request->has($field)) {
                // normalize boolean-like params: support true/false (bool), 'true'/'false' (strings), and '1'/'0'
                $booleanFields = [
                    'has_garden','has_parking','is_mortgage_available','is_from_developer',
                    'is_business_owner','is_full_apartment','is_for_aura'
                ];

                if (in_array($field, $booleanFields, true)) {
                    $raw = $request->input($field);
                    if ($raw === null || $raw === '') {
                        continue; // nothing to apply
                    }

                    $vals = [];
                    if (is_array($raw)) {
                        foreach ($raw as $v) {
                            if ($v === true || $v === 'true' || $v === '1' || $v === 1) $vals[] = '1';
                            elseif ($v === false || $v === 'false' || $v === '0' || $v === 0) $vals[] = '0';
                        }
                    } else {
                        $v = $raw;
                        if ($v === true || $v === 'true' || $v === '1' || $v === 1) {
                            $vals = ['1'];
                        } elseif ($v === false || $v === 'false' || $v === '0' || $v === 0) {
                            $vals = ['0'];
                        } else {
                            $vals = [$v];
                        }
                    }

                    $vals = array_values(array_unique(array_filter($vals, fn($x) => $x !== '')));
                    if (!empty($vals)) {
                        $query->whereIn($field, $vals);
                    }

                    continue;
                }

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

        // Диапазон по датам (date_from, date_to) — фильтрация по created_at.
        // Формат ожидается YYYY-MM-DD или любой распознаваемый Carbon формát.
        if ($request->has('date_from') || $request->has('date_to')) {
            $from = $request->input('date_from');
            $to = $request->input('date_to');

            try {
                if (!empty($from)) {
                    $query->whereDate('created_at', '>=', \Carbon\Carbon::parse($from)->toDateString());
                }
            } catch (\Exception $e) {
                // При желании логировать ошибку или игнорировать неверный формат
            }

            try {
                if (!empty($to)) {
                    $query->whereDate('created_at', '<=', \Carbon\Carbon::parse($to)->toDateString());
                }
            } catch (\Exception $e) {
                // При желании логировать ошибку или игнорировать неверный формат
            }
        }

        // Диапазон по датам продажи (sold_at_from, sold_at_to) — фильтрация по sold_at
// Применяется только к закрытым статусам
        if ($request->has('sold_at_from') || $request->has('sold_at_to')) {
            $soldFrom = $request->input('sold_at_from');
            $soldTo   = $request->input('sold_at_to');

            // sold_at имеет смысл только для закрытых объявлений
//            $query->whereIn('moderation_status', ['sold', 'rented', 'sold_by_owner']);

            try {
                if (!empty($soldFrom)) {
                    $query->whereDate('sold_at', '>=', \Carbon\Carbon::parse($soldFrom)->toDateString());
                }
            } catch (\Exception $e) {
                // можно логировать при необходимости
            }

            try {
                if (!empty($soldTo)) {
                    $query->whereDate('sold_at', '<=', \Carbon\Carbon::parse($soldTo)->toDateString());
                }
            } catch (\Exception $e) {
                // можно логировать при необходимости
            }
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
//        $validated['moderation_status'] = auth()->user()->hasRole('client') ? 'pending' : 'approved';
        $validated['listing_type'] = $request->input('listing_type', 'regular');

        if ($force) {
            $dups = $this->findDuplicateCandidates($validated);

            $dupCount = $dups->count();
            if ($dupCount > 0) {
                $items = $dups->take(10)->map(function ($d) {
                    $rooms = $d['rooms'] ?? null;
                    $title = isset($d['title']) && $d['title'] !== ''
                        ? $d['title']
                        : ($rooms ? "{$rooms} ком" : 'Объект');
                    $score = isset($d['score']) ? (string)$d['score'] : 'n/a';
                    $id = $d['id'] ?? '';
                    $url = 'https://aura.tj/apartment/' . $id;

                    $titleEsc = e($title);
                    $urlEsc = e($url);

                    return [
                        'text' => "[ID {$id}] {$title} (Совпадения: {$score}%)",
                        'html' => "<a href=\"{$urlEsc}\" target=\"_blank\" rel=\"noopener noreferrer\">[ID {$id}] {$titleEsc}</a> (score: {$score})"
                    ];
                })->toArray();

                $textItems = array_map(fn($x) => $x['text'], $items);
                $htmlItems = array_map(fn($x) => $x['html'], $items);

                $listText = implode('; ', $textItems);
                $listHtml = '<ul><li>' . implode('</li><li>', $htmlItems) . '</li></ul>';

                if ($dupCount > 10) {
                    $listText .= "; ... и ещё " . ($dupCount - 10) . " штук.";
                    $listHtml .= "<p>... и ещё " . ($dupCount - 10) . " штук.</p>";
                }

                $validated['moderation_status'] = 'pending';
                // сохраняем HTML в поле rejection_comment — фронтенд будет рендерить как HTML
                $validated['rejection_comment'] = "<p>Причина автоматически: Найдены возможные дубликаты ({$dupCount}):</p>" . $listHtml;
                // можно дополнительно сохранить plain-text, если есть поле
                // $validated['rejection_comment_text'] = "Найдены возможные дубликаты ({$dupCount}): " . $listText;
            }
        }

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

        // Если статус закрытый и sold_at ещё не установлен — ставим текущую дату
        if (
            $request->filled('moderation_status') &&
            in_array($request->moderation_status, ['sold', 'sold_by_owner', 'rented'], true) &&
            empty($property->sold_at)
        ) {
            $property->update([
                'sold_at' => now(),
            ]);
        }

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
//        $user = auth()->user();

        return response()->json($property->load(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'contractType','developer', 'heating', 'parking', 'buildingType']));
    }

    public function destroy(Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $property->update(['moderation_status' => 'deleted']);
        return response()->json(['message' => 'Объект помечен как удалён']);
    }

//    public function updateModerationAndListingType(Request $request, Property $property)
//    {
//        $user = auth()->user();
//
//        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('agent'))) {
//            return response()->json(['message' => 'Доступ запрещён'], 403);
//        }
//
//        $validated = $request->validate([
//            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented,sold_by_owner,denied',
//            'listing_type' => 'sometimes|in:regular,vip,urgent',
//            'status_comment' => 'nullable|string',
//        ]);
//
//        if (
//            isset($validated['moderation_status']) &&
//            in_array($validated['moderation_status'], ['sold', 'rented', 'sold_by_owner'], true)
//        ) {
//            $validated['sold_at'] = now();
//        }
//
//        $property->update($validated);
//
//        return response()->json([
//            'message' => 'Обновлено успешно',
//            'data' => $property->only(['id', 'moderation_status', 'listing_type']),
//        ]);
//    }

    public function updateModerationAndListingType(
        SavePropertyDealRequest $request,
        Property $property
    ) {
        $user = auth()->user();

        if (
            !$user ||
            !(
                $user->hasRole('admin') ||
                $user->hasRole('superadmin') ||
                $user->hasRole('agent')
            )
        ) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        DB::transaction(function () use ($request, $property) {

            /**
             * 1️⃣ ОБНОВЛЯЕМ ВСЁ, ЧТО ПРИШЛО
             * независимо от статуса
             */
            $fillable = [
                // moderation
                'moderation_status',
                'listing_type',
                'status_comment',

                // buyer / deposit
                'buyer_full_name',
                'buyer_phone',
                'deposit_amount',
                'deposit_currency',
                'deposit_received_at',
                'deposit_taken_at',

                // money
                'money_holder',
                'money_received_at',
                'contract_signed_at',

                // company
                'company_expected_income',
                'company_expected_income_currency',
                'company_commission_amount',
                'company_commission_currency',

                // deal
                'actual_sale_price',
                'actual_sale_currency',
                'planned_contract_signed_at',
            ];

            $property->update(
                collect($fillable)
                    ->filter(fn ($field) => $request->has($field))
                    ->mapWithKeys(fn ($field) => [$field => $request->$field])
                    ->toArray()
            );

            /**
             * 2️⃣ ЛОГИКА ПО СТАТУСУ (ТОЛЬКО БИЗНЕС-ПРАВИЛА)
             */
            if (in_array($request->moderation_status, ['sold', 'sold_by_owner', 'rented'], true)) {
                $property->update([
                    'sold_at' => now(),
                ]);
            }

            /**
             * 3️⃣ АГЕНТЫ — ТОЛЬКО ЕСЛИ SOLD
             */
            if ($request->moderation_status === 'sold' && $request->filled('agents')) {
                $property->saleAgents()->sync(
                    collect($request->agents)->mapWithKeys(fn ($agent) => [
                        $agent['agent_id'] => [
                            'role' => $agent['role'] ?? 'assistant',
                            'agent_commission_amount' => $agent['commission_amount'] ?? null,
                            'agent_commission_currency' => $agent['commission_currency'] ?? 'TJS',
                            'agent_paid_at' => $agent['paid_at'] ?? null,
                        ],
                    ])->toArray()
                );
            }
        });

        return response()->json([
            'message' => 'Объявление успешно обновлено',
            'data' => $property->fresh(['saleAgents']),
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
            // --- Moderation status with deposit and fixed enum
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,deposit,sold,rented,sold_by_owner,denied',
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
            'rejection_comment' => 'nullable|string',

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

            'developer_id' => 'nullable|exists:developers,id',
            'heating_type_id' => 'nullable|exists:heating_types,id',
            'parking_type_id' => 'nullable|exists:parking_types,id',
            'is_business_owner' => 'sometimes|boolean',
            'is_full_apartment' => 'sometimes|boolean',
            'is_for_aura' => 'sometimes|boolean',
            'status_comment' => 'nullable|string',
            'sold_at' => 'nullable|date',

            // ===== Deposit stage (optional) =====
            'buyer_full_name' => 'nullable|string|max:255',
            'buyer_phone' => 'nullable|string|max:30',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_currency' => 'nullable|in:TJS,USD',
            'deposit_received_at' => 'nullable|date',
            'deposit_taken_at' => 'nullable|date',
            'planned_contract_signed_at' => 'nullable|date',
            'company_expected_income' => 'nullable|numeric|min:0',
            'company_expected_income_currency' => 'nullable|in:TJS,USD',

            // ===== Final deal stage (optional) =====
            'actual_sale_price' => 'nullable|numeric|min:0',
            'actual_sale_currency' => 'nullable|in:TJS,USD',
            'company_commission_amount' => 'nullable|numeric|min:0',
            'company_commission_currency' => 'nullable|in:TJS,USD',
            'money_holder' => 'nullable|in:company,agent,owner,developer,client',
            'money_received_at' => 'nullable|date',
            'contract_signed_at' => 'nullable|date',
        ]);
        return $validated;
    }

    public function applySorts(Builder $query, ?string $sort = 'listing_type', ?string $dir = 'desc'): void
    {
        // Если явно указано 'none' — не применяем сортировку
        if ($sort === 'none') {
            return;
        }

        // Нормализуем направление
        $dir = strtolower($dir ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Специальный порядок для listing_type (urgent -> vip -> regular -> others)
        if ($sort === 'listing_type') {
            $orderExpr = "CASE listing_type
            WHEN 'urgent' THEN 1
            WHEN 'vip' THEN 2
            WHEN 'regular' THEN 3
            ELSE 4 END";
            // Сначала по listing_type согласно CASE, затем по дате (чтобы детерминировать порядок)
            $query->orderByRaw($orderExpr)->orderBy('created_at', $dir);
            return;
        }

        // Разрешённые поля сортировки — whitelist для защиты от произвольных колонок
        $allowed = [
            'created_at' => 'created_at', // можно также принимать alias 'date'
            'date' => 'created_at',
            'price' => 'price',
            'total_area' => 'total_area',
            'area' => 'total_area',
            'rooms' => 'rooms',
            'views_count' => 'views_count',
            'id' => 'id',
        ];

        // Если передали что-то вроде 'price' или 'total_area' — применим
        if (isset($allowed[$sort])) {
            $col = $allowed[$sort];
            $query->orderBy($col, $dir);
            return;
        }

        // По умолчанию — сортируем по созданию (дата)
        $query->orderBy('created_at', $dir);
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

    public function similar(Property $property, Request $request)
    {
        $limit = (int) $request->input('limit', 6);
        $priceTolerance = (float) $request->input('price_tolerance', 0.2); // 20%
        $radiusKm = (float) $request->input('radius_km', 5); // 5 km by default
        $useRadius = $request->boolean('use_radius', true);

        $query = Property::query();

        // всегда исключаем текущий объект
        $query->where('id', '!=', $property->id);

        // совпадающий тип — даёт приоритет
        if ($property->type_id) {
            $query->where('type_id', $property->type_id);
        }

        // совпадающая локация (город / район) — если есть
        if ($property->location_id) {
            $query->where('location_id', $property->location_id);
        } elseif (!empty($property->district)) {
            $query->where('district', $property->district);
        }

        if ($property->developer_id) {
            $query->where('developer_id', $property->developer_id);
        }

        // совпадающий тип предложения (продажа/аренда)
        if (!empty($property->offer_type)) {
            $query->where('offer_type', $property->offer_type);
        }

        // комнаты — если указаны
        if (!empty($property->rooms)) {
            // ищем либо ровно такое значение, либо +-1 комнату
            $query->whereBetween('rooms', [max(0, $property->rooms - 1), $property->rooms + 1]);
        }

        // ценовой диапазон
        if (!empty($property->price) && is_numeric($property->price)) {
            $minPrice = $property->price * (1 - $priceTolerance);
            $maxPrice = $property->price * (1 + $priceTolerance);
            $query->whereBetween('price', [$minPrice, $maxPrice]);
        }

        // поиск по радиусу — если есть координаты и включена опция
        if ($useRadius && $property->latitude && $property->longitude) {
            $lat = (float)$property->latitude;
            $lng = (float)$property->longitude;
            // Хаверсин: расстояние в км
            $haversine = "(6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?))
            + sin(radians(?)) * sin(radians(latitude))
        ))";

            // присоединяем расстояние как поле и фильтруем по radius
            // используем selectRaw чтобы включить всё необходимое
            $query->select(['properties.*'])
                ->selectRaw("$haversine AS distance", [$lat, $lng, $lat])
                ->whereRaw("$haversine <= ?", [$lat, $lng, $lat, $radiusKm])
                ->orderBy('distance', 'asc');
        } else {
            // если расстояние не используется - сортируем по дате и релевантности
            $query->orderBy('created_at', 'desc');
        }

        // Добавим дополнительные нестрогие критерии (например, same repair type) как опция
        if ($property->repair_type_id) {
            $query->orWhere('repair_type_id', $property->repair_type_id);
        }

        // eager load
        $result = $query->with(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'contractType'])
            ->limit($limit)
            ->get();

        return response()->json($result);
    }

    /**
     * Return audit logs for a property (paginated).
     * GET /api/properties/{property}/logs
     */
    public function logs(Request $request, Property $property)
    {
        $perPage = (int) $request->input('per_page', 50);

        $logs = $property->logs()->with('user')->paginate($perPage);

        return response()->json($logs);
    }

    public function saveDeal(
        SavePropertyDealRequest $request,
        Property                $property
    ) {
        DB::transaction(function () use ($request, $property) {

            // 1️⃣ Обновляем сам объект
            $property->update([
                'actual_sale_price' => $request->actual_sale_price,
                'actual_sale_currency' => $request->actual_sale_currency ?? 'TJS',

                'company_commission_amount' => $request->company_commission_amount,
                'company_commission_currency' => $request->company_commission_currency ?? 'TJS',

                'money_holder' => $request->money_holder,
                'money_received_at' => $request->money_received_at,
                'contract_signed_at' => $request->contract_signed_at,

                'deposit_amount' => $request->deposit_amount,
                'deposit_currency' => $request->deposit_currency ?? 'TJS',
                'deposit_received_at' => $request->deposit_received_at,
                'deposit_taken_at' => $request->deposit_taken_at,

                // если сделка оформлена — считаем проданным
                'moderation_status' => 'sold',
                'sold_at' => now(),
            ]);

            // 2️⃣ Агенты — очищаем и записываем заново
            $property->saleAgents()->detach();

            foreach ($request->agents as $agent) {
                $property->saleAgents()->attach($agent['agent_id'], [
                    'role' => $agent['role'] ?? 'assistant',
                    'agent_commission_amount' => $agent['commission_amount'] ?? null,
                    'agent_commission_currency' => $agent['commission_currency'] ?? 'TJS',
                    'agent_paid_at' => $agent['paid_at'] ?? null,
                ]);
            }
        });

        return response()->json([
            'message' => 'Сделка успешно сохранена',
            'property_id' => $property->id,
        ]);
    }
}
