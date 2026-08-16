<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceIngestRequest extends Model
{
    protected $fillable = [
        'device_id', 'payload_hash', 'raw_payload', 'request_meta', 'source_ip', 'received_at',
        'processing_status', 'accepted_count', 'duplicate_count', 'unmapped_count', 'rejected_rows',
    ];

    protected $casts = [
        'request_meta' => 'array',
        'rejected_rows' => 'array',
        'received_at' => UtcDateTime::class,
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }
}
