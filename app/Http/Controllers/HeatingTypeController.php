<?php

namespace App\Http\Controllers;

use App\Models\HeatingType;
use Illuminate\Http\Request;

class HeatingTypeController extends Controller
{
    public function index()
    {
        return HeatingType::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string']);
        return HeatingType::create($validated);
    }

    public function show(HeatingType $heatingType)
    {
        return $heatingType;
    }

    public function update(Request $request, HeatingType $heatingType)
    {
        $validated = $request->validate(['name' => 'required|string']);
        $heatingType->update($validated);
        return $heatingType;
    }

    public function destroy(HeatingType $heatingType)
    {
        $heatingType->delete();
        return response()->noContent();
    }
}
