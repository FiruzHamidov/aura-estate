<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiTelegramReportConfig extends Model
{
    use HasFactory;

    protected $fillable = ['daily_enabled', 'daily_time', 'weekly_enabled', 'weekly_day', 'weekly_time', 'timezone'];

    protected $casts = [
        'daily_enabled' => 'boolean',
        'weekly_enabled' => 'boolean',
    ];
}
