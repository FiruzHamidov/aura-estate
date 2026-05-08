<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivationAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'user_id',
        'company_scope',
        'won_at',
        'period_type',
        'date_from',
        'date_to',
        'snapshot_value',
        'status',
        'approved_by',
        'approved_at',
        'issued_by',
        'issued_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'meta',
    ];

    protected $casts = [
        'company_scope' => 'boolean',
        'won_at' => 'datetime',
        'date_from' => 'date',
        'date_to' => 'date',
        'snapshot_value' => 'decimal:4',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function rule()
    {
        return $this->belongsTo(MotivationRule::class, 'rule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rewardIssue()
    {
        return $this->hasOne(MotivationRewardIssue::class, 'achievement_id');
    }
}
