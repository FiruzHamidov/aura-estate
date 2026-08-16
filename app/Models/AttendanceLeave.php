<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceLeave extends Model
{
    protected $fillable = ['user_id', 'date_from', 'date_to', 'note', 'created_by'];

    protected $casts = ['date_from' => DateOnly::class, 'date_to' => DateOnly::class];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
