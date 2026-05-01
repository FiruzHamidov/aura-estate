<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    use HasFactory;

    public const TYPE_MEDIA = 'media';
    public const TYPE_PROPERTY = 'property';
    public const TYPE_REEL = 'reel';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'caption',
        'starts_at',
        'activated_at',
        'expires_at',
        'views_count',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'views_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoryItem::class)->orderBy('position');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(StoryAttachment::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function scopePublicFeed(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('activated_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}

