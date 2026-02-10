<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return response()->json(Branch::query()->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'landmark' => 'nullable|string|max:255',
            'photo' => 'nullable|string|max:2048',
        ]);

        $branch = Branch::create($validated);

        return response()->json($branch, 201);
    }

    public function show(Branch $branch)
    {
        return response()->json($branch);
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'landmark' => 'nullable|string|max:255',
            'photo' => 'nullable|string|max:2048',
        ]);

        $branch->update($validated);

        return response()->json($branch);
    }

    public function destroy(Branch $branch)
    {
        if ($branch->users()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить филиал: к нему привязаны пользователи',
            ], 409);
        }

        $branch->delete();

        return response()->json(['message' => 'Филиал удален']);
    }
}
