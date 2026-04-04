<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_id',
        'type',
        'category',
        'status',
        'priority',
        'channels',
        'title',
        'body',
        'action_url',
        'action_type',
        'dedupe_key',
        'occurrences_count',
        'last_occurred_at',
        'read_at',
        'delivered_at',
        'scheduled_at',
        'subject_type',
        'subject_id',
        'data',
    ];

    protected $casts = [
        'channels' => 'array',
        'data' => 'array',
        'last_occurred_at' => 'datetime',
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }
}
