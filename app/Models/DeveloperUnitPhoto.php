<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperUnitPhoto extends Model
{
    protected $fillable = ['unit_id', 'path', 'is_cover', 'sort_order', 'storage_disk', 'original_path', 'width', 'height', 'kind', 'alt', 'version', 'variants'];

    protected $casts = [
        'variants' => 'array',
        'is_cover' => 'boolean',
        'version' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DeveloperUnit::class, 'unit_id');
    }
}
