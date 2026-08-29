<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewBuilding extends Model
{
    use \App\Models\Concerns\NormalizesVerificationDate;

    protected $fillable = [
        'title', 'description', 'developer_id', 'construction_stage_id', 'material_id',
        'location_id', 'installment_available', 'heating', 'has_terrace',
        'floors_range', 'completion_at', 'address', 'latitude', 'longitude',
        'moderation_status', 'created_by', 'district',
        'ceiling_height',
        'publication_status', 'branch_id', 'responsible_agent_id', 'data_verified_at', 'published_at',
        'version', 'housing_class', 'advantages', 'parking', 'completion_precision', 'completion_year', 'completion_quarter',
    ];

    protected $casts = [
        'installment_available' => 'boolean',
        'heating' => 'boolean',
        'has_terrace' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'completion_at' => 'date:Y-m-d',
        'ceiling_height' => 'decimal:2',
        'data_verified_at' => 'datetime', 'published_at' => 'datetime', 'advantages' => 'array', 'version' => 'integer',
    ];

    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ConstructionStage::class, 'construction_stage_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(NewBuildingBlock::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeveloperUnit::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(NewBuildingPhoto::class);
    }

    public function coverPhoto(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NewBuildingPhoto::class)->ofMany(['is_cover' => 'max', 'sort_order' => 'min', 'id' => 'min']);
    }

    public function entrances(): HasMany
    {
        return $this->hasMany(NewBuildingEntrance::class);
    }

    public function layouts(): HasMany
    {
        return $this->hasMany(UnitLayout::class);
    }

    public function floorPlans(): HasMany
    {
        return $this->hasMany(BuildingFloorPlan::class);
    }

    public function responsibleAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_agent_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('new_buildings.publication_status', 'published')->orWhere(function (Builder $legacy) {
                $legacy->whereNull('new_buildings.publication_status')->where('new_buildings.moderation_status', 'approved');
            });
        });
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_new_building')->withTimestamps();
    }

    public function previewUnits()
    {
        return $this->hasMany(DeveloperUnit::class)
            ->available()
            ->orderBy('price_per_sqm')
            ->limit(3);
    }
}
