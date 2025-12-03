<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingBlock;
use App\Http\Requests\StoreNewBuildingBlockRequest;
use App\Http\Requests\UpdateNewBuildingBlockRequest;

class NewBuildingBlockController extends Controller
{
    // GET /new-buildings/{new_building}/blocks
    public function index(NewBuilding $new_building)
    {
        return $new_building->blocks()->orderBy('name')->get();
    }

    // POST /new-buildings/{new_building}/blocks
    public function store(StoreNewBuildingBlockRequest $request, NewBuilding $new_building)
    {
        $block = $new_building->blocks()->create($request->validated());
        return response()->json($block->load(/* связи если нужно */), 201);
    }

    // GET /new-buildings/{new_building}/blocks/{block}
    public function show(NewBuilding $new_building, NewBuildingBlock $block)
    {
        return $block->load(/* связи если нужно */);
    }

    // PUT/PATCH /new-buildings/{new_building}/blocks/{block}
    public function update(UpdateNewBuildingBlockRequest $request, NewBuilding $new_building, NewBuildingBlock $block)
    {
        $block->update($request->validated());
        return $block->refresh();
    }

    // DELETE /new-buildings/{new_building}/blocks/{block}
    public function destroy(NewBuilding $new_building, NewBuildingBlock $block)
    {
        $block->delete();
        return response()->noContent();
    }
}
