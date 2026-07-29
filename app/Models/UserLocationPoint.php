<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationPoint extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'device_id', 'branch_id', 'branch_group_id',
        'latitude', 'longitude', 'accuracy_m', 'altitude_m', 'speed_mps',
        'heading_deg', 'source', 'app_state', 'battery_percent', 'is_mocked',
        'quality', 'meta', 'captured_at', 'received_at',
    ];

    protected $casts = [
        'is_mocked' => 'boolean',
        'meta' => 'array',
        'captured_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
