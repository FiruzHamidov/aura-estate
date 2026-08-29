<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $fillable = ['location_id', 'name', 'slug', 'aliases', 'is_active'];

    protected $casts = [
        'aliases' => 'array',
        'is_active' => 'boolean',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function properties()
    {
        return $this->hasMany(Property::class);
    }
}
