<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\Feature;
use App\Http\Requests\StoreNewBuildingRequest;
use App\Http\Requests\UpdateNewBuildingRequest;
use Illuminate\Http\Request;

class NewBuildingController extends Controller
{
    public function index(Request $req)
    {
        $q = NewBuilding::query()
            ->with(['developer','stage','material','features','photos'])
            ->when($req->developer_id, fn($qq) => $qq->where('developer_id', $req->developer_id))
            ->when($req->stage_id, fn($qq) => $qq->where('construction_stage_id', $req->stage_id))
            ->when($req->material_id, fn($qq) => $qq->where('material_id', $req->material_id))
            ->when($req->search, fn($qq) => $qq->where('title','like','%'.$req->search.'%'));

        return $q->paginate($req->get('per_page', 15));
    }

    public function store(StoreNewBuildingRequest $request)
    {
        $data = $request->validated();
        $features = $data['features'] ?? [];
        unset($data['features']);

        $nb = NewBuilding::create($data);
        if ($features) {
            $nb->features()->sync($features);
        }
        return response()->json($nb->load(['developer','stage','material','features']), 201);
    }

    public function show(NewBuilding $new_building)
    {
        return $new_building->load(['developer','stage','material','features','blocks','units','photos']);
    }

    public function update(UpdateNewBuildingRequest $request, NewBuilding $new_building)
    {
        $data = $request->validated();
        $features = $data['features'] ?? null;
        unset($data['features']);

        $new_building->update($data);
        if (!is_null($features)) {
            $new_building->features()->sync($features);
        }
        return $new_building->load(['developer','stage','material','features']);
    }

    public function destroy(NewBuilding $new_building)
    {
        $new_building->delete();
        return response()->noContent();
    }

    public function attachFeature(NewBuilding $new_building, Feature $feature)
    {
        $new_building->features()->syncWithoutDetaching([$feature->id]);
        return response()->json(['ok' => true]);
    }

    public function detachFeature(NewBuilding $new_building, Feature $feature)
    {
        $new_building->features()->detach($feature->id);
        return response()->json(['ok' => true]);
    }
}
