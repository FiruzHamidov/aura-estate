<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use App\Services\Residential\MediaAssets;
use App\Services\Residential\PhotoWriter;
use Illuminate\Http\Request;

class NewBuildingPhotoController extends Controller
{
    public function __construct(private readonly PhotoWriter $writer, private readonly MediaAssets $media) {}

    public function index(Request $request, NewBuilding $new_building)
    {
        $viewer = $request->is('api/admin/*') ? $request->user() : null;

        return $new_building->photos()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($photo) => $this->media->photo($photo, $viewer));
    }

    public function store(Request $request, NewBuilding $new_building)
    {
        $request->validate(['file' => 'required|file']);
        $photos = $this->writer->add($request->user(), $new_building, null, [$request->file('file')], $request->all());

        return response()->json($this->media->photo($photos[0], $request->user()), 201);
    }

    public function destroy(Request $request, NewBuilding $new_building, NewBuildingPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, null, $photo->id, 'delete', $request->all());

        return response()->noContent();
    }

    public function setCover(Request $request, NewBuilding $new_building, NewBuildingPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, null, $photo->id, 'cover', $request->all());

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, NewBuilding $new_building, NewBuildingPhoto $photo)
    {
        $this->writer->change($request->user(), $new_building, null, $photo->id, 'updated', $request->all());

        return $this->media->photo($photo->refresh(), $request->user());
    }

    public function reorder(Request $request, NewBuilding $new_building)
    {
        $request->validate(['orders' => 'required|array']);
        $this->writer->reorder($request->user(), $new_building, null, $request->input('orders'));

        return response()->json(['ok' => true]);
    }
}
