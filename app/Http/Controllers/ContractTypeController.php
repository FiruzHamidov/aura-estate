<?php

namespace App\Http\Controllers;

use App\Models\ContractType;
use Illuminate\Http\Request;

class ContractTypeController extends Controller
{
    public function index()
    {
        return response()->json(ContractType::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'slug' => 'required|string|unique:contract_types,slug',
            'name' => 'required|string',
        ]);

        $contractType = ContractType::create($validated);

        return response()->json($contractType, 201);
    }

    public function show(ContractType $contractType)
    {
        return response()->json($contractType);
    }

    public function update(Request $request, ContractType $contractType)
    {
        $validated = $request->validate([
            'slug' => 'sometimes|string|unique:contract_types,slug,' . $contractType->id,
            'name' => 'sometimes|string',
        ]);

        $contractType->update($validated);

        return response()->json($contractType);
    }

    public function destroy(ContractType $contractType)
    {
        $contractType->delete();
        return response()->json(['message' => 'Удалено успешно']);
    }
}
