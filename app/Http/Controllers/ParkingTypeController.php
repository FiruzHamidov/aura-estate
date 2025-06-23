<?php

namespace App\Http\Controllers;

use App\Models\ParkingType;
use Illuminate\Http\Request;

class ParkingTypeController extends Controller
{
    public function index()
    {
        return ParkingType::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string']);
        return ParkingType::create($validated);
    }

    public function show(ParkingType $parkingType)
    {
        return $parkingType;
    }

    public function update(Request $request, ParkingType $parkingType)
    {
        $validated = $request->validate(['name' => 'required|string']);
        $parkingType->update($validated);
        return $parkingType;
    }

    public function destroy(ParkingType $parkingType)
    {
        $parkingType->delete();
        return response()->noContent();
    }
}
