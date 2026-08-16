<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceAuditLog extends Model
{
    protected $fillable = [
        'actor_user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values',
        'ip_address', 'user_agent',
    ];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];
}
