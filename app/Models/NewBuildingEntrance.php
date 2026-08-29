<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewBuildingEntrance extends Model
{
    protected $fillable = ['new_building_id', 'block_id', 'name', 'residential_floor_from', 'residential_floor_to', 'technical_floors', 'sort_order', 'version'];

    protected $casts = ['technical_floors' => 'array', 'version' => 'integer'];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(NewBuildingBlock::class, 'block_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeveloperUnit::class, 'entrance_id');
    }
}
