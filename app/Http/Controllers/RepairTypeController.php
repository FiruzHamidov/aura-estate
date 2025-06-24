<?php

namespace App\Http\Controllers;

use App\Models\RepairType;
use Illuminate\Http\Request;

class RepairTypeController extends Controller
{
    public function index()
    {
        return RepairType::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:repair_types,name'
        ]);

        $type = RepairType::create(['name' => $request->name]);

        return response()->json($type, 201);
    }

    public function show(RepairType $repairType)
    {
        return $repairType;
    }

    public function update(Request $request, RepairType $repairType)
    {
        $request->validate([
            'name' => 'required|string|unique:repair_types,name,' . $repairType->id
        ]);

        $repairType->update(['name' => $request->name]);

        return response()->json($repairType);
    }

    public function destroy(RepairType $repairType)
    {
        $repairType->delete();

        return response()->json(['message' => 'Удалено']);
    }
}
