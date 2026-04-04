<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class ClientNeed extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = [
        'property_type_ids',
    ];

    protected $fillable = [
        'client_id',
        'type_id',
        'status_id',
        'budget_from',
        'budget_to',
        'currency',
        'location_id',
        'district',
        'property_type_id',
        'rooms_from',
        'rooms_to',
        'area_from',
        'area_to',
        'comment',
        'created_by',
        'responsible_agent_id',
        'closed_at',
        'meta',
    ];

    protected $casts = [
        'budget_from' => 'decimal:2',
        'budget_to' => 'decimal:2',
        'area_from' => 'decimal:2',
        'area_to' => 'decimal:2',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function type()
    {
        return $this->belongsTo(ClientNeedType::class, 'type_id');
    }

    public function status()
    {
        return $this->belongsTo(ClientNeedStatus::class, 'status_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleAgent()
    {
        return $this->belongsTo(User::class, 'responsible_agent_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function propertyType()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    public function propertyTypes()
    {
        return $this->belongsToMany(PropertyType::class, 'client_need_property_type')
            ->withTimestamps();
    }

    public function getPropertyTypeIdsAttribute(): array
    {
        if ($this->relationLoaded('propertyTypes')) {
            $ids = $this->propertyTypes
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }

            return $this->property_type_id ? [(int) $this->property_type_id] : [];
        }

        static $hasPropertyTypePivotTable;
        $hasPropertyTypePivotTable ??= Schema::hasTable('client_need_property_type');

        if (!$hasPropertyTypePivotTable) {
            return $this->property_type_id ? [(int) $this->property_type_id] : [];
        }

        $ids = $this->propertyTypes()
            ->pluck('property_types.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        return $this->property_type_id ? [(int) $this->property_type_id] : [];
    }
}
