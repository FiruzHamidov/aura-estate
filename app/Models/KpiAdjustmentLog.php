<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiAdjustmentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_type',
        'period_key',
        'entity_id',
        'field_name',
        'old_value',
        'new_value',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];
}
