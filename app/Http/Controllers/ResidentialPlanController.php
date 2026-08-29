<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Services\Crm\AuditLogger;
use App\Services\Residential\MediaAssets;
use App\Services\Residential\PlanRegions;
use App\Services\Residential\ResidentialAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResidentialPlanController extends Controller
{
    public function masterplan(NewBuilding $new_building, MediaAssets $media)
    {
        abort_unless(NewBuilding::query()->published()->whereKey($new_building->id)->exists(), 404);
        $blocks = $new_building->blocks()->whereNull('archived_at')
            ->select(['id', 'name', 'code', 'completion_at', 'completion_precision', 'completion_year', 'completion_quarter'])
            ->withCount(['units as available_count' => fn ($query) => $query->available()->where('developer_units.new_building_id', $new_building->id)])
            ->orderBy('sort_order')->orderBy('id')->get();
        $allowed = $blocks->pluck('id')->all();

        return ['data' => $new_building->photos()->where('kind', 'masterplan')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($photo) => $media->photo($photo) + [
            'block_regions' => array_values(array_filter($photo->block_regions ?? [], fn ($region) => in_array($region['block_id'], $allowed, true))),
        ]), 'blocks' => $blocks->map(fn ($block) => array_replace($block->toArray(), [
            'completion_at' => $block->completion_at?->toDateString(),
            'available_count' => (int) $block->available_count,
        ]))];
    }

    public function floor(NewBuilding $new_building, DeveloperUnit $unit, MediaAssets $media)
    {
        $unit = $new_building->units()->availability(['available', 'reserved', 'sold'])->findOrFail($unit->id);
        if (! $unit->entrance_id || $unit->floor === null) {
            return ['data' => null];
        }
        $plan = $new_building->floorPlans()->where('block_id', $unit->block_id)->where('entrance_id', $unit->entrance_id)->where('floor_from', '<=', $unit->floor)->where('floor_to', '>=', $unit->floor)->whereNotNull('image_path')->first();
        if (! $plan) {
            return ['data' => null];
        }
        $allowed = $new_building->units()->availability(['available', 'reserved', 'sold'])
            ->where('entrance_id', $unit->entrance_id)->where('floor', $unit->floor)
            ->get(['id', 'number', 'name'])->keyBy('id');

        return ['data' => $plan->only(['id', 'block_id', 'entrance_id', 'floor_from', 'floor_to', 'alt', 'width', 'height']) + [
            'image_url' => $media->url($plan), 'original_url' => $media->url($plan, 'original'),
            'unit_regions' => collect($plan->unit_regions ?? [])
                ->filter(fn ($region) => $allowed->has($region['unit_id']))
                ->map(fn ($region) => [
                    'unit_id' => (int) $region['unit_id'],
                    'points' => $region['points'],
                    'number' => $allowed->get($region['unit_id'])->number,
                    'name' => $allowed->get($region['unit_id'])->name,
                ])->values()->all(),
        ]];
    }

    public function regions(Request $request, NewBuilding $new_building, int $photo, ResidentialAccess $access, PlanRegions $regions, AuditLogger $audit)
    {
        $access->ensureManage($request->user(), $new_building);
        $data = $request->validate(['version' => 'required|integer|min:1', 'block_regions' => 'present|array|max:500']);

        return DB::transaction(function () use ($request, $new_building, $photo, $data, $access, $regions, $audit) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($new_building->id);
            $access->ensureManage($request->user(), $parent);
            $record = $parent->photos()->where('kind', 'masterplan')->lockForUpdate()->findOrFail($photo);
            app(\App\Services\Residential\InventoryWriter::class)->checkVersion($record, $data);
            $old = $record->getAttributes();
            $record->block_regions = $regions->validate($data['block_regions'], 'block_id', $parent->blocks()->whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all());
            $record->version++;
            $record->save();
            $audit->log($record, $request->user(), 'residential.masterplan.regions', $old, $record->getAttributes());
            $oldParent = $parent->getAttributes();
            if (! $access->canPublish($request->user(), $parent) && \App\Services\Residential\InventoryStatus::building($oldParent) === 'published') {
                $parent->publication_status = 'pending';
                $parent->moderation_status = 'pending';
            }
            $parent->version++;
            $parent->save();
            $audit->log($parent, $request->user(), 'residential.media.changed', $oldParent, $parent->getAttributes());

            return $record;
        }, 3);
    }
}
