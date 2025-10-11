<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingBlock;
use App\Http\Requests\StoreNewBuildingBlockRequest;
use App\Http\Requests\UpdateNewBuildingBlockRequest;

class NewBuildingBlockController extends Controller
{
    public function index(NewBuilding $new_building)
    {
        return $new_building->blocks()->orderBy('name')->get();
    }

    public function store(StoreNewBuildingBlockRequest $request, NewBuilding $new_building)
    {
        $block = $new_building->blocks()->create($request->validated());
        return response()->json($block, 201);
    }

    public function show(NewBuildingBlock $new_building_block)
    {
        return $new_building_block;
    }

    public function update(UpdateNewBuildingBlockRequest $request, NewBuildingBlock $block)
    {
        $block->update($request->validated());
        return $new_building_block;
    }

    public function destroy(NewBuildingBlock $new_building_block)
    {
        $new_building_block->delete();
        return response()->noContent();
    }
}
