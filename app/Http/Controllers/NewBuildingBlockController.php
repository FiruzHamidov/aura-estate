<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingBlock;
use App\Services\Residential\StructureWriter;
use Illuminate\Http\Request;

class NewBuildingBlockController extends Controller
{
    public function __construct(private readonly StructureWriter $writer) {}

    public function index(NewBuilding $new_building, Request $request)
    {
        return $new_building->blocks()->when(! $request->is('api/admin/*'), fn ($q) => $q->whereNull('archived_at'))->orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request, NewBuilding $new_building)
    {
        return response()->json($this->writer->save($request->user(), $new_building, 'blocks', $request->all()), 201);
    }

    public function show(NewBuilding $new_building, NewBuildingBlock $block)
    {
        return $new_building->blocks()->findOrFail($block->id);
    }

    public function update(Request $request, NewBuilding $new_building, NewBuildingBlock $block)
    {
        return $this->writer->save($request->user(), $new_building, 'blocks', $request->all(), $block->id);
    }

    public function destroy(Request $request, NewBuilding $new_building, NewBuildingBlock $block)
    {
        $this->writer->remove($request->user(), $new_building, 'blocks', $block->id, $request->all());

        return response()->noContent();
    }
}
