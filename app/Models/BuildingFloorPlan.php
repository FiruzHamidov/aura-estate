<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildingFloorPlan extends Model
{
    protected $fillable = ['new_building_id', 'block_id', 'entrance_id', 'floor_from', 'floor_to', 'image_path', 'alt', 'unit_regions', 'version', 'storage_disk', 'original_path', 'width', 'height', 'variants'];

    protected $casts = ['unit_regions' => 'array', 'version' => 'integer', 'variants' => 'array'];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function entrance(): BelongsTo
    {
        return $this->belongsTo(NewBuildingEntrance::class, 'entrance_id');
    }
}
