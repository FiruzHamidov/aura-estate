<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewBuildingPhoto extends Model
{
    protected $fillable = ['new_building_id', 'path', 'is_cover', 'sort_order', 'storage_disk', 'original_path', 'width', 'height', 'kind', 'alt', 'version', 'block_regions', 'variants'];

    protected $casts = [
        'variants' => 'array',
        'is_cover' => 'boolean',
        'version' => 'integer', 'block_regions' => 'array',
    ];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }
}
