<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_slug',
        'user_id',
        'branch_id',
        'branch_group_id',
        'metric_key',
        'daily_plan',
        'weight',
        'comment',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'daily_plan' => 'decimal:4',
        'weight' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];
}
