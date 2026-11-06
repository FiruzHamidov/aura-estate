<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'condition', 'reward_amount', 'reward_currency', 'branch_id', 'branch_group_id', 'version',
        'scope',
        'metric_key',
        'threshold_value',
        'reward_type',
        'name',
        'description',
        'period_type',
        'date_from',
        'date_to',
        'ui_meta',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'condition' => 'array', 'reward_amount' => 'decimal:2', 'version' => 'integer',
        'threshold_value' => 'decimal:4',
        'is_active' => 'boolean',
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'ui_meta' => 'array',
    ];

    public function achievements()
    {
        return $this->hasMany(MotivationAchievement::class, 'rule_id');
    }
}
