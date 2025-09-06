<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConstructionStage extends Model
{
    protected $fillable = ['name','slug','sort_order','is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function newBuildings(): HasMany
    {
        return $this->hasMany(NewBuilding::class);
    }
}
