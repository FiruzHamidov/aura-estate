<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return Tag::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = $request->string('search')->trim()->toString();

                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->where('name', 'like', '%'.$term.'%')
                        ->orWhere('slug', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 100));
    }

    public function show(Tag $tag)
    {
        return $tag;
    }

    public function store(Request $request)
    {
        $data = $this->validateTag($request);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        if ($data['slug'] === '') {
            $data['slug'] = 'tag-'.Str::lower(Str::random(8));
        }

        $baseSlug = $data['slug'];
        $suffix = 2;

        while (Tag::query()->where('slug', $data['slug'])->exists()) {
            $data['slug'] = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return response()->json(Tag::query()->create($data), 201);
    }

    public function update(Request $request, Tag $tag)
    {
        $data = $this->validateTag($request, $tag);

        $tag->update($data);

        return $tag;
    }

    public function destroy(Tag $tag)
    {
        $tag->delete();

        return response()->noContent();
    }

    private function validateTag(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'name' => [$tag ? 'sometimes' : 'required', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tags', 'slug')->ignore($tag?->id),
            ],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }
}
