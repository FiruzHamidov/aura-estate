<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationTrackingSetting extends Model
{
    protected $fillable = [
        'user_id', 'tracking_enabled', 'mode', 'timezone', 'schedule',
        'foreground_interval_sec', 'background_interval_sec', 'min_distance_m',
        'history_retention_days', 'require_background_permission', 'policy_version',
        'configured_by', 'change_reason',
    ];

    protected $casts = [
        'tracking_enabled' => 'boolean',
        'schedule' => 'array',
        'require_background_permission' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
