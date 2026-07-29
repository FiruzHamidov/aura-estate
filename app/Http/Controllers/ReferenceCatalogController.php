<?php

namespace App\Http\Controllers;

use App\Services\ReferenceCatalogService;
use Illuminate\Http\Request;

class ReferenceCatalogController extends Controller
{
    public function __construct(private readonly ReferenceCatalogService $catalogs) {}

    public function usage(Request $request, string $catalog, int $item)
    {
        $this->catalogs->assertCanManage($request->user());

        return response()->json([
            'data' => $this->catalogs->usage($catalog, $item),
        ]);
    }

    public function merge(Request $request, string $catalog, int $item)
    {
        $this->catalogs->assertCanManage($request->user());
        $data = $request->validate([
            'replacement_id' => ['required', 'integer', 'min:1'],
            'expected_usage_count' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json([
            'data' => $this->catalogs->merge(
                $request->user(),
                $catalog,
                $item,
                (int) $data['replacement_id'],
                (int) $data['expected_usage_count'],
                $request->ip(),
                $request->userAgent(),
            ),
        ]);
    }

    public function destroy(Request $request, string $catalog, int $item)
    {
        return response()->json([
            'data' => $this->catalogs->deleteUnused(
                $request->user(),
                $catalog,
                $item,
            ),
        ]);
    }
}
