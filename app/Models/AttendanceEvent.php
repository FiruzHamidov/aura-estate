<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    protected $fillable = [
        'raw_event_id', 'user_id', 'device_id', 'branch_id', 'branch_group_id', 'device_user_id',
        'event_type', 'occurred_at', 'verification_method', 'direction', 'is_duplicate', 'meta',
    ];

    protected $casts = ['occurred_at' => UtcDateTime::class, 'is_duplicate' => 'boolean', 'meta' => 'array'];

    public function rawEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceRawEvent::class, 'raw_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }
}
