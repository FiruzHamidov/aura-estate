<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiEarlyRiskAlert extends Model
{
    use HasFactory;

    protected $fillable = ['branch_group_id', 'user_id', 'alert_date', 'status', 'message', 'meta'];

    protected static function booted(): void
    {
        static::creating(function (self $alert) {
            if ($alert->branch_group_id || ! \Illuminate\Support\Facades\Schema::hasColumn($alert->getTable(), 'branch_group_id')) return;
            if ($alert->alert_date?->toDateString() !== now('Asia/Dushanbe')->toDateString()) return;
            $employee = User::query()->whereKey($alert->user_id)
                ->whereHas('role', fn ($query) => $query->whereIn('slug', ['agent', 'mop']))->first();
            if ($employee && BranchGroup::whereKey($employee->branch_group_id)->where('branch_id', $employee->branch_id)->exists()) {
                $alert->branch_group_id = $employee->branch_group_id;
            }
        });
    }

    protected $casts = ['branch_group_id' => 'integer',

        'alert_date' => 'date',
        'meta' => 'array',
    ];
}
