<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiQualityIssue extends Model
{
    use HasFactory;

    protected $fillable = ['branch_group_id', 'title', 'severity', 'detected_at', 'status', 'details'];

    public function scopeForRop(\Illuminate\Database\Eloquent\Builder $query, User $actor, ?int $groupId = null): \Illuminate\Database\Eloquent\Builder
    {
        if ($actor->hasRole('rop')) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'kpi_quality_issues.branch_group_id');
            if ($groupId !== null) $query->where('kpi_quality_issues.branch_group_id', $groupId);
        }
        return $query;
    }

    protected $casts = [
        'detected_at' => 'datetime',
        'details' => 'array',
    ];
}
