<?php

namespace App\Http\Controllers;

use App\Models\ConstructionStage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConstructionStageController extends Controller
{
    // GET /construction-stages  (публично)
    public function index(Request $request)
    {
        $q = ConstructionStage::query()
            ->when($request->filled('active'), fn($qq) => $qq->where('is_active', $request->boolean('active')))
            ->orderBy('sort_order')->orderBy('id');

        return $q->paginate($request->get('per_page', 50));
    }

    // GET /construction-stages/{id}  (публично)
    public function show(ConstructionStage $construction_stage)
    {
        return $construction_stage;
    }

    // POST /construction-stages   (требует auth)
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required','string','max:255'],
            'slug'       => ['nullable','string','max:255','unique:construction_stages,slug'],
            'sort_order' => ['nullable','integer','min:0'],
            'is_active'  => ['nullable','boolean'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        // если автосгенерированный slug пересекается — добавим суффикс
        if (ConstructionStage::where('slug', $data['slug'])->exists()) {
            $data['slug'] .= '-'.Str::random(4);
        }

        $stage = ConstructionStage::create($data);
        return response()->json($stage, 201);
    }

    // PUT/PATCH /construction-stages/{id}   (требует auth)
    public function update(Request $request, ConstructionStage $construction_stage)
    {
        $data = $request->validate([
            'name'       => ['sometimes','string','max:255'],
            'slug'       => ['sometimes','string','max:255', Rule::unique('construction_stages','slug')->ignore($construction_stage->id)],
            'sort_order' => ['sometimes','integer','min:0'],
            'is_active'  => ['sometimes','boolean'],
        ]);

        if (isset($data['name']) && !isset($data['slug'])) {
            $candidate = Str::slug($data['name']);
            if ($candidate && $candidate !== $construction_stage->slug) {
                $data['slug'] = $candidate;
            }
        }

        $construction_stage->update($data);
        return $construction_stage;
    }

    // DELETE /construction-stages/{id}   (требует auth)
    public function destroy(ConstructionStage $construction_stage)
    {
        // при желании: запретить удаление, если есть связанные new_buildings
        // if ($construction_stage->newBuildings()->exists()) abort(409, 'Есть связанные новостройки');

        $construction_stage->delete();
        return response()->noContent();
    }
}
