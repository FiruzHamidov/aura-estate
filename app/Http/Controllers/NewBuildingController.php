<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\NewBuilding;
use App\Services\Residential\InventoryFilters;
use App\Services\Residential\InventoryQuery;
use App\Services\Residential\InventoryWriter;
use App\Services\Residential\PublicInventory;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;

class NewBuildingController extends Controller
{
    public function __construct(private readonly InventoryQuery $inventory, private readonly PublicInventory $public, private readonly InventoryWriter $writer, private readonly ResidentialAccess $access) {}

    public function index(Request $request)
    {
        $filters = (new InventoryFilters)->validate($request->all(), true);
        $base = $this->inventory->buildings($filters);
        $query = $this->inventory->withAggregates(clone $base, $filters)->with(['developer', 'stage', 'location', 'coverPhoto']);
        match ($filters['sort'] ?? 'newest') {
            'price_asc', 'price_desc' => $query->orderByRaw('min_total_price IS NULL')->orderBy('min_total_price', $filters['sort'] === 'price_asc' ? 'asc' : 'desc'),
            'completion' => $this->inventory->sortBuildingsByCompletion($query),
            default => $query->orderByRaw('COALESCE(published_at, created_at) DESC'),
        };
        $page = $query->orderBy('new_buildings.id')->paginate($filters['per_page'] ?? 15, total: fn () => (clone $base)->count())->withQueryString();
        $rooms = $this->inventory->roomsSummary($page->getCollection()->pluck('id')->all(), $filters);
        $page->setCollection($page->getCollection()->map(fn ($building) => $this->public->building($building) + ['rooms_summary' => $rooms->get($building->id, collect())->values()]));
        $count = $this->inventory->filterUnits(\App\Models\DeveloperUnit::query()->available()
            ->whereIn('new_building_id', $this->inventory->buildingFilters($filters)->select('new_buildings.id')), $filters)->count();

        return response()->json($page->toArray() + ['meta' => ['total_complexes' => $page->total(), 'total_available_units' => $count, 'as_of' => now()->toIso8601String()]]);
    }

    public function map(Request $request)
    {
        $filters = (new InventoryFilters)->validate($request->all(), true);
        $base = $this->inventory->buildings($filters);
        $withoutCoordinates = (clone $base)->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->count();
        $markers = $this->inventory->withAggregates((clone $base)->whereNotNull('latitude')->whereNotNull('longitude'), $filters)->orderBy('id')->limit(2001)->get();

        return response()->json(['data' => $markers->take(2000)->map(fn ($building) => $building->only(['id', 'title', 'address', 'latitude', 'longitude']) + $this->public->aggregates($building)),
            'meta' => ['without_coordinates' => $withoutCoordinates, 'truncated' => $markers->count() > 2000, 'total_complexes' => $base->count(), 'as_of' => now()->toIso8601String()]]);
    }

    public function show(Request $request, NewBuilding $new_building)
    {
        $request->validate(['inventory' => 'sometimes|in:paginated,legacy']);
        $building = $this->inventory->withAggregates(NewBuilding::query()->published())->with(['developer', 'stage', 'material', 'features', 'photos', 'location', 'responsibleAgent', 'coverPhoto', 'blocks' => fn ($q) => $q->whereNull('archived_at')->orderBy('sort_order')])->findOrFail($new_building->id);
        // New clients opt into a bounded preview; legacy clients retain the complete public array.
        $legacyInventory = $request->input('inventory') !== 'paginated';
        $units = $this->inventory->sortUnits($this->inventory->units($building->id), [])->with(['block', 'entrance', 'coverPhoto'])
            ->when(! $legacyInventory, fn ($q) => $q->limit(20))
            ->when($legacyInventory, fn ($q) => $q->with(['photos', 'layout']))->get();
        $data = $this->public->building($building, true) + ['units' => $units->map(fn ($u) => $this->public->unit($u, $legacyInventory)), 'units_has_more' => $building->available_count > $units->count()];

        return response()->json(['data' => $data, 'stats' => $this->public->legacyStats($building),
            'rooms_summary' => $this->inventory->roomsSummary([$building->id])->get($building->id, collect())->groupBy('block_id'),
            'meta' => ['as_of' => now()->toIso8601String()]]);
    }

    public function adminIndex(Request $request)
    {
        abort_unless($this->access->canCreate($request->user()), 403);
        $filters = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|between:1,100', 'search' => 'nullable|string|max:255',
            'developer_id' => 'nullable|integer|exists:developers,id', 'stage_id' => 'nullable|integer|exists:construction_stages,id']);
        $page = $this->access->visible($request->user())->with(['developer', 'stage', 'location'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->when($filters['developer_id'] ?? null, fn ($q, $id) => $q->where('developer_id', $id))
            ->when($filters['stage_id'] ?? null, fn ($q, $id) => $q->where('construction_stage_id', $id))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $page->getCollection()->each(fn ($building) => $building->setAttribute('capabilities', $this->access->capabilities($request->user(), $building)));

        return $page;
    }

    public function adminShow(Request $request, NewBuilding $new_building)
    {
        $this->access->ensureManage($request->user(), $new_building);

        return response()->json(['data' => $new_building->load(['developer', 'stage', 'material', 'features', 'blocks', 'location', 'responsibleAgent']),
            'capabilities' => $this->access->capabilities($request->user(), $new_building)]);
    }

    public function consultants(Request $request, NewBuilding $new_building)
    {
        $this->access->ensureManage($request->user(), $new_building);
        $input = $request->validate(['branch_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100', 'page' => 'integer|min:1', 'per_page' => 'integer|between:1,100']);
        $branch = $request->has('branch_id') ? ($input['branch_id'] ?? null) : $new_building->branch_id;
        abort_if(! $this->access->global($request->user()) && (int) $branch !== (int) $new_building->branch_id, 403);
        $query = \App\Models\User::query()->where('status', 'active')->whereNull('deleted_at')->whereNull('deletion_requested_at')
            ->where('branch_id', $branch)->whereHas('role', fn ($q) => $q->whereIn('slug', [...ResidentialAccess::GLOBAL_ROLES, ...ResidentialAccess::BRANCH_ROLES, ...ResidentialAccess::AUTHOR_ROLES]))
            ->when($input['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'));
        if (! $this->access->canPublish($request->user(), $new_building)) $query->whereIn('id', array_filter([$request->user()->id, $new_building->responsible_agent_id]));
        return $query->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 100, ['id', 'name', 'branch_id']);
    }

    public function store(Request $request)
    {
        return response()->json($this->writer->building($request->user(), $request->all())->load(['developer', 'stage', 'material', 'features']), 201);
    }

    public function update(Request $request, NewBuilding $new_building)
    {
        return $this->writer->building($request->user(), $request->all(), $new_building)->load(['developer', 'stage', 'material', 'features']);
    }

    public function destroy(Request $request, NewBuilding $new_building)
    {
        $this->access->ensurePublish($request->user(), $new_building);
        $this->writer->building($request->user(), ['version' => $request->input('version'), 'publication_status' => 'archived', 'change_reason' => $request->input('change_reason', 'Архивирование ЖК')], $new_building);

        return response()->noContent();
    }

    public function attachFeature(Request $request, NewBuilding $new_building, Feature $feature)
    {
        return $this->changeFeatures($request, $new_building, $feature, true);
    }

    public function detachFeature(Request $request, NewBuilding $new_building, Feature $feature)
    {
        return $this->changeFeatures($request, $new_building, $feature, false);
    }

    private function changeFeatures(Request $request, NewBuilding $building, Feature $feature, bool $attach)
    {
        $this->access->ensureManage($request->user(), $building);
        $ids = $building->features()->pluck('features.id')->all();
        $ids = $attach ? array_unique([...$ids, $feature->id]) : array_diff($ids, [$feature->id]);
        $this->writer->building($request->user(), ['version' => $request->input('version'), 'features' => array_values($ids)], $building);

        return response()->json(['ok' => true]);
    }
}
