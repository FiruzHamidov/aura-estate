<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiPeriodLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_type',
        'period_key',
        'branch_id',
        'branch_group_id',
        'locked_by',
        'locked_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
    ];
}
