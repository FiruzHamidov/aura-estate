<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalPropertyRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_TYPE = 'external_agent';

    protected $fillable = [
        'external_agent_id',
        'assigned_agent_id',
        'branch_id',
        'branch_group_id',
        'property_id',
        'owner_client_id',
        'status',
        'offer_type',
        'type_id',
        'location_id',
        'district',
        'address',
        'landmark',
        'price',
        'currency',
        'rooms',
        'total_area',
        'living_area',
        'land_size',
        'floor',
        'total_floors',
        'repair_type_id',
        'condition',
        'owner_name',
        'owner_phone',
        'owner_phone_normalized',
        'external_comment',
        'internal_comment',
        'rejection_reason',
        'needs_info_comment',
        'duplicate_property_id',
        'submitted_at',
        'assigned_at',
        'converted_at',
        'rejected_at',
        'meta',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total_area' => 'decimal:2',
        'living_area' => 'decimal:2',
        'land_size' => 'decimal:2',
        'submitted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'converted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = [
        'display_status',
        'is_closed',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_ASSIGNED,
            self::STATUS_IN_REVIEW,
            self::STATUS_NEEDS_INFO,
            self::STATUS_DUPLICATE,
            self::STATUS_REJECTED,
            self::STATUS_CONVERTED,
            self::STATUS_ARCHIVED,
        ];
    }

    public static function editableByExternalAgentStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_NEEDS_INFO,
        ];
    }

    public static function closedStatuses(): array
    {
        return [
            self::STATUS_CONVERTED,
            self::STATUS_REJECTED,
            self::STATUS_ARCHIVED,
        ];
    }

    public function externalAgent()
    {
        return $this->belongsTo(User::class, 'external_agent_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchGroup()
    {
        return $this->belongsTo(BranchGroup::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function ownerClient()
    {
        return $this->belongsTo(Client::class, 'owner_client_id');
    }

    public function duplicateProperty()
    {
        return $this->belongsTo(Property::class, 'duplicate_property_id');
    }

    public function type()
    {
        return $this->belongsTo(PropertyType::class, 'type_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function repairType()
    {
        return $this->belongsTo(RepairType::class);
    }

    public function photos()
    {
        return $this->hasMany(ExternalPropertyRequestPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(ExternalPropertyRequestLog::class)->latest('created_at')->latest('id');
    }

    public function getIsClosedAttribute(): bool
    {
        return in_array($this->status, self::closedStatuses(), true);
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === self::STATUS_CONVERTED && $this->relationLoaded('property') && $this->property) {
            return match ($this->property->moderation_status) {
                'approved' => 'published',
                'sold', 'rented', 'sold_by_owner' => 'closed_deal',
                'rejected', 'denied' => 'property_rejected',
                default => 'property_created',
            };
        }

        return match ($this->status) {
            self::STATUS_ASSIGNED, self::STATUS_IN_REVIEW => 'in_work',
            default => $this->status,
        };
    }
}
