<?php

namespace App\Http\Controllers;

use App\Models\PropertyStatus;
use Illuminate\Http\Request;

class PropertyStatusController extends Controller
{
    public function index()
    {
        return response()->json(PropertyStatus::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:property_statuses,name',
            'slug' => 'required|string|unique:property_statuses,slug',
        ]);

        $status = PropertyStatus::create($validated);
        return response()->json($status, 201);
    }

    public function show(PropertyStatus $propertyStatus)
    {
        return response()->json($propertyStatus);
    }

    public function update(Request $request, PropertyStatus $propertyStatus)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|unique:property_statuses,name,' . $propertyStatus->id,
            'slug' => 'sometimes|string|unique:property_statuses,slug,' . $propertyStatus->id,
        ]);

        $propertyStatus->update($validated);
        return response()->json($propertyStatus);
    }

    public function destroy(PropertyStatus $propertyStatus)
    {
        $propertyStatus->delete();
        return response()->json(['message' => 'Удалено']);
    }
}
