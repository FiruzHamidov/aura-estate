<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use Illuminate\Http\Request;

final class ResidentialSitemapController extends Controller
{
    private const PAGE_SIZE = 500;

    public function __invoke(Request $request)
    {
        $input = $request->validate(['type' => 'sometimes|in:buildings,units', 'page' => 'sometimes|integer|min:1|max:1000000']);
        if (! isset($input['type'])) {
            return response()->json(['page_size' => self::PAGE_SIZE, 'pages' => [
                'buildings' => (int) ceil(NewBuilding::query()->published()->count() / self::PAGE_SIZE),
                'units' => (int) ceil(DeveloperUnit::query()->published()->count() / self::PAGE_SIZE),
            ]])->header('Cache-Control', 'no-store');
        }
        $units = $input['type'] === 'units';
        $query = $units ? DeveloperUnit::query()->published() : NewBuilding::query()->published();
        $page = $query->orderBy('id')->paginate(self::PAGE_SIZE, $units ? ['id', 'new_building_id', 'updated_at', 'created_at'] : ['id', 'updated_at', 'created_at'], 'page', $input['page'] ?? 1);
        abort_if($page->currentPage() > max(1, $page->lastPage()), 404);

        return response()->json(['data' => $page->getCollection()->map(fn ($record) => [
            'path' => $units ? '/new-buildings/'.$record->new_building_id.'/units/'.$record->id : '/new-buildings/'.$record->id,
            'last_modified' => ($record->updated_at ?? $record->created_at)?->toIso8601String(),
        ]), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'no-store');
    }
}
