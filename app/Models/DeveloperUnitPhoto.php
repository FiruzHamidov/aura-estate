<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperUnitPhoto extends Model
{
    protected $fillable = ['unit_id','path','is_cover','sort_order'];

    protected $casts = [
        'is_cover' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DeveloperUnit::class, 'unit_id');
    }
}
