<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\DeveloperUnit;
use App\Http\Requests\StoreDeveloperUnitRequest;
use App\Http\Requests\UpdateDeveloperUnitRequest;
use Illuminate\Http\Request;

class DeveloperUnitController extends Controller
{
    public function index(NewBuilding $new_building, Request $req)
    {
        return $new_building->units()
            ->with('block')
            ->when($req->available, fn($q) => $q->where('is_available', (bool)$req->boolean('available')))
            ->paginate($req->get('per_page', 15));
    }

    public function store(StoreDeveloperUnitRequest $request, NewBuilding $new_building)
    {
        $data = $request->validated();
        $data['new_building_id'] = $new_building->id;
        $unit = DeveloperUnit::create($data);
        return response()->json($unit->load('block'), 201);
    }

    public function show(NewBuilding $new_building, DeveloperUnit $unit)
    {
        // опционально: убедиться, что юнит принадлежит зданию
        if ($unit->new_building_id !== $new_building->id) {
            abort(404);
        }

        return $unit->load(['block','photos','newBuilding']);
    }

    public function update(UpdateDeveloperUnitRequest $request, DeveloperUnit $unit)
    {
        $unit->update($request->validated());
        return $unit->load('block');
    }

    public function destroy(DeveloperUnit $unit)
    {
        $unit->delete();
        return response()->noContent();
    }
}
