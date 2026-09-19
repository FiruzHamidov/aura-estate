<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDailySummary extends Model
{
    use \App\Models\Concerns\ProjectsRopRelations;

    protected $fillable = [
        'role_slug', 'schedule_snapshot',
        'branch_group_id',
        'user_id', 'work_date', 'first_in_at', 'last_out_at', 'first_event_id', 'last_event_id',
        'events_count', 'device_ids', 'worked_minutes', 'late_minutes', 'status',
    ];

    protected $casts = [
        'schedule_snapshot' => 'array',
        'work_date' => DateOnly::class,
        'first_in_at' => UtcDateTime::class,
        'last_out_at' => UtcDateTime::class,
        'device_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
