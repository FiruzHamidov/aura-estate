<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Services\Residential\SimilarInventory;

final class ResidentialSimilarController extends Controller
{
    public function buildings(NewBuilding $new_building, SimilarInventory $similar)
    {
        abort_unless(NewBuilding::query()->published()->whereKey($new_building->id)->exists(), 404);

        return $similar->buildings($new_building);
    }

    public function units(NewBuilding $new_building, int $unit, SimilarInventory $similar)
    {
        $source = $new_building->units()->availability(['available', 'reserved', 'sold'])->findOrFail($unit);

        return $similar->units($new_building, $source);
    }
}
