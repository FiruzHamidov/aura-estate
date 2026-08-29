<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewBuildingBlock extends Model
{
    protected $fillable = [
        'new_building_id', 'name', 'floors_from', 'floors_to', 'completion_at',
        'code', 'completion_precision', 'completion_year', 'completion_quarter', 'construction_stage_id', 'sort_order', 'archived_at', 'version',
    ];

    protected $casts = [
        'completion_at' => 'date:Y-m-d',
        'archived_at' => 'datetime', 'version' => 'integer',
    ];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeveloperUnit::class, 'block_id');
    }

    public function entrances(): HasMany
    {
        return $this->hasMany(NewBuildingEntrance::class, 'block_id');
    }
}
