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
        'repair_type_id', // заменено
        'price',
        'currency',
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
        'landmark',
        'moderation_status',
        'latitude',
        'longitude',
        'created_by',
    ];

    /**
     * Связь с типом недвижимости
     */
    public function type()
    {
        return $this->belongsTo(PropertyType::class, 'type_id');
    }

    /**
     * Связь со статусом недвижимости
     */
    public function status()
    {
        return $this->belongsTo(PropertyStatus::class, 'status_id');
    }

    /**
     * Связь с локацией
     */
    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Связь с типом ремонта
     */
    public function repairType()
    {
        return $this->belongsTo(RepairType::class, 'repair_type_id');
    }

    /**
     * Связь с фото
     */
    public function photos()
    {
        return $this->hasMany(PropertyPhoto::class);
    }

    /**
     * Связь с создателем объекта
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Валютный символ для отображения
     */
    public function getCurrencySymbolAttribute(): string
    {
        return match ($this->currency) {
            'USD' => '$',
            'TJS' => 'смн',
            default => $this->currency,
        };
    }
}
