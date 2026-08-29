<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperUnit extends Model
{
    use \App\Models\Concerns\NormalizesVerificationDate;

    protected $fillable = [
        'new_building_id', 'block_id', 'name', 'bedrooms', 'bathrooms', 'area',
        'floor', 'price_per_sqm', 'total_price', 'description', 'is_available', 'moderation_status', 'window_view',
        'entrance_id', 'layout_id', 'number', 'position_on_floor', 'rooms', 'living_area', 'kitchen_area',
        'ceiling_height', 'finishing', 'publication_status', 'availability_status', 'pricing_basis',
        'price_on_request', 'currency', 'version', 'data_verified_at', 'external_id',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'price_per_sqm' => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_available' => 'boolean',
        'window_view' => 'string',
        'living_area' => 'decimal:2', 'kitchen_area' => 'decimal:2', 'ceiling_height' => 'decimal:2',
        'version' => 'integer', 'rooms' => 'integer', 'price_on_request' => 'boolean', 'data_verified_at' => 'datetime',
    ];

    public function newBuilding(): BelongsTo
    {
        return $this->belongsTo(NewBuilding::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(NewBuildingBlock::class, 'block_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DeveloperUnitPhoto::class, 'unit_id');
    }

    public function coverPhoto(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeveloperUnitPhoto::class, 'unit_id')->ofMany(['is_cover' => 'max', 'sort_order' => 'min', 'id' => 'min']);
    }

    public function entrance(): BelongsTo
    {
        return $this->belongsTo(NewBuildingEntrance::class, 'entrance_id');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(UnitLayout::class, 'layout_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereHas('newBuilding', fn (Builder $q) => $q->published())
            ->where(fn (Builder $q) => $q->whereNull('block_id')->orWhereHas('block', fn (Builder $b) => $b->whereNull('archived_at')))
            ->where(function (Builder $q) {
                $q->where('developer_units.publication_status', 'published')->orWhere(function (Builder $legacy) {
                    $legacy->whereNull('developer_units.publication_status')->whereNull('developer_units.availability_status')
                        ->where(fn (Builder $states) => $states
                            ->where(fn (Builder $available) => $available->whereIn('developer_units.moderation_status', ['available', 'approved'])->where('developer_units.is_available', true))
                            ->orWhere(fn (Builder $closed) => $closed->whereIn('developer_units.moderation_status', ['reserved', 'sold'])->where('developer_units.is_available', false)));
                });
            });
    }

    public function scopeAvailability(Builder $query, array $statuses): Builder
    {
        return $query->published()->where(function (Builder $q) use ($statuses) {
            $q->whereIn('developer_units.availability_status', $statuses)->orWhere(function (Builder $legacy) use ($statuses) {
                $legacy->whereNull('developer_units.availability_status')->whereNull('developer_units.publication_status')
                    ->whereIn('developer_units.moderation_status', in_array('available', $statuses, true) ? [...$statuses, 'approved'] : $statuses);
            });
        });
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->availability(['available']);
    }
}
