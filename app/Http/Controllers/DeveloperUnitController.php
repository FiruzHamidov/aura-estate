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

            // фильтр по доступности
            ->when(
                $req->has('available'),
                fn ($q) => $q->where('is_available', $req->boolean('available'))
            )

            // фильтр по блоку
            ->when(
                $req->filled('block_id'),
                fn ($q) => $q->where('block_id', $req->integer('block_id'))
            )

            // комнаты ОТ
            ->when(
                $req->filled('rooms_from'),
                fn ($q) => $q->where('bedrooms', '>=', $req->integer('rooms_from'))
            )

            // комнаты ДО
            ->when(
                $req->filled('rooms_to'),
                fn ($q) => $q->where('bedrooms', '<=', $req->integer('rooms_to'))
            )

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

    public function update(UpdateDeveloperUnitRequest $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        // ensure the unit belongs to the requested building
        if ($unit->new_building_id !== $new_building->id) {
            abort(404);
        }

        $unit->update($request->validated());
        return $unit->load('block');
    }

    public function destroy(NewBuilding $new_building, DeveloperUnit $unit)
    {
        // ensure the unit belongs to the requested building
        if ($unit->new_building_id !== $new_building->id) {
            abort(404);
        }

        $unit->delete();
        return response()->noContent();
    }
}
