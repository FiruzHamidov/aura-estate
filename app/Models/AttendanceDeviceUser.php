<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDeviceUser extends Model
{
    protected $fillable = ['device_id', 'device_user_id', 'user_id', 'card_number', 'is_active', 'mapped_by', 'mapped_at'];

    protected $casts = ['is_active' => 'boolean', 'mapped_at' => 'datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
