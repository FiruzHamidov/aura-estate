<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FeatureController extends Controller
{
    // GET /features  (публично)
    public function index(Request $request)
    {
        $q = Feature::query()
            ->when($request->filled('search'), fn($qq) =>
            $qq->where('name', 'like', '%'.$request->get('search').'%')
            )
            ->orderBy('name');

        return $q->paginate($request->get('per_page', 50));
    }

    // GET /features/{id}  (публично)
    public function show(Feature $feature)
    {
        return $feature;
    }

    // POST /features   (auth)
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'slug' => ['nullable','string','max:255','unique:features,slug'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        if (Feature::where('slug', $data['slug'])->exists()) {
            $data['slug'] .= '-'.Str::random(4);
        }

        $feature = Feature::create($data);
        return response()->json($feature, 201);
    }

    // PUT/PATCH /features/{id}   (auth)
    public function update(Request $request, Feature $feature)
    {
        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'slug' => ['sometimes','string','max:255', Rule::unique('features','slug')->ignore($feature->id)],
        ]);

        if (isset($data['name']) && !isset($data['slug'])) {
            $candidate = Str::slug($data['name']);
            if ($candidate && $candidate !== $feature->slug) {
                $data['slug'] = $candidate;
            }
        }

        $feature->update($data);
        return $feature;
    }

    // DELETE /features/{id}   (auth)
    public function destroy(Feature $feature)
    {
        // if ($feature->newBuildings()->exists()) abort(409, 'Есть связанные новостройки');
        $feature->delete();
        return response()->noContent();
    }
}
