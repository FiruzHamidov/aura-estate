<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyLiquiditySnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cohort_definition' => 'array',
        'factors' => 'array',
        'recommendations' => 'array',
        'market' => 'array',
        'interest' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
