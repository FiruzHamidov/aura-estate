<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyPhoto extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyPhotoFactory> */
    use HasFactory;

    protected $fillable = ['property_id', 'file_path', 'type'];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
