<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertySocialPromotion extends Model
{
    public const STATUSES = ['recommended', 'content_needed', 'planned', 'published', 'skipped', 'expired'];

    public const CHANNELS = ['instagram', 'facebook', 'telegram', 'other'];

    protected $fillable = [
        'property_id', 'channel', 'status', 'priority_score_snapshot',
        'liquidity_score_snapshot', 'planned_at', 'published_at', 'published_url',
        'assigned_to', 'published_by', 'skip_reason', 'notes', 'metrics',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'published_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
