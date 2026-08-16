<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceRawEvent extends Model
{
    protected $fillable = [
        'device_id', 'ingest_request_id', 'event_hash', 'device_user_id', 'occurred_at_local', 'occurred_at_utc',
        'attendance_status', 'verify_mode', 'work_code', 'raw_payload', 'request_meta',
        'source_ip', 'received_at', 'processing_status', 'processing_error',
    ];

    protected $casts = [
        'occurred_at_local' => 'datetime',
        'occurred_at_utc' => UtcDateTime::class,
        'received_at' => UtcDateTime::class,
        'request_meta' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function event(): HasOne
    {
        return $this->hasOne(AttendanceEvent::class, 'raw_event_id');
    }
}
