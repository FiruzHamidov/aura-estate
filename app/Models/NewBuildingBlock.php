<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class NewBuildingBlock extends Model
{
    protected $fillable = [
        'new_building_id','name','floors_from','floors_to','completion_at',
    ];

    protected $casts = [
        'completion_at' => 'datetime',
    ];

    public function newBuilding(): BelongsTo { return $this->belongsTo(NewBuilding::class); }
    public function units(): HasMany { return $this->hasMany(DeveloperUnit::class, 'block_id'); }
}
