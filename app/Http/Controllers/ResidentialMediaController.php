<?php

namespace App\Http\Controllers;

use App\Models\BuildingFloorPlan;
use App\Models\DeveloperUnit;
use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use App\Models\UnitLayout;
use App\Models\User;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ResidentialMediaController extends Controller
{
    public function show(Request $request, string $kind, int $record, string $variant, ResidentialAccess $access)
    {
        $model = match ($kind) {
            'building-photos' => NewBuildingPhoto::class, 'unit-photos' => DeveloperUnitPhoto::class,
            'layouts' => UnitLayout::class, 'floor-plans' => BuildingFloorPlan::class, default => abort(404),
        };
        $media = $model::query()->findOrFail($record);
        $unit = $media instanceof DeveloperUnitPhoto ? $media->unit : null;
        $building = $unit?->newBuilding ?? $media->newBuilding;
        abort_unless($building, 404);
        if ($request->has('viewer')) {
            abort_unless($request->hasValidSignature(), 403);
            $viewer = User::query()->find($request->integer('viewer'));
            $access->ensureManage($viewer, $building);
        } else {
            abort_unless(NewBuilding::query()->published()->whereKey($building->id)->exists(), 404);
            if ($unit) {
                abort_unless(DeveloperUnit::query()->availability(['available', 'reserved', 'sold'])->whereKey($unit->id)->exists(), 404);
            }
            if ($media instanceof BuildingFloorPlan) {
                abort_unless($building->blocks()->whereNull('archived_at')->whereKey($media->block_id)->exists(), 404);
            }
        }
        $path = $variant === 'original' ? ($media->original_path ?: ($media->path ?? $media->image_path)) : ($media->path ?? $media->image_path);
        if (str_starts_with($variant, 'w')) {
            $path = $media->variants[$variant]['path'] ?? null;
        }
        // Legacy rows may contain unsafe external/arbitrary paths; never use them as filesystem paths.
        abort_unless(is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_contains($path, ':') && ! str_starts_with($path, '/') && ! str_contains($path, '\\'), 404);
        $disk = in_array($media->storage_disk, ['public', 'residential'], true) ? $media->storage_disk : 'public';
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $mime = $storage->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true), 404);

        return $storage->response($path, null, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
