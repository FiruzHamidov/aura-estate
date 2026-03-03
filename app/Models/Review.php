<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends Model
{
    /** @use HasFactory<\Database\Factories\ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'author_name',
        'author_phone',
        'author_user_id',
        'rating',
        'text',
        'status',
        'published_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'published_at' => 'datetime',
    ];

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
