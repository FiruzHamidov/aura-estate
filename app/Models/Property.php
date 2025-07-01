<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'type_id',
        'status_id',
        'location_id',
        'repair_type_id',
        'price',
        'currency',
        'offer_type',
        'rooms',
        'youtube_link',
        'total_area',
        'living_area',
        'floor',
        'total_floors',
        'year_built',
        'condition',
        'apartment_type',
        'has_garden',
        'has_parking',
        'is_mortgage_available',
        'is_from_developer',
        'moderation_status',
        'landmark',
        'latitude',
        'longitude',
        'created_by',
        'agent_id',
        'owner_phone'
    ];

    public function type()
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function status()
    {
        return $this->belongsTo(PropertyStatus::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function repairType()
    {
        return $this->belongsTo(RepairType::class);
    }

    public function photos()
    {
        return $this->hasMany(PropertyPhoto::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
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
