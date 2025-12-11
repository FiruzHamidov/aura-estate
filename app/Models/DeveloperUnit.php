<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class DeveloperUnit extends Model
{
    protected $fillable = [
        'new_building_id','block_id','name','bedrooms','bathrooms','area',
        'floor','price_per_sqm','total_price','description','is_available', 'moderation_status', 'window_view',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'price_per_sqm' => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_available' => 'boolean',
        'window_view' => 'string',
    ];

    public function newBuilding(): BelongsTo { return $this->belongsTo(NewBuilding::class); }
    public function block(): BelongsTo { return $this->belongsTo(NewBuildingBlock::class, 'block_id'); }
    public function photos(): HasMany { return $this->hasMany(DeveloperUnitPhoto::class, 'unit_id'); }
}
