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
            ->with(['developer', 'previewUnits', 'stage','location'])
            ->when($req->developer_id, fn($qq) => $qq->where('developer_id', $req->developer_id))
            ->when($req->stage_id, fn($qq) => $qq->where('construction_stage_id', $req->stage_id))
            ->when($req->material_id, fn($qq) => $qq->where('material_id', $req->material_id))
            ->when($req->search, fn($qq) => $qq->where('title','like','%'.$req->search.'%'))
            // optional filters for ceiling height
            ->when($req->filled('ceiling_height_min'), fn($qq) => $qq->where('ceiling_height', '>=', (float)$req->ceiling_height_min))
            ->when($req->filled('ceiling_height_max'), fn($qq) => $qq->where('ceiling_height', '<=', (float)$req->ceiling_height_max));

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

        // ensure ceiling_height is returned as float|null (not string)
        if (!is_null($nb->ceiling_height)) {
            $nb->ceiling_height = (float)$nb->ceiling_height;
        }

        return response()->json($nb->load(['developer','stage','material','features']), 201);
    }

    public function show(NewBuilding $new_building)
    {
        // грузим связи
        $new_building->load(['developer','stage','material','features','blocks','units','photos', 'location']);

        // приводим ceiling_height к числу
        if (!is_null($new_building->ceiling_height)) {
            $new_building->ceiling_height = (float)$new_building->ceiling_height;
        }

        // считаем агрегаты по доступным и одобренным юнитам
        $unitsQ = $new_building->units()
            ->where('is_available', true)
            ->where('moderation_status', 'approved');

        $stats = $unitsQ->selectRaw('
        MIN(total_price)   as min_total_price,
        MAX(total_price)   as max_total_price,
        MIN(price_per_sqm) as min_ppsqm,
        MAX(price_per_sqm) as max_ppsqm
    ')->first();

        // Хелперы форматирования
        $fmt = fn($v) => is_null($v) ? null : number_format((float)$v, 0, '.', ' ');
        $range = function ($min, $max, string $suffix) use ($fmt) {
            if (is_null($min) || is_null($max)) return null;               // нет данных
            if ((float)$min === (float)$max) return $fmt($min).$suffix;    // одно значение
            return $fmt($min).' – '.$fmt($max).$suffix;                    // вилка
        };

        $totalPriceRange   = $range($stats->min_total_price ?? null, $stats->max_total_price ?? null, ' c.');
        $pricePerSqmRange  = $range($stats->min_ppsqm ?? null, $stats->max_ppsqm ?? null, ' c./м²');

        // Можно вернуть как мета-блок рядом с данными объекта
        return response()->json([
            'data' => $new_building,
            'stats' => [
                'total_price' => [
                    'min'       => $stats->min_total_price ? (float)$stats->min_total_price : null,
                    'max'       => $stats->max_total_price ? (float)$stats->max_total_price : null,
                    'formatted' => $totalPriceRange, // например: "473 860 c. – 1 148 240 c."
                ],
                'price_per_sqm' => [
                    'min'       => $stats->min_ppsqm ? (float)$stats->min_ppsqm : null,
                    'max'       => $stats->max_ppsqm ? (float)$stats->max_ppsqm : null,
                    'formatted' => $pricePerSqmRange, // например: "8 170 c. – 9 260 c./м²"
                ],
            ],
        ]);
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

        // ensure ceiling_height is numeric in response
        if (!is_null($new_building->ceiling_height)) {
            $new_building->ceiling_height = (float)$new_building->ceiling_height;
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
