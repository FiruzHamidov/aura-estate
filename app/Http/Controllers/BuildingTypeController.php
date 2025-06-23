<?php

namespace App\Http\Controllers;

use App\Models\BuildingType;
use Illuminate\Http\Request;

class BuildingTypeController extends Controller
{
    public function index()
    {
        return BuildingType::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string']);
        return BuildingType::create($validated);
    }

    public function show(BuildingType $buildingType)
    {
        return $buildingType;
    }

    public function update(Request $request, BuildingType $buildingType)
    {
        $validated = $request->validate(['name' => 'required|string']);
        $buildingType->update($validated);
        return $buildingType;
    }

    public function destroy(BuildingType $buildingType)
    {
        $buildingType->delete();
        return response()->noContent();
    }
}
