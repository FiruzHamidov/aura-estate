<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDailyReportReminderSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'enabled',
        'remind_time',
        'timezone',
        'channels',
        'allow_edit_submitted_daily_report',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'channels' => 'array',
        'allow_edit_submitted_daily_report' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
