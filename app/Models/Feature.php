<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['name','slug'];

    public function newBuildings(): BelongsToMany
    {
        return $this->belongsToMany(NewBuilding::class, 'feature_new_building')
            ->withTimestamps();
    }
}
