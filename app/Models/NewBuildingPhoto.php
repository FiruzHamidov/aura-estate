<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewBuildingPhoto extends Model
{
    protected $fillable = ['new_building_id','path','is_cover','sort_order'];

    protected $casts = [
        'is_cover' => 'boolean',
    ];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }
}
