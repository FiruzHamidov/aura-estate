<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientNeed extends Model
{
    use HasFactory, SoftDeletes;

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
}
