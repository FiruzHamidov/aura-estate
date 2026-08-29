<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class Property extends Model
{
    use HasFactory;

    public const LISTING_CONTENT_FIELDS = [
        'title',
        'description',
        'type_id',
        'status_id',
        'location_id',
        'repair_type_id',
        'contract_type_id',
        'document_type_id',
        'price',
        'discount_price',
        'currency',
        'offer_type',
        'rooms',
        'youtube_link',
        'instagram_link',
        'total_area',
        'land_size',
        'living_area',
        'floor',
        'total_floors',
        'year_built',
        'condition',
        'construction_status',
        'renovation_permission_status',
        'apartment_type',
        'has_garden',
        'has_parking',
        'is_mortgage_available',
        'is_from_developer',
        'landmark',
        'latitude',
        'longitude',
        'district',
        'address',
        'listing_type',
        'developer_id',
        'is_full_apartment',
        'is_for_aura',
        'parking_type_id',
        'heating_type_id',
    ];

    public const PUBLIC_MODERATION_STATUS = 'approved';

    public const CLOSED_MODERATION_STATUSES = [
        'closed',
        'sold',
        'rented',
        'archived',
        'inactive',
        'completed',
        'deleted',
        'rejected',
        'denied',
        'draft',
        'sold_by_owner',
    ];

    public const CLOSED_STATUS_SLUGS = [
        'closed',
        'sold',
        'rented',
        'archived',
        'inactive',
        'completed',
        'sold_by_owner',
    ];

    protected $appends = [
        'branch_group_id',
        'listing_updated_at',
        'public_price_badge',
    ];

    protected $casts = [
        'listing_updated_at' => 'datetime',
        'listed_at' => 'datetime',
        'liquidity_calculated_at' => 'datetime',
        'liquidity_score' => 'integer',
        'liquidity_confidence' => 'integer',
        'price_delta_pct' => 'decimal:2',
        'promotion_priority_score' => 'integer',
        'liquidity_business_priority' => 'boolean',
        'liquidity_business_priority_at' => 'datetime',
    ];

    protected $hidden = [
        'effective_price',
        'liquidity_score',
        'liquidity_category',
        'liquidity_confidence',
        'price_position',
        'price_delta_pct',
        'promotion_priority_score',
        'promotion_eligibility',
        'liquidity_business_priority',
        'liquidity_business_priority_comment',
        'liquidity_business_priority_by',
        'liquidity_business_priority_at',
        'liquidity_calculated_at',
        'liquidity_model_version',
    ];

    protected $fillable = [
        'title',
        'description',
        'type_id',
        'status_id',
        'location_id',
        'repair_type_id',
        'price',
        'discount_price',
        'currency',
        'offer_type',
        'rooms',
        'youtube_link',
        'instagram_link',
        'total_area',
        'land_size',
        'living_area',
        'floor',
        'total_floors',
        'year_built',
        'condition',
        'construction_status',
        'renovation_permission_status',
        'apartment_type',
        'has_garden',
        'has_parking',
        'is_mortgage_available',
        'is_from_developer',
        'moderation_status',
        'landmark',
        'latitude',
        'longitude',
        'created_by',
        'agent_id',
        'co_owner_user_id',
        'external_agent_id',
        'external_property_request_id',
        'source_type',
        'branch_id',
        'branch_group_id',
        'district',
        'district_id',
        'address',
        'owner_phone',
        'listing_type',
        'contract_type_id',
        'document_type_id',
        'views_count',
        'owner_name',
        'owner_client_id',
        'object_key',
        'is_business_owner',
        'developer_id',
        'is_full_apartment',
        'is_for_aura',
        'parking_type_id',
        'heating_type_id',
        'rejection_comment',
        'status_comment',
        'sold_at',
        'sale_user_id',
        'actual_sale_price',
        'actual_sale_currency',
        'company_commission_amount',
        'company_commission_currency',
        'money_holder',
        'money_received_at',
        'contract_signed_at',
        'deposit_amount',
        'deposit_currency',
        'deposit_received_at',
        'deposit_taken_at',
        'deposit_user_id',
        'buyer_full_name',
        'buyer_phone',
        'buyer_client_id',
        'company_expected_income',
        'company_expected_income_currency',
        'planned_contract_signed_at',
        'listed_at',
        'liquidity_score',
        'liquidity_category',
        'liquidity_confidence',
        'price_position',
        'price_delta_pct',
        'promotion_priority_score',
        'promotion_eligibility',
        'liquidity_business_priority',
        'liquidity_business_priority_comment',
        'liquidity_business_priority_by',
        'liquidity_business_priority_at',
        'liquidity_calculated_at',
        'liquidity_model_version',
    ];

    protected static function booted(): void
    {
        static::creating(function (Property $property): void {
            if (
                Schema::hasColumn($property->getTable(), 'listing_updated_at')
                && empty($property->getAttributes()['listing_updated_at'])
            ) {
                $property->setAttribute('listing_updated_at', $property->created_at ?? now());
            }

            if (
                Schema::hasColumn($property->getTable(), 'listed_at')
                && empty($property->getAttributes()['listed_at'])
                && $property->moderation_status === self::PUBLIC_MODERATION_STATUS
            ) {
                $property->setAttribute('listed_at', $property->created_at ?? now());
            }
        });
    }

    public function getListingUpdatedAtAttribute($value)
    {
        $rawValue = $this->attributes['listing_updated_at'] ?? $value;

        return $rawValue !== null
            ? $this->asDateTime($rawValue)
            : $this->created_at;
    }

    public function markListingUpdated($at = null): bool
    {
        if (! Schema::hasColumn($this->getTable(), 'listing_updated_at')) {
            return false;
        }

        $this->setAttribute('listing_updated_at', $at ?? now());

        if (! $this->save()) {
            return false;
        }

        $this->refresh();

        return true;
    }

    public function type()
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function buildingType()
    {
        return $this->belongsTo(BuildingType::class, 'status_id');
    }

    public function parking()
    {
        return $this->belongsTo(ParkingType::class, 'parking_type_id');
    }

    public function heating()
    {
        return $this->belongsTo(HeatingType::class, 'heating_type_id');
    }

    public function status()
    {
        return $this->belongsTo(PropertyStatus::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function districtRelation()
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function liquiditySnapshots()
    {
        return $this->hasMany(PropertyLiquiditySnapshot::class);
    }

    public function latestLiquiditySnapshot()
    {
        return $this->hasOne(PropertyLiquiditySnapshot::class)->latestOfMany('calculated_at');
    }

    public function socialPromotions()
    {
        return $this->hasMany(PropertySocialPromotion::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(PropertyStatusHistory::class)->orderBy('changed_at');
    }

    public function publicPriceBadge(): ?array
    {
        if (
            $this->price_position !== 'below_market'
            || (int) $this->liquidity_score < (int) config('property-liquidity.liquid_score_threshold', 65)
            || (int) $this->liquidity_confidence < (int) config('property-liquidity.public_badge_minimum_confidence', 45)
        ) {
            return null;
        }

        return [
            'code' => 'below_market',
            'label' => 'Выгодная цена',
            'tooltip' => 'Цена за м² ниже, чем у похожих объектов этого типа в данном районе.',
            'explanation' => 'Платформа сравнила цену за квадратный метр с похожими активными объектами того же типа в данном районе: учитывались площадь и применимые характеристики объекта. Это информационная оценка на основе доступных объявлений, а не гарантия продажи или официальная оценка стоимости.',
        ];
    }

    public function getPublicPriceBadgeAttribute(): ?array
    {
        return $this->publicPriceBadge();
    }

    public function repairType()
    {
        return $this->belongsTo(RepairType::class);
    }

    public function photos()
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('position');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_property')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'property_tag')
            ->withTimestamps();
    }

    public function reels()
    {
        return $this->hasMany(Reel::class)->orderBy('sort_order')->orderByDesc('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function coOwner()
    {
        return $this->belongsTo(User::class, 'co_owner_user_id');
    }

    public function externalAgent()
    {
        return $this->belongsTo(User::class, 'external_agent_id');
    }

    public function externalPropertyRequest()
    {
        return $this->belongsTo(ExternalPropertyRequest::class, 'external_property_request_id');
    }

    public function ownerClient()
    {
        return $this->belongsTo(Client::class, 'owner_client_id');
    }

    public function buyerClient()
    {
        return $this->belongsTo(Client::class, 'buyer_client_id');
    }

    public function depositUser()
    {
        return $this->belongsTo(User::class, 'deposit_user_id');
    }

    public function saleUser()
    {
        return $this->belongsTo(User::class, 'sale_user_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'primary_property_id')->latest('id');
    }

    public function getCurrencySymbolAttribute(): string
    {
        return match ($this->currency) {
            'USD' => '$',
            'TJS' => 'смн',
            default => $this->currency,
        };
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function contractType()
    {
        return $this->belongsTo(ContractType::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function logs()
    {
        return $this->hasMany(PropertyLog::class)->latest();
    }

    public function developer()
    {
        return $this->belongsTo(Developer::class);
    }

    public function saleAgents()
    {
        return $this->belongsToMany(User::class, 'property_agent_sales', 'property_id', 'agent_id')
            ->withPivot([
                'role',
                'agent_commission_amount',
                'agent_commission_currency',
                'agent_paid_at',
            ])
            ->withTimestamps();
    }

    public function getActualSaleCurrencySymbolAttribute(): string
    {
        return match ($this->actual_sale_currency) {
            'USD' => '$',
            'TJS' => 'смн',
            default => $this->actual_sale_currency,
        };
    }

    public function getCompanyCommissionCurrencySymbolAttribute(): string
    {
        return match ($this->company_commission_currency) {
            'USD' => '$',
            'TJS' => 'смн',
            default => $this->company_commission_currency,
        };
    }

    public function scopeSold($query)
    {
        return $query->whereNotNull('sold_at')
            ->whereIn('moderation_status', ['sold', 'rented', 'sold_by_owner']);
    }

    public function scopePublicSearchable(Builder $query): Builder
    {
        $query
            ->where('properties.moderation_status', self::PUBLIC_MODERATION_STATUS)
            ->whereNotIn('properties.moderation_status', self::CLOSED_MODERATION_STATUSES);

        if (Schema::hasColumn('properties', 'sold_at')) {
            $query->whereNull('properties.sold_at');
        }

        if (Schema::hasTable('property_statuses')) {
            $query->whereDoesntHave('status', function (Builder $statusQuery) {
                $statusQuery->whereIn('slug', self::CLOSED_STATUS_SLUGS);
            });
        }

        return $query;
    }

    public function isDealClosed(): bool
    {
        return ! is_null($this->sold_at);
    }

    public function getBranchGroupIdAttribute($value): ?int
    {
        $ownBranchGroupId = $this->normalizeBranchGroupId(
            $value ?? ($this->attributes['branch_group_id'] ?? null)
        );

        if ($ownBranchGroupId !== null) {
            return $ownBranchGroupId;
        }

        return $this->resolveUserBranchGroupId(
            $this->agent_id,
            $this->relationLoaded('agent') ? $this->agent : null
        )
            ?? $this->resolveUserBranchGroupId(
                $this->created_by,
                $this->relationLoaded('creator') ? $this->creator : null
            )
            ?? $this->resolveUserBranchGroupId(
                $this->relationLoaded('creator') ? $this->creator?->id : null,
                $this->relationLoaded('creator') ? $this->creator : null
            );
    }

    private function resolveUserBranchGroupId($userId, ?User $loadedUser = null): ?int
    {
        if ($loadedUser && (int) $loadedUser->id === (int) $userId) {
            $branchGroupId = $this->normalizeBranchGroupId(
                $loadedUser->getAttributes()['branch_group_id'] ?? null
            );

            if ($branchGroupId !== null) {
                return $branchGroupId;
            }
        }

        if (empty($userId) || ! Schema::hasColumn('users', 'branch_group_id')) {
            return null;
        }

        return $this->normalizeBranchGroupId(
            User::query()->whereKey($userId)->value('branch_group_id')
        );
    }

    private function normalizeBranchGroupId($branchGroupId): ?int
    {
        if ($branchGroupId === null || $branchGroupId === '') {
            return null;
        }

        return (int) $branchGroupId;
    }
}
