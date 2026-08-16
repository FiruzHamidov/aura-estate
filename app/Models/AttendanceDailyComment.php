<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceDailyComment extends Model
{
    protected $fillable = [
        'user_id', 'work_date', 'comment', 'created_by', 'updated_by', 'version',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
        'version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
