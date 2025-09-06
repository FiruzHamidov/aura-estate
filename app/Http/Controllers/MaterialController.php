<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    // GET /materials  (публично)
    public function index(Request $request)
    {
        $q = Material::query()
            ->when($request->filled('search'), fn($qq) =>
            $qq->where('name', 'like', '%'.$request->get('search').'%')
            )
            ->orderBy('name');

        return $q->paginate($request->get('per_page', 50));
    }

    // GET /materials/{id}  (публично)
    public function show(Material $material)
    {
        return $material;
    }

    // POST /materials   (auth)
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'slug' => ['nullable','string','max:255','unique:materials,slug'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        if (Material::where('slug', $data['slug'])->exists()) {
            $data['slug'] .= '-'.Str::random(4);
        }

        $m = Material::create($data);
        return response()->json($m, 201);
    }

    // PUT/PATCH /materials/{id}   (auth)
    public function update(Request $request, Material $material)
    {
        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'slug' => ['sometimes','string','max:255', Rule::unique('materials','slug')->ignore($material->id)],
        ]);

        if (isset($data['name']) && !isset($data['slug'])) {
            $candidate = Str::slug($data['name']);
            if ($candidate && $candidate !== $material->slug) {
                $data['slug'] = $candidate;
            }
        }

        $material->update($data);
        return $material;
    }

    // DELETE /materials/{id}   (auth)
    public function destroy(Material $material)
    {
        // if ($material->newBuildings()->exists()) abort(409, 'Есть связанные новостройки');
        $material->delete();
        return response()->noContent();
    }
}
