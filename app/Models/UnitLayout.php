<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitLayout extends Model
{
    protected $fillable = ['new_building_id', 'code', 'rooms', 'typical_area', 'image_path', 'alt', 'version', 'storage_disk', 'original_path', 'width', 'height', 'variants'];

    protected $casts = ['rooms' => 'integer', 'typical_area' => 'decimal:2', 'version' => 'integer', 'variants' => 'array'];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeveloperUnit::class, 'layout_id');
    }
}
