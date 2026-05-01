<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StoryAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'attachable_type',
        'attachable_id',
        'snapshot_json',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}

