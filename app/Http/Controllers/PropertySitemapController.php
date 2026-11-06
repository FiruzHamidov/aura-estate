<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;

final class PropertySitemapController extends Controller
{
    private const PAGE_SIZE = 500;

    public function __invoke(Request $request)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1|max:1000000']);
        // Use the same visibility contract as anonymous search. Never publish
        // drafts, closed deals, blocking reviews or confirmed duplicates.
        $query = Property::query()->publicSearchable();
        if (! isset($input['page'])) {
            return response()->json([
                'page_size' => self::PAGE_SIZE,
                'pages' => (int) ceil($query->count() / self::PAGE_SIZE),
            ])->header('Cache-Control', 'no-store');
        }

        $page = $query->orderBy('properties.id')->paginate(
            self::PAGE_SIZE, ['properties.id', 'properties.updated_at', 'properties.created_at'], 'page', $input['page'],
        );
        abort_if($page->currentPage() > max(1, $page->lastPage()), 404);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Property $property) => [
                'path' => '/apartment/'.$property->id,
                'last_modified' => ($property->updated_at ?? $property->created_at)?->toIso8601String(),
            ]),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }
}
