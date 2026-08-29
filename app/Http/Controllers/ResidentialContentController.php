<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Services\Residential\BuildingContent;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;

final class ResidentialContentController extends Controller
{
    public function __construct(private readonly BuildingContent $content, private readonly ResidentialAccess $access) {}

    public function index(NewBuilding $new_building)
    {
        abort_unless(NewBuilding::query()->published()->whereKey($new_building->id)->exists(), 404);

        return $this->content->index($new_building);
    }

    public function adminIndex(Request $request, NewBuilding $new_building)
    {
        $this->access->ensureManage($request->user(), $new_building);

        return $this->content->index($new_building, true) + ['capabilities' => $this->access->capabilities($request->user(), $new_building)];
    }

    public function store(Request $request, NewBuilding $new_building, string $kind)
    {
        return response()->json($this->content->serialize($this->content->save($request->user(), $new_building, $kind, $request->all()), $new_building, true), 201);
    }

    public function update(Request $request, NewBuilding $new_building, string $kind, int $record)
    {
        return $this->content->serialize($this->content->save($request->user(), $new_building, $kind, $request->all(), $record), $new_building, true);
    }

    public function destroy(Request $request, NewBuilding $new_building, string $kind, int $record)
    {
        $this->content->remove($request->user(), $new_building, $kind, $record, $request->all());

        return response()->noContent();
    }
}
