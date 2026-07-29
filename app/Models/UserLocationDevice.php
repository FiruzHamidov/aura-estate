<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationDevice extends Model
{
    protected $fillable = [
        'device_uuid', 'user_id', 'platform', 'app_version', 'os_version',
        'permission_status', 'background_permission', 'last_seen_at',
        'last_policy_version', 'revoked_at',
    ];

    protected $casts = [
        'background_permission' => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
