<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    /** @use HasFactory<\Database\Factories\LocationFactory> */
    use HasFactory;

    protected $fillable = ['city', 'district', 'latitude', 'longitude'];

    public function properties()
    {
        return $this->hasMany(Property::class);
    }
}
