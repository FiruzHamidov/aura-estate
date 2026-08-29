<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuilding;
use App\Services\Residential\MediaAssets;
use App\Services\Residential\PhotoWriter;
use Illuminate\Http\Request;

class DeveloperUnitPhotoController extends Controller
{
    public function __construct(private readonly PhotoWriter $writer, private readonly MediaAssets $media) {}

    public function index(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        $viewer = $request->is('api/admin/*') ? $request->user() : null;

        return $unit->photos()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($photo) => $this->media->photo($photo, $viewer));
    }

    public function store(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        $files = $request->file('photo') ?? $request->file('photos') ?? [];
        $files = is_array($files) ? $files : [$files];
        $photos = $this->writer->add($request->user(), $new_building, $unit, $files, $request->all());

        return response()->json(array_map(fn ($photo) => $this->media->photo($photo, $request->user()), $photos), 201);
    }

    public function destroy(Request $request, NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, $unit, $photo->id, 'delete', $request->all());

        return response()->noContent();
    }

    public function update(Request $request, NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, $unit, $photo->id, 'updated', $request->all());

        return $this->media->photo($photo->refresh(), $request->user());
    }

    public function reorder(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        // Preserve both historical payloads as well as canonical orders.
        if ($request->has('orders')) {
            $request->validate(['orders' => 'array']);
            $orders = $request->input('orders');
        } elseif ($request->has('photo_order')) {
            $request->validate(['photo_order' => 'array']);
            $orders = [];
            foreach ($request->input('photo_order') as $index => $id) {
                $orders[] = ['id' => $id, 'sort_order' => $index];
            }
        } else {
            $request->validate(['photo_positions' => 'required|array']);
            $orders = [];
            foreach ($request->input('photo_positions') as $id => $position) {
                $orders[] = is_array($position)
                    ? ['id' => $position['id'] ?? null, 'sort_order' => $position['position'] ?? null]
                    : ['id' => $id, 'sort_order' => $position];
            }
        }
        $this->writer->reorder($request->user(), $new_building, $unit, $orders);

        return $unit->photos()->orderBy('sort_order')->get()->map(fn ($photo) => $this->media->photo($photo, $request->user()));
    }

    public function setCover(Request $request, NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, $unit, $photo->id, 'cover', $request->all());

        return $unit->photos()->orderBy('sort_order')->get()->map(fn ($photo) => $this->media->photo($photo, $request->user()));
    }
}
