<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyModerationCase extends Model
{
    public const TYPE_INITIAL = 'initial_review';
    public const TYPE_PRICE_INCREASE = 'price_increase';
    public const TYPE_DUPLICATE = 'duplicate_review';
    public const TYPE_CONTENT = 'content_review';
    public const TYPE_APPEAL = 'appeal';

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_WITHDRAWN_BY_AUTHOR = 'withdrawn_by_author';
    public const STATUS_MERGED = 'merged';
    public const STATUS_APPEALED = 'appealed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'property_id', 'type', 'status', 'blocking', 'submitted_by', 'submitted_at',
        'decided_by', 'decided_at', 'decision_comment', 'baseline_snapshot',
        'proposed_snapshot', 'reason_codes', 'parent_case_id', 'version',
    ];

    protected $casts = [
        'blocking' => 'boolean',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'baseline_snapshot' => 'array',
        'proposed_snapshot' => 'array',
        'reason_codes' => 'array',
        'version' => 'integer',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function parentCase()
    {
        return $this->belongsTo(self::class, 'parent_case_id');
    }

    public function duplicateCandidates()
    {
        return $this->hasMany(PropertyDuplicateCandidate::class, 'moderation_case_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
