<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyPromotion extends Model
{
    public const TYPE_VIP = 'vip';
    public const TYPE_URGENT = 'urgent';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'property_id', 'type', 'status', 'requested_by', 'requested_at', 'request_comment',
        'requested_days',
        'decided_by', 'decided_at', 'decision_comment', 'starts_at', 'ends_at',
        'source', 'payment_reference', 'campaign_id', 'revoked_by', 'revoked_at',
        'revoke_reason', 'version',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
        'version' => 'integer',
        'requested_days' => 'integer',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeCurrentlyActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }
}
