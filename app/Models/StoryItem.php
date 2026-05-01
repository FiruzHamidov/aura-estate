<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'position',
        'media_type',
        'media_url',
        'thumbnail_url',
        'duration_sec',
        'meta',
    ];

    protected $casts = [
        'position' => 'integer',
        'duration_sec' => 'integer',
        'meta' => 'array',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}

