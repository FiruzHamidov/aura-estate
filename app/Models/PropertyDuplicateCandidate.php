<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyDuplicateCandidate extends Model
{
    public const DECISION_PENDING = 'pending';
    public const DECISION_NOT_DUPLICATE = 'not_duplicate';
    public const DECISION_CONFIRMED = 'confirmed_duplicate';

    protected $fillable = [
        'moderation_case_id', 'candidate_property_id', 'score', 'signals',
        'candidate_snapshot', 'decision', 'decided_by', 'decided_at', 'comment',
        'reversed_at', 'reversed_by', 'reversal_comment',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'signals' => 'array',
        'candidate_snapshot' => 'array',
        'decided_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function moderationCase()
    {
        return $this->belongsTo(PropertyModerationCase::class, 'moderation_case_id');
    }

    public function candidateProperty()
    {
        return $this->belongsTo(Property::class, 'candidate_property_id');
    }
}
