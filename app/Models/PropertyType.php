<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyType extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function properties()
    {
        return $this->hasMany(Property::class, 'type_id');
    }
}
