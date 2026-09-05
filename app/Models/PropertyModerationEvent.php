<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyModerationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'property_id', 'moderation_case_id', 'event_type', 'actor_id', 'actor_role',
        'payload', 'request_id', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
