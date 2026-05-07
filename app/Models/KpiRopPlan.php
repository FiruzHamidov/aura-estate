<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiRopPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_slug',
        'branch_id',
        'branch_group_id',
        'month',
        'items',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'items' => 'array',
        'branch_id' => 'integer',
        'branch_group_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
