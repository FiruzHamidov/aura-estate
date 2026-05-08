<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivationRewardIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'achievement_id',
        'assignee_id',
        'status',
        'issued_at',
        'comment',
        'meta',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'meta' => 'array',
    ];

    public function achievement()
    {
        return $this->belongsTo(MotivationAchievement::class, 'achievement_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
