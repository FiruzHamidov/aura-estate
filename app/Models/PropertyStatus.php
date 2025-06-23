<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyStatus extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyStatusFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function properties()
    {
        return $this->hasMany(Property::class, 'status_id');
    }
}
