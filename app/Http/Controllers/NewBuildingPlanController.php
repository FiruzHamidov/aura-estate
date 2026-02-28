<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewBuildingPlanController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;
    private const CURRENCY = 'TJS';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'developer_id' => ['nullable', 'integer', 'exists:developers,id'],
            'stage_id' => ['nullable', 'integer', 'exists:construction_stages,id'],
            'material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'search' => ['nullable', 'string'],
            'ceiling_height_min' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'ceiling_height_max' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'sort' => ['nullable', 'in:title,building_title,building_address,price,min_price,area,rooms,created_at'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $dir = $validated['dir'] ?? 'desc';
        $sort = $validated['sort'] ?? 'created_at';

        $coverPhotoSubquery = DB::table('developer_unit_photos')
            ->select(
                'unit_id',
                DB::raw('COALESCE(MAX(CASE WHEN is_cover = 1 THEN path END), MIN(path)) as cover_photo')
            )
            ->groupBy('unit_id');

        $sortColumns = [
            'title' => 'developer_units.name',
            'building_title' => 'new_buildings.title',
            'building_address' => 'new_buildings.address',
            'price' => 'developer_units.total_price',
            'min_price' => 'developer_units.total_price',
            'area' => 'developer_units.area',
            'rooms' => 'developer_units.bedrooms',
            'created_at' => 'developer_units.created_at',
        ];

        $paginator = DeveloperUnit::query()
            ->join('new_buildings', 'new_buildings.id', '=', 'developer_units.new_building_id')
            ->leftJoinSub($coverPhotoSubquery, 'unit_cover_photos', function ($join) {
                $join->on('unit_cover_photos.unit_id', '=', 'developer_units.id');
            })
            ->where('new_buildings.moderation_status', 'approved')
            ->where('developer_units.is_available', true)
            ->whereIn('developer_units.moderation_status', ['approved', 'available'])
            ->when(isset($validated['developer_id']), fn ($query) => $query->where('new_buildings.developer_id', $validated['developer_id']))
            ->when(isset($validated['stage_id']), fn ($query) => $query->where('new_buildings.construction_stage_id', $validated['stage_id']))
            ->when(isset($validated['material_id']), fn ($query) => $query->where('new_buildings.material_id', $validated['material_id']))
            ->when(!empty($validated['search']), function ($query) use ($validated) {
                $search = trim($validated['search']);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('developer_units.name', 'like', '%'.$search.'%')
                        ->orWhere('new_buildings.title', 'like', '%'.$search.'%')
                        ->orWhere('new_buildings.address', 'like', '%'.$search.'%');
                });
            })
            ->when(isset($validated['ceiling_height_min']), fn ($query) => $query->where('new_buildings.ceiling_height', '>=', (float) $validated['ceiling_height_min']))
            ->when(isset($validated['ceiling_height_max']), fn ($query) => $query->where('new_buildings.ceiling_height', '<=', (float) $validated['ceiling_height_max']))
            ->orderBy($sortColumns[$sort], $dir)
            ->orderBy('developer_units.id')
            ->select([
                'developer_units.id',
                'developer_units.new_building_id',
                'developer_units.name',
                'developer_units.bedrooms',
                'developer_units.area',
                'developer_units.total_price',
                'developer_units.created_at',
                'new_buildings.title',
                'new_buildings.address',
                'new_buildings.latitude',
                'new_buildings.longitude',
                'unit_cover_photos.cover_photo',
            ])
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($plan) => [
                'unit_id' => (int) $plan->id,
                'building_id' => (int) $plan->new_building_id,
                'building_title' => $plan->title,
                'building_address' => $plan->address,
                'building_latitude' => is_null($plan->latitude) ? null : (float) $plan->latitude,
                'building_longitude' => is_null($plan->longitude) ? null : (float) $plan->longitude,
                'rooms' => is_null($plan->bedrooms) ? null : (int) $plan->bedrooms,
                'area' => is_null($plan->area) ? null : (float) $plan->area,
                'price' => is_null($plan->total_price) ? null : (float) $plan->total_price,
                'currency' => self::CURRENCY,
                'cover_photo' => $plan->cover_photo,
            ])->values(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
