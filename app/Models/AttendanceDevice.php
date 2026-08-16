<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    protected $fillable = [
        'name', 'serial_number', 'branch_id', 'branch_group_id', 'protocol', 'timezone',
        'firmware_version', 'platform', 'device_model', 'communication_key', 'is_active',
        'last_seen_at', 'last_event_at', 'clock_drift_seconds', 'offline_notified_at', 'last_ip', 'last_error',
    ];

    protected $hidden = ['communication_key'];

    protected $casts = [
        'communication_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_event_at' => UtcDateTime::class,
        'offline_notified_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchGroup(): BelongsTo
    {
        return $this->belongsTo(BranchGroup::class);
    }

    public function userMappings(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class, 'device_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'device_id');
    }
}
