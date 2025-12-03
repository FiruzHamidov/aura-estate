<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{
    BelongsTo, BelongsToMany, HasMany
};

class NewBuilding extends Model
{
    protected $fillable = [
        'title','description','developer_id','construction_stage_id','material_id',
        'location_id','installment_available','heating','has_terrace',
        'floors_range','completion_at','address','latitude','longitude',
        'moderation_status','created_by',
    ];

    protected $casts = [
        'installment_available' => 'boolean',
        'heating' => 'boolean',
        'has_terrace' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'completion_at' => 'datetime',
    ];

    public function developer(): BelongsTo { return $this->belongsTo(Developer::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function stage(): BelongsTo { return $this->belongsTo(ConstructionStage::class, 'construction_stage_id'); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class); }

    public function blocks(): HasMany { return $this->hasMany(NewBuildingBlock::class); }
    public function units(): HasMany { return $this->hasMany(DeveloperUnit::class); }
    public function photos(): HasMany { return $this->hasMany(NewBuildingPhoto::class); }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_new_building')->withTimestamps();
    }

    public function previewUnits()
    {
        return $this->hasMany(DeveloperUnit::class)
            ->where('is_available', true)
            ->where('moderation_status', 'approved')
            ->orderBy('price_per_sqm')
            ->limit(3);
    }
}
