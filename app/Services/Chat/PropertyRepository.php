<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PropertyRepository
{
    public function search(array $args): array
    {
        // ---------- Нормализация ----------
        $limit     = (int)($args['limit'] ?? 6);
        $rooms     = isset($args['rooms']) ? (int)$args['rooms'] : null;
        $offerType = ($args['offer_type'] ?? null) === 'rent' ? 'rent' : (($args['offer_type'] ?? null) === 'sale' ? 'sale' : null);

        $cityRaw = $args['city'] ?? '';
        $city    = $this->normalizeCity($cityRaw);

        [$typeId, $typeMeta] = $this->resolveTypeId($args['property_type'] ?? null);

        // ---------- База запроса ----------
        $base = DB::table('properties')
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
                'properties.listing_type',
                'properties.created_at',
            ])
            ->where('properties.moderation_status', '=', 'approved');

        $hasTypes = Schema::hasTable('property_types');
        if ($hasTypes) {
            $base->leftJoin('property_types', 'properties.type_id', '=', 'property_types.id')
                ->addSelect([
                    DB::raw('COALESCE(property_types.name, NULL) as type_name'),
                    DB::raw('COALESCE(property_types.slug, NULL) as type_slug'),
                ]);
        }

        $hasLocations = Schema::hasTable('locations');
        if ($hasLocations && Schema::hasColumn('locations', 'name')) {
            $base->leftJoin('locations', 'properties.location_id', '=', 'locations.id')
                ->addSelect(DB::raw('COALESCE(locations.name, NULL) as city'));
        }

        // единый билдер с функцией накатывания фильтров
        $applyFilters = function($q, $opts) use ($hasLocations, $offerType, $typeId, $rooms, $city) {
            if (!empty($opts['city']) && $city) {
                // важно обернуть в одну группу, чтобы orWhere не ломал остальные условия
                $q->where(function($w) use ($hasLocations, $city) {
                    if ($hasLocations && Schema::hasColumn('locations', 'name')) {
                        $w->where('locations.name', 'like', '%'.$city.'%');
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

            if (!empty($opts['offer']) && $offerType) {
                $q->where('properties.offer_type', $offerType);
            }

            if (!empty($opts['type']) && $typeId !== null) {
                $q->where('properties.type_id', $typeId);
            }

            if (!empty($opts['rooms']) && $rooms !== null) {
                $q->where('properties.rooms', $rooms);
            }

            $priceMin = isset($opts['price_min']) ? $opts['price_min'] : null;
            $priceMax = isset($opts['price_max']) ? $opts['price_max'] : null;

            if ($priceMin !== null && $priceMin > 0) {
                $q->where('properties.price', '>=', $priceMin);
            }
            if ($priceMax !== null && $priceMax > 0) {
                $q->where('properties.price', '<=', $priceMax);
            }

            $q->orderByRaw("CASE WHEN properties.listing_type='vip' THEN 0 WHEN properties.listing_type='urgent' THEN 1 ELSE 2 END")
                ->orderByDesc('properties.created_at');
        };

        // значения цены
        $priceMin = isset($args['price_min']) ? (float)$args['price_min'] : null;
        $priceMax = isset($args['price_max']) ? (float)$args['price_max'] : null;

        // ---------- Ступени поиска (ослабляем постепенно) ----------
        // step 1: все фильтры
        $q1 = (clone $base);
        $applyFilters($q1, ['city'=>true,'offer'=>true,'type'=>true,'rooms'=>true,'price_min'=>$priceMin,'price_max'=>$priceMax]);
        $rows = $q1->limit($limit)->get();

        // step 2: если пусто, убираем city
        if ($rows->isEmpty() && $city) {
            $q2 = (clone $base);
            $applyFilters($q2, ['city'=>false,'offer'=>true,'type'=>true,'rooms'=>true,'price_min'=>$priceMin,'price_max'=>$priceMax]);
            $rows = $q2->limit($limit)->get();
        }

        // step 3: если пусто, убираем ещё и type
        if ($rows->isEmpty() && $typeId !== null) {
            $q3 = (clone $base);
            $applyFilters($q3, ['city'=>false,'offer'=>true,'type'=>false,'rooms'=>true,'price_min'=>$priceMin,'price_max'=>$priceMax]);
            $rows = $q3->limit($limit)->get();
        }

        // step 4: если пусто, убираем ещё и rooms
        if ($rows->isEmpty() && $rooms !== null) {
            $q4 = (clone $base);
            $applyFilters($q4, ['city'=>false,'offer'=>true,'type'=>false,'rooms'=>false,'price_min'=>$priceMin,'price_max'=>$priceMax]);
            $rows = $q4->limit($limit)->get();
        }

        // ---------- Mapping ----------
        return $rows->map(function ($r) {
            $type = [
                'id'   => $r->type_id,
                'name' => property_exists($r, 'type_name') ? $r->type_name : null,
                'slug' => property_exists($r, 'type_slug') ? $r->type_slug : null,
            ];
            $city = property_exists($r, 'city') ? $r->city : null;

            return [
                'id'         => $r->id,
                'title'      => $r->title,
                'price'      => (float)$r->price,
                'currency'   => $r->currency ?? 'TJS',
                'city'       => $city,
                'district'   => $r->district,
                'rooms'      => $r->rooms,
                'area'       => null,
                'url' => rtrim(config('app.front_url','https://aura.tj'),'/')."/apartment/{$r->id}",
                'image'      => null,
                'badge'      => $r->offer_type === 'rent' ? 'Сдаётся' : 'Продаётся',
                'type'       => $type,
                'created_at' => $r->created_at,
                'listing'    => $r->listing_type,
                'address'    => $r->address,
            ];
        })->all();
    }

    private function normalizeCity(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') return null;

        $norm = Str::of($raw)->lower()->toString();

        // Душанбе / Дӯшанбе → душанбе
        $norm = str_replace(['ӯ','Ӯ'], ['у','У'], $norm);

        $syn = [
            'Душанбе' => 'Душанбе',
            'душанбе' => 'душанбе',
            'душанбее' => 'Душанбе',
            'dushanbe' => 'душанбе',
            'Dushanbe' => 'Душанбе',
        ];

        return $syn[$norm] ?? $norm;
    }

    private function resolveTypeId($raw): array
    {
        // Нет значения или нет таблицы типов — выходим мягко
        if (!$raw || !Schema::hasTable('property_types')) {
            return [null, null];
        }

        $value = trim((string)$raw);
        if ($value === '') return [null, null];

        // Нормализация текста
        $txt  = Str::of($value)->lower()->toString();
        // Приведём таджикскую "ӯ" к "у", чтобы "Дӯшанбе" и пр. не мешали
        $txt  = str_replace(['ӯ','Ӯ'], ['у','У'], $txt);

        // --- Эвристика: "хона" часто означает "квартира", если нет явных признаков частного дома
        $signalsHouse = [
            'частный дом','частный','дом на земле','частный сектор',
            'коттедж','дача','участок','земля','хавли','ҳавли','мансард',
        ];
        $isLikelyHouse = false;
        foreach ($signalsHouse as $kw) {
            if (Str::contains($txt, Str::lower($kw))) { $isLikelyHouse = true; break; }
        }
        if (!$isLikelyHouse && (Str::contains($txt, 'хона') || Str::contains($txt, 'дом'))) {
            // Без явных сигналов частного дома — трактуем как apartment
            $value = 'apartment';
            $txt   = 'apartment';
        }

        // Синонимы / варианты написания (ru/tg/en)
        $norm = $txt;
        $syn = [
            'квартира' => 'apartment',
            'кв'       => 'apartment',
            'kv'       => 'apartment',
            'kvartira' => 'apartment',
            'apt'      => 'apartment',
            'flat'     => 'apartment',
            'apartment'=> 'apartment',

            'дом'      => 'house',
            'домик'    => 'house',
            'house'    => 'house',
            'хона'     => 'house',   // если не сработала эвристика выше — оставим как house
            'хонай'    => 'house',

            'дача'     => 'cottage',
            'dacha'    => 'cottage',
            'cottage'  => 'cottage',

            'комната'  => 'room',
            'room'     => 'room',

            'коммерческая' => 'commercial',
            'коммерческая недвижимость' => 'commercial',
            'commercial'    => 'commercial',

            'земля'    => 'land',
            'участок'  => 'land',
            'land'     => 'land',
            'plot'     => 'land',
            'участки'  => 'land',
        ];
        $slugGuess = $syn[$norm] ?? $norm;

        // Выясним, какие колонки доступны в property_types
        $hasSlug = Schema::hasColumn('property_types', 'slug');
        $hasName = Schema::hasColumn('property_types', 'name');

        // Поиск строки типа
        if (is_numeric($value)) {
            $row = DB::table('property_types')
                ->select('id', $hasName?'name':DB::raw('NULL as name'), $hasSlug?'slug':DB::raw('NULL as slug'))
                ->where('id', (int)$value)
                ->first();
        } else {
            $row = DB::table('property_types')
                ->select('id', $hasName?'name':DB::raw('NULL as name'), $hasSlug?'slug':DB::raw('NULL as slug'))
                ->when($hasSlug || $hasName, function($q) use ($hasSlug, $hasName, $slugGuess, $value) {
                    $q->where(function($qq) use ($hasSlug, $hasName, $slugGuess, $value) {
                        if ($hasSlug)      $qq->orWhere('slug', $slugGuess);
                        if ($hasSlug)      $qq->orWhere('slug', 'like', '%'.$value.'%');
                        if ($hasName)      $qq->orWhere('name', 'like', '%'.$value.'%');
                    });
                })
                // Приоритет точному совпадению slug
                ->when($hasSlug, fn($q) => $q->orderByRaw("CASE WHEN slug = ? THEN 0 ELSE 1 END", [$slugGuess]))
                ->first();
        }

        return $row ? [(int)$row->id, $row] : [null, null];
    }
}
