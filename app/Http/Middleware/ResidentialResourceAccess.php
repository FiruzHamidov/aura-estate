<?php

namespace App\Http\Middleware;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingBlock;
use App\Services\Residential\ResidentialAccess;
use Closure;
use Illuminate\Http\Request;

class ResidentialResourceAccess
{
    public function __construct(private readonly ResidentialAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        $parameter = $request->route('new_building');
        $building = $parameter instanceof NewBuilding ? $parameter : NewBuilding::query()->findOrFail($parameter);
        if (! $request->isMethod('GET') || $request->is('api/admin/*')) {
            $this->access->ensureManage($request->user(), $building);
        } else {
            abort_unless(NewBuilding::query()->published()->whereKey($building->id)->exists(), 404);
        }
        if ($parameter = $request->route('unit')) {
            $unit = $parameter instanceof DeveloperUnit ? $parameter : DeveloperUnit::query()->findOrFail($parameter);
            abort_unless((int) $unit->new_building_id === (int) $building->id, 404);
            if ($request->isMethod('GET') && ! $request->is('api/admin/*')) {
                abort_unless(DeveloperUnit::query()->availability(['available', 'reserved', 'sold'])->whereKey($unit->id)->exists(), 404);
            }
        }
        if ($parameter = $request->route('block')) {
            $block = $parameter instanceof NewBuildingBlock ? $parameter : NewBuildingBlock::query()->findOrFail($parameter);
            abort_unless((int) $block->new_building_id === (int) $building->id, 404);
            if ($request->isMethod('GET') && ! $request->is('api/admin/*')) {
                abort_if($block->archived_at, 404);
            }
        }

        return $next($request);
    }
}
