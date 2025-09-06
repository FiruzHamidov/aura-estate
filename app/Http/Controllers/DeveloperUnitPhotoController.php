<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use Illuminate\Http\Request;

class DeveloperUnitPhotoController extends Controller
{
    public function index(DeveloperUnit $new_building) { return $new_building->photos()->orderBy('sort_order')->get(); }
    public function store(Request $r, DeveloperUnit $new_building) {
        $data = $r->validate(['path'=>'required|string','is_cover'=>'boolean','sort_order'=>'integer']);
        if (!empty($data['is_cover'])) { $new_building->photos()->update(['is_cover'=>false]); }
        return $new_building->photos()->create($data);
    }
    public function destroy(\App\Models\DeveloperUnitPhoto $photo) { $photo->delete(); return response()->noContent(); }
}
