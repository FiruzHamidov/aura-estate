<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceGlobalSchedule extends Model
{
    protected $fillable = ['timezone', 'schedule', 'configured_by', 'change_reason'];

    protected $casts = ['schedule' => 'array'];

    public function configurator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
