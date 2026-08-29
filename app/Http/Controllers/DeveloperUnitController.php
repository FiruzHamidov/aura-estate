<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingEntrance;
use App\Services\Residential\InventoryFilters;
use App\Services\Residential\InventoryQuery;
use App\Services\Residential\InventoryWriter;
use App\Services\Residential\PublicInventory;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;

class DeveloperUnitController extends Controller
{
    public function __construct(private readonly InventoryQuery $inventory, private readonly PublicInventory $public, private readonly InventoryWriter $writer, private readonly ResidentialAccess $access) {}

    private function publicBuilding(NewBuilding $building): void
    {
        abort_unless(NewBuilding::query()->published()->whereKey($building->id)->exists(), 404);
    }

    public function index(NewBuilding $new_building, Request $request)
    {
        $this->publicBuilding($new_building);
        $filters = (new InventoryFilters)->validate($request->all());
        $query = $this->inventory->units($new_building->id, $filters);
        $page = $this->inventory->sortUnits($query, $filters)->with(['block', 'entrance', 'coverPhoto'])->paginate($filters['per_page'] ?? 20)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($unit) => $this->public->unit($unit)));

        return response()->json($page->toArray() + ['meta' => $this->counts($new_building, $filters) + ['page' => $page->currentPage(), 'per_page' => $page->perPage()]]);
    }

    private function counts(NewBuilding $building, array $filters): array
    {
        return ['matched_count' => $this->inventory->units($building->id, $filters)->count(),
            'available_count' => $this->inventory->units($building->id, $filters, true)->count(),
            'reserved_count' => $this->inventory->filterUnits($building->units()->getQuery()->availability(['reserved']), $filters)->count(),
            'as_of' => now()->toIso8601String()];
    }

    public function facets(NewBuilding $new_building, Request $request)
    {
        $this->publicBuilding($new_building);
        $filters = (new InventoryFilters)->validate($request->all());
        $ranges = $this->inventory->units($new_building->id, $filters)->selectRaw('MIN(total_price) AS price_min, MAX(total_price) AS price_max, MIN(area) AS area_min, MAX(area) AS area_max, MIN(floor) AS floor_min, MAX(floor) AS floor_max')->first();

        $options = [];
        foreach (['finishing', 'window_view'] as $field) {
            $options[$field] = $this->inventory->units($new_building->id, ['include_reserved' => true])->whereNotNull($field)->where($field, '!=', '')->distinct()->orderBy($field)->limit(100)->pluck($field);
        }
        $options['has_kitchen_area'] = $this->inventory->units($new_building->id, ['include_reserved' => true])->whereNotNull('kitchen_area')->exists();

        return response()->json(['data' => $ranges?->only(['price_min', 'price_max', 'area_min', 'area_max', 'floor_min', 'floor_max']), 'options' => $options, 'meta' => $this->counts($new_building, $filters)]);
    }

    public function grid(NewBuilding $new_building, Request $request)
    {
        $this->publicBuilding($new_building);
        $filters = (new InventoryFilters)->validate($request->all());
        $request->validate(['block_id' => 'required|integer|min:1', 'entrance_id' => 'required|integer|min:1']);
        $entrance = NewBuildingEntrance::query()->where('new_building_id', $new_building->id)->where('block_id', $filters['block_id'])->findOrFail($filters['entrance_id']);
        $matched = $this->inventory->units($new_building->id, $filters)->pluck('id')->flip();
        $geometry = $new_building->units()->availability(['available', 'reserved', 'sold'])->where('entrance_id', $entrance->id)->whereNotNull('position_on_floor')->whereNotNull('floor')->with(['block', 'entrance', 'coverPhoto'])->orderByDesc('floor')->orderBy('position_on_floor')->get();

        return response()->json(['data' => ['entrance' => $entrance->only(['id', 'name', 'residential_floor_from', 'residential_floor_to', 'technical_floors']),
            'cells' => $geometry->map(fn ($unit) => $this->public->unit($unit) + ['matches_filter' => $matched->has($unit->id)]),
            'incomplete_count' => $new_building->units()->published()->where('block_id', $filters['block_id'])->where(fn ($q) => $q->whereNull('entrance_id')->orWhereNull('position_on_floor')->orWhereNull('floor'))->count()],
            'meta' => $this->counts($new_building, $filters)]);
    }

    public function show(NewBuilding $new_building, DeveloperUnit $unit)
    {
        $this->publicBuilding($new_building);
        $unit = $new_building->units()->availability(['available', 'reserved', 'sold'])->with(['block', 'entrance', 'photos', 'layout', 'coverPhoto'])->findOrFail($unit->id);

        return $this->public->unit($unit, true);
    }

    public function adminIndex(NewBuilding $new_building, Request $request)
    {
        $this->access->ensureManage($request->user(), $new_building);
        $filters = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|between:1,100', 'block_id' => 'nullable|integer|min:1', 'entrance_id' => 'nullable|integer|min:1', 'floor_min' => 'nullable|integer|between:0,200', 'floor_max' => 'nullable|integer|between:0,200', 'search' => 'nullable|string|max:100']);
        $query = $new_building->units()->with(['block', 'entrance']);
        foreach (['block_id', 'entrance_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['floor_min'])) {
            $query->where('floor', '>=', $filters['floor_min']);
        }
        if (isset($filters['floor_max'])) {
            $query->where('floor', '<=', $filters['floor_max']);
        }
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%'));
        }

        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
    }

    public function adminShow(NewBuilding $new_building, DeveloperUnit $unit, Request $request)
    {
        $this->access->ensureManage($request->user(), $new_building);

        $record = $new_building->units()->with(['block', 'entrance', 'layout', 'photos'])->findOrFail($unit->id);
        [$publication, $availability] = \App\Services\Residential\InventoryStatus::unit($record->getAttributes());
        return array_replace($record->toArray(), ['publication_status' => $publication, 'availability_status' => $availability, 'rooms' => \App\Services\Residential\InventoryStatus::rooms($record->getAttributes())]);
    }

    public function store(Request $request, NewBuilding $new_building)
    {
        return response()->json($this->writer->unit($request->user(), $new_building, $request->all())->load(['block', 'entrance']), 201);
    }

    public function update(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        return $this->writer->unit($request->user(), $new_building, $request->all(), $unit)->load(['block', 'entrance']);
    }

    public function destroy(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $this->writer->unit($request->user(), $new_building, ['version' => $request->input('version'), 'publication_status' => 'archived', 'availability_status' => 'withdrawn', 'change_reason' => $request->input('change_reason', 'Архивирование квартиры')], $unit);

        return response()->noContent();
    }
}
