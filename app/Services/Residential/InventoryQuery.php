<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use Illuminate\Database\Eloquent\Builder;

final class InventoryQuery
{
    public const ROOMS_SQL = 'COALESCE(developer_units.rooms, NULLIF(developer_units.bedrooms, 0))';

    public function units(int $buildingId, array $filters = [], bool $availableOnly = false): Builder
    {
        $statuses = ! $availableOnly && ($filters['include_reserved'] ?? false) ? ['available', 'reserved'] : ['available'];

        return $this->filterUnits(DeveloperUnit::query()->where('new_building_id', $buildingId)->availability($statuses), $filters);
    }

    /** All apartment predicates are applied to this SAME query, including catalog EXISTS. */
    public function filterUnits(Builder $query, array $filters): Builder
    {
        foreach (['block_id', 'entrance_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('developer_units.'.$field, $filters[$field]);
            }
        }
        foreach (['price' => 'total_price', 'area' => 'area', 'floor' => 'floor', 'kitchen' => 'kitchen_area'] as $key => $column) {
            foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                if (isset($filters[$key.'_'.$bound])) {
                    $query->where('developer_units.'.$column, $operator, $filters[$key.'_'.$bound]);
                }
            }
        }
        foreach (['rooms_from' => '>=', 'rooms_to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->whereRaw(self::ROOMS_SQL.' '.$operator.' ?', [$filters[$key]]);
            }
        }
        if (! empty($filters['rooms'])) {
            $query->where(function (Builder $rooms) use ($filters) {
                foreach ($filters['rooms'] as $room) {
                    $rooms->orWhereRaw(self::ROOMS_SQL.($room === '4+' ? ' >= ?' : ' = ?'), [$room === '4+' ? 4 : ($room === 'studio' ? 0 : (int) $room)]);
                }
            });
        }
        foreach (['finishing', 'window_view'] as $field) {
            if (! empty($filters[$field])) {
                $query->whereIn('developer_units.'.$field, $filters[$field]);
            }
        }
        foreach (['exclude_first_floor' => ['>', 'residential_floor_from'], 'exclude_last_floor' => ['<', 'residential_floor_to'], 'only_last_floor' => ['=', 'residential_floor_to']] as $filter => [$operator, $column]) {
            if ($filters[$filter] ?? false) {
                $query->whereHas('entrance', fn (Builder $entrance) => $entrance->whereColumn('developer_units.floor', $operator, 'new_building_entrances.'.$column));
            }
        }

        return $query;
    }

    public function sortBuildingsByCompletion(Builder $query): Builder
    {
        // Quarter/year use their upper calendar boundary for ordering, never a fabricated public date.
        $key = "CASE WHEN completion_precision = 'quarter' AND completion_year IS NOT NULL AND completion_quarter BETWEEN 1 AND 4 THEN completion_year * 10000 + completion_quarter * 300 + CASE WHEN completion_quarter IN (1,4) THEN 31 ELSE 30 END WHEN completion_precision = 'year' AND completion_year IS NOT NULL THEN completion_year * 10000 + 1231 WHEN completion_at IS NOT NULL THEN CAST(REPLACE(SUBSTR(completion_at, 1, 10), '-', '') AS DECIMAL(8,0)) ELSE NULL END";

        return $query->orderByRaw("($key) IS NULL")->orderByRaw("($key) ASC");
    }

    public function sortUnits(Builder $query, array $filters): Builder
    {
        [$column, $direction] = match ($filters['sort'] ?? 'newest') {
            'price_asc' => ['total_price', 'asc'], 'price_desc' => ['total_price', 'desc'],
            'area_asc' => ['area', 'asc'], 'area_desc' => ['area', 'desc'],
            'floor_asc' => ['floor', 'asc'], 'floor_desc' => ['floor', 'desc'],
            default => ['created_at', 'desc'],
        };

        return $query->orderByRaw('developer_units.'.$column.' IS NULL')->orderBy('developer_units.'.$column, $direction)->orderBy('developer_units.id');
    }

    public function buildings(array $filters): Builder
    {
        $query = $this->buildingFilters($filters);
        if ((new InventoryFilters)->hasUnitFilters($filters)) {
            $query->whereHas('units', fn (Builder $units) => $this->filterUnits($units, $filters)->available());
        }

        return $query;
    }

    /** Building predicates only; callers counting matching units already prove their existence. */
    public function buildingFilters(array $filters): Builder
    {
        $query = NewBuilding::query()->published();
        foreach (['developer_id' => 'developer_id', 'stage_id' => 'construction_stage_id', 'material_id' => 'material_id', 'location_id' => 'location_id', 'district' => 'district', 'installment_available' => 'installment_available'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where('new_buildings.'.$column, $filters[$key]);
            }
        }
        if (isset($filters['completion_year'])) {
            $query->where(fn (Builder $q) => $q->where('completion_year', $filters['completion_year'])->orWhere(fn (Builder $legacy) => $legacy->whereNull('completion_year')->whereYear('completion_at', $filters['completion_year'])));
        }
        if (! empty($filters['search'])) {
            $query->where(fn (Builder $q) => $q->where('title', 'like', '%'.$filters['search'].'%')->orWhere('address', 'like', '%'.$filters['search'].'%'));
        }
        foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
            if (isset($filters['ceiling_height_'.$bound])) {
                $query->where('ceiling_height', $operator, $filters['ceiling_height_'.$bound]);
            }
        }
        if (isset($filters['bbox'])) {
            [$west, $south, $east, $north] = array_values($filters['bbox']);
            $query->whereBetween('longitude', [$west, $east])->whereBetween('latitude', [$south, $north]);
        }

        return $query;
    }

    public function withAggregates(Builder $query, array $filters = []): Builder
    {
        $available = $this->filterUnits(DeveloperUnit::query(), $filters)->available()
            ->selectRaw('developer_units.new_building_id AS available_building_id, COUNT(*) AS available_count')
            ->groupBy('developer_units.new_building_id');
        foreach (['total_price', 'price_per_sqm'] as $column) {
            foreach (['min', 'max'] as $aggregate) {
                $available->selectRaw("$aggregate(CASE WHEN total_price > 0 AND currency = 'TJS' THEN $column END) AS {$aggregate}_{$column}");
            }
        }
        $reserved = $this->filterUnits(DeveloperUnit::query(), $filters)->availability(['reserved'])
            ->selectRaw('developer_units.new_building_id AS reserved_building_id, COUNT(*) AS reserved_count')
            ->groupBy('developer_units.new_building_id');

        // Aggregate each public scope once, not six correlated scans per candidate complex.
        return $query->select('new_buildings.*')
            ->leftJoinSub($available, 'available_inventory', 'available_building_id', '=', 'new_buildings.id')
            ->leftJoinSub($reserved, 'reserved_inventory', 'reserved_building_id', '=', 'new_buildings.id')
            ->selectRaw('COALESCE(available_count, 0) AS available_count, COALESCE(reserved_count, 0) AS reserved_count')
            ->addSelect(['min_total_price', 'max_total_price', 'min_price_per_sqm', 'max_price_per_sqm']);
    }

    public function roomsSummary(array $buildingIds, array $filters = []): \Illuminate\Support\Collection
    {
        return $this->filterUnits(DeveloperUnit::query()->available()->whereIn('new_building_id', $buildingIds), $filters)
            ->selectRaw('new_building_id, block_id, '.self::ROOMS_SQL.' AS rooms, COUNT(*) AS offers_count, MIN(area) AS area_from, MIN(CASE WHEN total_price > 0 THEN total_price END) AS price_from')
            ->groupBy('new_building_id', 'block_id')->groupByRaw(self::ROOMS_SQL)->orderBy('block_id')->orderBy('rooms')->get()->groupBy('new_building_id');
    }
}
