<?php

namespace App\Http\Controllers;

use App\Models\PropertyType;
use Illuminate\Http\Request;

class PropertyTypeController extends Controller
{
    public function index()
    {
        return response()->json(PropertyType::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:property_types,name',
            'slug' => 'required|string|unique:property_types,slug',
        ]);

        $type = PropertyType::create($validated);
        return response()->json($type, 201);
    }

    public function show(PropertyType $propertyType)
    {
        return response()->json($propertyType);
    }

    public function update(Request $request, PropertyType $propertyType)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|unique:property_types,name,' . $propertyType->id,
            'slug' => 'sometimes|string|unique:property_types,slug,' . $propertyType->id,
        ]);

        $propertyType->update($validated);
        return response()->json($propertyType);
    }

    public function destroy(PropertyType $propertyType)
    {
        $propertyType->delete();
        return response()->json(['message' => 'Удалено']);
    }
}
