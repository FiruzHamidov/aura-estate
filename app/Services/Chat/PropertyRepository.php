<?php

namespace App\Services\Chat;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PropertyRepository
{
    public function search(array $args): array
    {
        $args = (new PropertySearchFilters)->normalize($args);
        // ---------- Нормализация ----------
        $limit = max(1, min(10, (int) ($args['limit'] ?? 6)));
        $liquiditySearch = ($args['sort_by'] ?? null) === 'liquidity';
        $areaSearch = ($args['sort_by'] ?? null) === 'area_asc';
        $repairCondition = $args['repair_condition'] ?? 'any';
        $renovationPermission = $args['renovation_permission_status'] ?? 'any';
        if (! in_array($repairCondition, ['shell', 'renovated', 'any'], true)
            || ! in_array($renovationPermission, ['allowed', 'not_allowed', 'any'], true)) {
            return [];
        }
        $strictSearch = $liquiditySearch || $areaSearch || $repairCondition !== 'any'
            || $renovationPermission !== 'any' || isset($args['areaFrom']) || isset($args['areaTo']);
        $rooms = isset($args['rooms']) ? (int) $args['rooms'] : null;
        $roomsMin = isset($args['rooms_min']) ? (int) $args['rooms_min'] : null;
        $roomsMax = isset($args['rooms_max']) ? (int) $args['rooms_max'] : null;
        $offerType = ($args['offer_type'] ?? null) === 'rent' ? 'rent' : (($args['offer_type'] ?? null) === 'sale' ? 'sale' : null);
        $currency = in_array(($args['currency'] ?? null), ['TJS', 'USD'], true)
            ? $args['currency']
            : 'TJS';

        $cityRaw = $args['city'] ?? '';
        $city = $this->normalizeCity($cityRaw);

        $typeInput = $args['property_type'] ?? $args['type_id'] ?? null;
        $typeIds = $this->resolveTypeIds($typeInput);

        // Strict selections must never substitute an unrelated property type.
        if ($strictSearch && $typeInput && $typeIds === []) {
            return [];
        }

        $hasDiscountPrice = Schema::hasColumn('properties', 'discount_price');
        $hasEffectivePrice = Schema::hasColumn('properties', 'effective_price');
        $effectivePriceSql = $hasEffectivePrice
            ? 'properties.effective_price'
            : ($hasDiscountPrice
                ? 'CAST(COALESCE(NULLIF(properties.discount_price, 0), properties.price) AS DECIMAL(18, 2))'
                : 'properties.price');

        // ---------- База запроса ----------
        $base = Property::query()->publicSearchable()->toBase()
            ->select([
                'properties.id',
                'properties.title',
                'properties.offer_type',
                'properties.type_id',
                'properties.rooms',
                'properties.price',
                'properties.currency',
                'properties.address',
                'properties.district',
                'properties.created_at',
            ])
            ->addSelect(DB::raw("{$effectivePriceSql} as effective_price"));

        $hasArea = Schema::hasColumn('properties', 'total_area');
        if ($hasArea) {
            $base->addSelect('properties.total_area');
        }
        if ($areaSearch || isset($args['areaFrom']) || isset($args['areaTo'])) {
            if (! $hasArea) {
                return [];
            }
            $base->where('properties.total_area', '>', 0);
            foreach (['areaFrom' => '>=', 'areaTo' => '<='] as $key => $operator) {
                if (isset($args[$key])) {
                    if (! is_numeric($args[$key]) || $args[$key] < 0) {
                        return [];
                    }
                    $base->where('properties.total_area', $operator, (float) $args[$key]);
                }
            }
        }

        $repairTypes = Schema::hasTable('repair_types') && Schema::hasColumn('properties', 'repair_type_id')
            ? DB::table('repair_types')->pluck('name', 'id')->all()
            : [];
        $repairConditions = array_map(fn ($name) => $this->classifyRepairType($name), $repairTypes);
        if ($repairTypes !== []) {
            $base->addSelect('properties.repair_type_id');
        }
        if ($repairCondition !== 'any') {
            $repairIds = array_keys(array_filter($repairConditions, fn ($condition) => $condition === $repairCondition));
            if ($repairIds === []) {
                return [];
            }
            $base->whereIn('properties.repair_type_id', $repairIds);
        }

        $hasPermission = Schema::hasColumn('properties', 'renovation_permission_status');
        if ($hasPermission) {
            $base->addSelect('properties.renovation_permission_status');
        }
        if ($renovationPermission !== 'any') {
            if (! $hasPermission) {
                return [];
            }
            $base->where('properties.renovation_permission_status', $renovationPermission);
        }

        if ($liquiditySearch) {
            if (! Schema::hasColumns('properties', [
                'liquidity_score', 'liquidity_confidence', 'price_position',
                'liquidity_calculated_at', 'liquidity_model_version',
            ])) {
                return [];
            }

            $base->where('properties.offer_type', 'sale')
                ->where('properties.liquidity_score', '>=', (int) config('property-liquidity.liquid_score_threshold', 65))
                ->where('properties.liquidity_confidence', '>=', (int) config('property-liquidity.public_badge_minimum_confidence', 45))
                ->whereIn('properties.price_position', ['below_market', 'at_market'])
                ->where('properties.liquidity_model_version', config('property-liquidity.model_version'))
                ->whereBetween('properties.liquidity_calculated_at', [
                    now()->subHours(max(1, (int) config('property-liquidity.chat_max_age_hours', 48))), now(),
                ])
                ->addSelect('properties.liquidity_calculated_at');
        }

        if (Schema::hasTable('property_promotions')) {
            $base->addSelect(DB::raw("COALESCE((
                SELECT pp.type FROM property_promotions pp
                WHERE pp.property_id = properties.id
                  AND pp.status = 'active'
                  AND pp.starts_at <= CURRENT_TIMESTAMP
                  AND pp.ends_at > CURRENT_TIMESTAMP
                ORDER BY pp.starts_at DESC, pp.id DESC LIMIT 1
            ), 'regular') as listing_type"));
        } else {
            $base->addSelect('properties.listing_type');
        }

        if ($hasDiscountPrice) {
            $base->addSelect('properties.discount_price');
        }

        $hasTypes = Schema::hasTable('property_types');
        if ($hasTypes) {
            $base->leftJoin('property_types', 'properties.type_id', '=', 'property_types.id')
                ->addSelect([
                    DB::raw('COALESCE(property_types.name, NULL) as type_name'),
                    DB::raw('COALESCE(property_types.slug, NULL) as type_slug'),
                ]);
        }

        $cityColumn = Schema::hasColumn('locations', 'city') ? 'locations.city'
            : (Schema::hasColumn('locations', 'name') ? 'locations.name' : null);
        if ($cityColumn) {
            $base->leftJoin('locations', 'properties.location_id', '=', 'locations.id')
                ->addSelect(DB::raw("{$cityColumn} as city"));
        }

        // единый билдер с функцией накатывания фильтров
        $applyFilters = function ($q, $opts) use ($cityColumn, $offerType, $typeIds, $rooms, $roomsMin, $roomsMax, $city, $currency, $effectivePriceSql, $liquiditySearch, $areaSearch) {
            if (! empty($opts['city']) && $city) {
                // важно обернуть в одну группу, чтобы orWhere не ломал остальные условия
                $q->where(function ($w) use ($cityColumn, $city) {
                    if ($cityColumn) {
                        $w->where($cityColumn, 'like', '%'.$city.'%');
                    } else {
                        $w->where(function ($qq) use ($city) {
                            $qq->whereNotNull('properties.district')
                                ->where('properties.district', 'like', '%'.$city.'%');
                        })->orWhere(function ($qq) use ($city) {
                            $qq->whereNotNull('properties.address')
                                ->where('properties.address', 'like', '%'.$city.'%');
                        });
                    }
                });
            }

            if (! empty($opts['offer']) && $offerType) {
                $q->where('properties.offer_type', $offerType);
            }

            if (! empty($opts['type']) && $typeIds !== []) {
                $q->whereIn('properties.type_id', $typeIds);
            }

            if (! empty($opts['rooms']) && $rooms !== null) {
                $q->where('properties.rooms', $rooms);
            }
            if (! empty($opts['rooms']) && $roomsMin !== null) {
                $q->where('properties.rooms', '>=', $roomsMin);
            }
            if (! empty($opts['rooms']) && $roomsMax !== null) {
                $q->where('properties.rooms', '<=', $roomsMax);
            }

            if (! empty($opts['districts']) && is_array($opts['districts'])) {
                $q->whereIn('properties.district', $opts['districts']);
            }

            $priceMin = isset($opts['price_min']) ? $opts['price_min'] : null;
            $priceMax = isset($opts['price_max']) ? $opts['price_max'] : null;

            if (($priceMin !== null && $priceMin > 0) || ($priceMax !== null && $priceMax > 0)) {
                $q->where('properties.currency', $currency);
            }

            if ($priceMin !== null && $priceMin > 0) {
                $q->whereRaw("{$effectivePriceSql} >= ?", [$priceMin]);
            }
            if ($priceMax !== null && $priceMax > 0) {
                $q->whereRaw("{$effectivePriceSql} <= ?", [$priceMax]);
            }

            if ($liquiditySearch) {
                $q->orderByDesc('properties.liquidity_score')
                    ->orderByDesc('properties.liquidity_confidence');
            } elseif ($areaSearch) {
                $q->orderBy('properties.total_area');
            } elseif (Schema::hasTable('property_promotions')) {
                $q->orderByRaw("CASE
                    WHEN EXISTS (SELECT 1 FROM property_promotions pp WHERE pp.property_id = properties.id AND pp.status = 'active' AND pp.starts_at <= CURRENT_TIMESTAMP AND pp.ends_at > CURRENT_TIMESTAMP AND pp.type = 'urgent') THEN 0
                    WHEN EXISTS (SELECT 1 FROM property_promotions pp WHERE pp.property_id = properties.id AND pp.status = 'active' AND pp.starts_at <= CURRENT_TIMESTAMP AND pp.ends_at > CURRENT_TIMESTAMP AND pp.type = 'vip') THEN 1
                    ELSE 2 END");
            } else {
                $q->orderByRaw("CASE WHEN properties.listing_type='urgent' THEN 0 WHEN properties.listing_type='vip' THEN 1 ELSE 2 END");
            }
            $q->orderByDesc('properties.created_at')->orderByDesc('properties.id');
        };

        // значения цены
        $priceMin = isset($args['price_min']) ? (float) $args['price_min'] : null;
        $priceMax = isset($args['price_max']) ? (float) $args['price_max'] : null;

        // ---------- Ступени поиска (ослабляем постепенно) ----------
        // step 1: все фильтры
        $q1 = (clone $base);
        $applyFilters($q1, [
            'city' => true,
            'districts' => $args['districts'] ?? null,
            'offer' => true,
            'type' => true,
            'rooms' => true,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ]);
        $rows = $q1->limit($limit)->get();

        // step 2: если пусто, убираем city
        if (! $strictSearch && $rows->isEmpty() && $city) {
            $q2 = (clone $base);
            $applyFilters($q2, [
                'city' => false,
                'districts' => $args['districts'] ?? null,
                'offer' => true,
                'type' => true,
                'rooms' => true,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
            ]);
            $rows = $q2->limit($limit)->get();
        }

        // step 3: если пусто, убираем ещё и type
        if (! $strictSearch && $rows->isEmpty() && $typeIds !== []) {
            $q3 = (clone $base);
            $applyFilters($q3, [
                'city' => false,
                'districts' => $args['districts'] ?? null,
                'offer' => true,
                'type' => false,
                'rooms' => true,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
            ]);
            $rows = $q3->limit($limit)->get();
        }

        // step 4: если пусто, убираем ещё и rooms
        if (! $strictSearch && $rows->isEmpty() && ($rooms !== null || $roomsMin !== null || $roomsMax !== null)) {
            $q4 = (clone $base);
            $applyFilters($q4, [
                'city' => false,
                'districts' => $args['districts'] ?? null,
                'offer' => true,
                'type' => false,
                'rooms' => false,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
            ]);
            $rows = $q4->limit($limit)->get();
        }

        $ids = $rows->pluck('id')->all();

        $photosByProperty = [];
        if (! empty($ids)) {
            $photos = DB::table('property_photos')
                ->select('property_id', 'file_path', 'position')
                ->whereIn('property_id', $ids)
                ->orderBy('position')
                ->get();

            foreach ($photos as $p) {
                $photosByProperty[$p->property_id][] = [
                    'path' => $p->file_path,
                    'is_main' => ((int) $p->position === 0),
                ];
            }
        }

        // ---------- Mapping ----------
        return $rows->map(function ($r) use ($photosByProperty, $liquiditySearch, $repairTypes, $repairConditions) {
            $type = [
                'id' => $r->type_id,
                'name' => property_exists($r, 'type_name') ? $r->type_name : null,
                'slug' => property_exists($r, 'type_slug') ? $r->type_slug : null,
            ];
            $city = property_exists($r, 'city') ? $r->city : null;

            return [
                'id' => $r->id,
                'title' => $r->title,
                'price' => (float) $r->effective_price,
                'original_price' => (float) $r->price,
                'discount_price' => property_exists($r, 'discount_price') && (float) $r->discount_price > 0
                    ? (float) $r->discount_price
                    : null,
                'currency' => $r->currency ?? 'TJS',
                'city' => $city,
                'district' => $r->district,
                'rooms' => $r->rooms,
                'area' => isset($r->total_area) && (float) $r->total_area > 0 ? (float) $r->total_area : null,
                'repair_type' => isset($repairTypes[$r->repair_type_id ?? null]) ? [
                    'id' => $r->repair_type_id,
                    'name' => $repairTypes[$r->repair_type_id],
                ] : null,
                'repair_condition' => $repairConditions[$r->repair_type_id ?? null] ?? null,
                'renovation_permission_status' => $r->renovation_permission_status ?? null,
                'url' => rtrim(config('app.front_url', 'https://aura.tj'), '/')."/apartment/{$r->id}",

                // 👇 ГЛАВНОЕ
                'photos' => $photosByProperty[$r->id] ?? [],

                // оставляем для совместимости (если фронт где-то ждёт)
                'image' => $photosByProperty[$r->id][0]['path'] ?? null,

                'badge' => $r->offer_type === 'rent' ? 'Сдаётся' : 'Продаётся',
                'type' => $type,
                'created_at' => $r->created_at,
                'listing' => $r->listing_type,
                'address' => $r->address,
                ...($liquiditySearch ? ['selection' => [
                    'basis' => 'liquidity',
                    'calculated_at' => $r->liquidity_calculated_at,
                ]] : []),
            ];
        })->all();
    }

    private function classifyRepairType(string $name): ?string
    {
        $name = str_replace('ё', 'е', mb_strtolower($name));
        $name = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
        $shell = '(?:коробка|без ремонта|без отделки|черновая отделка|черновой ремонт|под самоотделку|shell|unfinished|korobka|bez remonta)';
        if (preg_match('/^'.$shell.'(?: '.$shell.')*$/u', $name)) {
            return 'shell';
        }
        if (preg_match('/^(?:с ремонтом|(?:новый|косметический|капитальный|дизайнерский|современный|хороший|евро) ?ремонт|евроремонт|renovated)$/u', $name)) {
            return 'renovated';
        }

        return null;
    }

    private function resolveTypeIds($raw): array
    {
        // The live catalog can split apartments into new-buildings and resale.
        $genericApartment = in_array(mb_strtolower(trim((string) $raw)), [
            'квартира', 'квартиры', 'квартир', 'кв', 'kv', 'kvartira', 'kvartiry', 'kvartir', 'apt', 'flat', 'apartment', 'apartments',
        ], true);
        if ($genericApartment && Schema::hasTable('property_types')) {
            return DB::table('property_types')->get()->filter(function ($type) {
                return in_array(mb_strtolower($type->slug ?? ''), [
                    'apartment', 'apartments', 'new-buildings', 'new_buildings', 'secondary', 'secondary-housing', 'resale',
                ], true) || in_array(mb_strtolower($type->name ?? ''), [
                    'квартира', 'квартиры', 'новостройки', 'новостройка', 'вторичка', 'вторичное жилье',
                ], true);
            })->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        [$id] = $this->resolveTypeId($raw);

        return $id === null ? [] : [$id];
    }

    private function normalizeCity(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $norm = Str::of($raw)->lower()->toString();

        // Душанбе / Дӯшанбе → душанбе
        $norm = str_replace(['ӯ', 'Ӯ'], ['у', 'У'], $norm);

        $syn = [
            'Душанбе' => 'Душанбе',
            'душанбе' => 'Душанбе',
            'душанбее' => 'Душанбе',
            'dushanbe' => 'Душанбе',
            'Dushanbe' => 'Душанбе',
        ];

        return $syn[$norm] ?? $norm;
    }

    private function resolveTypeId($raw): array
    {
        // Нет значения или нет таблицы типов — выходим мягко
        if (! $raw || ! Schema::hasTable('property_types')) {
            return [null, null];
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return [null, null];
        }

        // Нормализация текста
        $txt = Str::of($value)->lower()->toString();
        // Приведём таджикскую "ӯ" к "у", чтобы "Дӯшанбе" и пр. не мешали
        $txt = str_replace(['ӯ', 'Ӯ'], ['у', 'У'], $txt);

        // --- Эвристика: "хона" часто означает "квартира", если нет явных признаков частного дома
        $signalsHouse = [
            'частный дом', 'частный', 'дом на земле', 'частный сектор',
            'коттедж', 'дача', 'участок', 'земля', 'хавли', 'ҳавли', 'мансард',
        ];
        $isLikelyHouse = false;
        foreach ($signalsHouse as $kw) {
            if (Str::contains($txt, Str::lower($kw))) {
                $isLikelyHouse = true;
                break;
            }
        }
        if (! $isLikelyHouse && (Str::contains($txt, 'хона') || Str::contains($txt, 'дом'))) {
            // Без явных сигналов частного дома — трактуем как apartment
            $value = 'apartment';
            $txt = 'apartment';
        }

        // Синонимы / варианты написания (ru/tg/en)
        $norm = $txt;
        $syn = [
            'квартира' => 'apartment',
            'кв' => 'apartment',
            'kv' => 'apartment',
            'kvartira' => 'apartment',
            'apt' => 'apartment',
            'flat' => 'apartment',
            'apartment' => 'apartment',

            'дом' => 'house',
            'домик' => 'house',
            'house' => 'house',
            'хона' => 'house',
            'хонай' => 'house',

            'дача' => 'cottage',
            'dacha' => 'cottage',
            'cottage' => 'cottage',

            'комната' => 'room',
            'room' => 'room',

            'коммерческая' => 'commercial',
            'коммерческая недвижимость' => 'commercial',
            'commercial' => 'commercial',

            'земля' => 'land',
            'участок' => 'land',
            'land' => 'land',
            'plot' => 'land',
            'участки' => 'land',
        ];
        $slugGuess = $syn[$norm] ?? $norm;

        // Выясним, какие колонки доступны в property_types
        $hasSlug = Schema::hasColumn('property_types', 'slug');
        $hasName = Schema::hasColumn('property_types', 'name');

        // Поиск строки типа
        if (is_numeric($value)) {
            $row = DB::table('property_types')
                ->select('id', $hasName ? 'name' : DB::raw('NULL as name'), $hasSlug ? 'slug' : DB::raw('NULL as slug'))
                ->where('id', (int) $value)
                ->first();
        } else {
            $row = DB::table('property_types')
                ->select('id', $hasName ? 'name' : DB::raw('NULL as name'), $hasSlug ? 'slug' : DB::raw('NULL as slug'))
                ->when($hasSlug || $hasName, function ($q) use ($hasSlug, $hasName, $slugGuess, $value) {
                    $q->where(function ($qq) use ($hasSlug, $hasName, $slugGuess, $value) {
                        if ($hasSlug) {
                            $qq->orWhere('slug', $slugGuess);
                        }
                        if ($hasSlug) {
                            $qq->orWhere('slug', 'like', '%'.$value.'%');
                        }
                        if ($hasName) {
                            $qq->orWhere('name', 'like', '%'.$value.'%');
                        }
                    });
                })
                // Приоритет точному совпадению slug
                ->when($hasSlug, fn ($q) => $q->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [$slugGuess]))
                ->first();
        }

        return $row ? [(int) $row->id, $row] : [null, null];
    }
}
