<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'type_id',
        'status_id',
        'location_id',
        'price',
        'currency',
        'total_area',
        'living_area',
        'floor',
        'total_floors',
        'year_built',
        'condition',
        'apartment_type',
        'repair_type',
        'has_garden',
        'has_parking',
        'is_mortgage_available',
        'is_from_developer',
        'landmark',
        'moderation_status',
        'created_by',
    ];

    public function type()
    {
        return $this->belongsTo(PropertyType::class, 'type_id');
    }

    public function status()
    {
        return $this->belongsTo(PropertyStatus::class, 'status_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function photos()
    {
        return $this->hasMany(PropertyPhoto::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCurrencySymbolAttribute(): string
    {
        return match ($this->currency) {
            'USD' => '$',
            'TJS' => 'смн',
            default => $this->currency,
        };
    }
}
