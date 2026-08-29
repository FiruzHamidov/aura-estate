<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Services\Residential\ResidentialAccess;
use App\Services\Residential\StructureWriter;
use Illuminate\Http\Request;

class ResidentialStructureController extends Controller
{
    public function __construct(private readonly ResidentialAccess $access, private readonly StructureWriter $writer) {}

    private function relation(NewBuilding $building, string $kind)
    {
        return match ($kind) {
            'entrances' => $building->entrances(), 'layouts' => $building->layouts(), 'floor-plans' => $building->floorPlans(), default => abort(404)
        };
    }

    public function index(Request $request, NewBuilding $new_building, string $kind)
    {
        $this->access->ensureManage($request->user(), $new_building);
        $input = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|between:1,100', 'block_id' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100']);
        $query = $this->relation($new_building, $kind);
        if (! empty($input['search']) && in_array($kind, ['entrances', 'layouts'], true)) {
            $query->where($kind === 'layouts' ? 'code' : 'name', 'like', '%'.$input['search'].'%');
        }
        if ($kind === 'entrances' && isset($input['block_id'])) {
            $query->where('block_id', $input['block_id']);
        }
        $page = $query->orderBy('id')->paginate($input['per_page'] ?? 100);
        if ($kind !== 'entrances') {
            $page->through(fn ($record) => $record->toArray() + ['image_url' => app(\App\Services\Residential\MediaAssets::class)->url($record, 'preview', $request->user())]);
        }

        return $page;
    }

    public function entrances(Request $request, NewBuilding $new_building)
    {
        abort_unless(NewBuilding::query()->published()->whereKey($new_building->id)->exists(), 404);
        $input = $request->validate(['block_id' => 'required|integer|min:1']);
        $block = $new_building->blocks()->whereNull('archived_at')->findOrFail($input['block_id']);

        return $block->entrances()->orderBy('sort_order')->orderBy('id')->get(['id', 'block_id', 'name', 'residential_floor_from', 'residential_floor_to']);
    }

    public function store(Request $request, NewBuilding $new_building, string $kind)
    {
        return response()->json($this->writer->save($request->user(), $new_building, $kind, $request->all()), 201);
    }

    public function update(Request $request, NewBuilding $new_building, string $kind, int $record)
    {
        return $this->writer->save($request->user(), $new_building, $kind, $request->all(), $record);
    }

    public function destroy(Request $request, NewBuilding $new_building, string $kind, int $record)
    {
        $this->writer->remove($request->user(), $new_building, $kind, $record, $request->all());

        return response()->noContent();
    }

    public function usage(Request $request, NewBuilding $new_building, string $kind, int $record)
    {
        $this->access->ensureManage($request->user(), $new_building);

        return app(\App\Services\Residential\StructureReplacement::class)->usage($new_building, $kind, $this->relation($new_building, $kind)->findOrFail($record));
    }

    public function image(Request $request, NewBuilding $new_building, string $kind, int $record, \App\Services\Residential\PlanImages $images)
    {
        $request->validate(['file' => 'required|file']);
        $saved = $images->store($request->user(), $new_building, $kind, $record, $request->file('file'), $request->all());

        return $saved->toArray() + ['image_url' => app(\App\Services\Residential\MediaAssets::class)->url($saved, 'preview', $request->user())];
    }
}
