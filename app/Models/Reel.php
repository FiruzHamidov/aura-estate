<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reel extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const TRANSCODE_PENDING = 'pending';
    public const TRANSCODE_QUEUED = 'queued';
    public const TRANSCODE_PROCESSING = 'processing';
    public const TRANSCODE_COMPLETED = 'completed';
    public const TRANSCODE_FAILED = 'failed';

    protected $fillable = [
        'property_id',
        'created_by',
        'title',
        'description',
        'video_url',
        'hls_url',
        'mp4_url',
        'preview_image',
        'thumbnail_url',
        'duration',
        'aspect_ratio',
        'status',
        'sort_order',
        'is_featured',
        'views_count',
        'likes_count',
        'video_size',
        'mime_type',
        'transcode_status',
        'processing_meta',
        'poster_second',
        'published_at',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'processing_meta' => 'array',
        'published_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function likes()
    {
        return $this->hasMany(ReelLike::class);
    }

    public function likedByUsers()
    {
        return $this->belongsToMany(User::class, 'reel_likes')->withTimestamps();
    }

    public function scopeStandalone(Builder $query): Builder
    {
        return $query->whereNull('property_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('transcode_status', self::TRANSCODE_COMPLETED)
            ->whereNotNull('published_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function canBePublished(): bool
    {
        return $this->transcode_status === self::TRANSCODE_COMPLETED
            && (!empty($this->hls_url) || !empty($this->mp4_url) || !empty($this->video_url));
    }
}
