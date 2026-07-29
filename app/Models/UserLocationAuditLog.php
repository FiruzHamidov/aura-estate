<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationAuditLog extends Model
{
    protected $fillable = [
        'actor_user_id', 'target_user_id', 'action', 'meta',
        'ip_address', 'user_agent', 'succeeded',
    ];

    protected $casts = ['meta' => 'array', 'succeeded' => 'boolean'];
}
