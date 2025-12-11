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
        'land_size',
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
        'district',
        'address',
        'owner_phone',
        'listing_type',
        'contract_type_id',
        'views_count',
        'owner_name',
        'object_key',
        'is_business_owner',
        'developer_id',
        'is_full_apartment',
        'is_for_aura',
        'parking_type_id',
        'heating_type_id',
        'rejection_comment',
        'status_comment',
    ];

    public function type()
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function buildingType()
    {
        return $this->belongsTo(BuildingType::class, 'status_id');
    }

    public function parking()
    {
        return $this->belongsTo(ParkingType::class, 'parking_type_id');
    }

    public function heating()
    {
        return $this->belongsTo(HeatingType::class, 'heating_type_id');
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
        return $this->hasMany(PropertyPhoto::class)->orderBy('position');
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

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function contractType()
    {
        return $this->belongsTo(ContractType::class);
    }

    public function logs()
    {
        return $this->hasMany(PropertyLog::class)->latest();
    }

    public function developer()
    {
        return $this->belongsTo(Developer::class);
    }
}
