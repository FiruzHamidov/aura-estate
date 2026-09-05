<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTrustEvent extends Model
{
    protected $fillable = [
        'user_id', 'property_id', 'moderation_case_id', 'type', 'points_delta',
        'confirmed_by', 'confirmed_at', 'comment', 'expires_at', 'reverses_event_id',
    ];

    protected $casts = [
        'points_delta' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
