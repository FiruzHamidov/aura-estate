<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    public const CONTACT_KIND_BUYER = 'buyer';
    public const CONTACT_KIND_SELLER = 'seller';
    public const CONTACT_KIND_BOTH = 'both';
    public const COLLABORATOR_ROLE_OWNER = 'owner';
    public const COLLABORATOR_ROLE_COLLABORATOR = 'collaborator';
    public const COLLABORATOR_ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'full_name',
        'phone',
        'phone_normalized',
        'email',
        'email_normalized',
        'note',
        'branch_id',
        'branch_group_id',
        'created_by',
        'responsible_agent_id',
        'client_type_id',
        'status_id',
        'source_id',
        'source_comment',
        'contact_kind',
        'status',
        'bitrix_contact_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $appends = [
        'is_business_client',
        'is_buyer_contact',
        'is_seller_contact',
    ];

    public static function contactKinds(): array
    {
        return [
            self::CONTACT_KIND_BUYER,
            self::CONTACT_KIND_SELLER,
            self::CONTACT_KIND_BOTH,
        ];
    }

    public static function collaboratorRoles(): array
    {
        return [
            self::COLLABORATOR_ROLE_OWNER,
            self::COLLABORATOR_ROLE_COLLABORATOR,
            self::COLLABORATOR_ROLE_VIEWER,
        ];
    }

    public static function kindsMatchingFilter(?string $kind): array
    {
        return match ($kind) {
            self::CONTACT_KIND_BUYER => [self::CONTACT_KIND_BUYER, self::CONTACT_KIND_BOTH],
            self::CONTACT_KIND_SELLER => [self::CONTACT_KIND_SELLER, self::CONTACT_KIND_BOTH],
            self::CONTACT_KIND_BOTH => [self::CONTACT_KIND_BOTH],
            default => self::contactKinds(),
        };
    }

    public function mergedContactKindFor(?string $nextKind): string
    {
        $normalizedCurrent = in_array($this->contact_kind, self::contactKinds(), true)
            ? $this->contact_kind
            : self::CONTACT_KIND_BUYER;
        $normalizedNext = in_array($nextKind, self::contactKinds(), true)
            ? $nextKind
            : self::CONTACT_KIND_BUYER;

        if ($normalizedCurrent === $normalizedNext) {
            return $normalizedCurrent;
        }

        if (
            $normalizedCurrent === self::CONTACT_KIND_BOTH
            || $normalizedNext === self::CONTACT_KIND_BOTH
        ) {
            return self::CONTACT_KIND_BOTH;
        }

        return self::CONTACT_KIND_BOTH;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchGroup()
    {
        return $this->belongsTo(BranchGroup::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleAgent()
    {
        return $this->belongsTo(User::class, 'responsible_agent_id');
    }

    public function collaborators()
    {
        return $this->belongsToMany(User::class, 'client_collaborators')
            ->withPivot(['role', 'granted_by'])
            ->withTimestamps();
    }

    public function type()
    {
        return $this->belongsTo(ClientType::class, 'client_type_id');
    }

    public function source()
    {
        return $this->belongsTo(ClientSource::class, 'source_id');
    }

    public function needStatus()
    {
        return $this->belongsTo(ClientNeedStatus::class, 'status_id');
    }

    public function needs()
    {
        return $this->hasMany(ClientNeed::class)->latest('id');
    }

    public function openNeeds()
    {
        return $this->hasMany(ClientNeed::class)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false));
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'crm_client_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class)->latest('id');
    }

    public function ownerProperties()
    {
        return $this->hasMany(Property::class, 'owner_client_id');
    }

    public function buyerProperties()
    {
        return $this->hasMany(Property::class, 'buyer_client_id');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class)->latest('id');
    }

    public function auditLogs()
    {
        return $this->morphMany(CrmAuditLog::class, 'auditable')->latest('id');
    }

    public function getIsBusinessClientAttribute(): bool
    {
        return (bool) ($this->type?->is_business ?? false);
    }

    public function getIsBuyerContactAttribute(): bool
    {
        return in_array($this->contact_kind, [
            self::CONTACT_KIND_BUYER,
            self::CONTACT_KIND_BOTH,
        ], true);
    }

    public function getIsSellerContactAttribute(): bool
    {
        return in_array($this->contact_kind, [
            self::CONTACT_KIND_SELLER,
            self::CONTACT_KIND_BOTH,
        ], true);
    }

    private function hasBuyerSignals(): bool
    {
        return $this->buyerProperties()->exists()
            || $this->bookings()->exists()
            || $this->leads()->exists()
            || $this->needs()->whereHas('type', fn ($query) => $query->whereIn('slug', ['buy', 'rent', 'invest']))->exists();
    }

    private function hasSellerSignals(): bool
    {
        return $this->ownerProperties()->exists()
            || $this->needs()->whereHas('type', fn ($query) => $query->where('slug', 'sell'))->exists();
    }
}
