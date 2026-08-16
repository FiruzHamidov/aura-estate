<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWorkSchedule extends Model
{
    protected $fillable = ['user_id', 'timezone', 'schedule', 'holidays', 'configured_by', 'change_reason'];

    protected $casts = ['schedule' => 'array', 'holidays' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
