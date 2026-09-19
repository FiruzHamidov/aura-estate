<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchGroup extends Model
{
    use HasFactory;

    public const CONTACT_VISIBILITY_GROUP_ONLY = 'group_only';
    public const CONTACT_VISIBILITY_BRANCH = 'branch';

    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'contact_visibility_mode',
    ];

    public static function contactVisibilityModes(): array
    {
        return [
            self::CONTACT_VISIBILITY_GROUP_ONLY,
            self::CONTACT_VISIBILITY_BRANCH,
        ];
    }

    public function rops()
    {
        return $this->belongsToMany(User::class, 'rop_branch_groups', 'branch_group_id', 'rop_id')
            ->withPivot('assigned_by')->withTimestamps();
    }

    public function hasAssignedRops(bool $lock = false): bool
    {
        $query = \Illuminate\Support\Facades\DB::table('rop_branch_groups')->where('branch_group_id', $this->id);

        return $lock ? $query->lockForUpdate()->first(['id']) !== null : $query->exists();
    }

    public function hasAssignedData(bool $lock = false): bool
    {
        foreach ([
            'users', 'clients', 'properties', 'leads', 'crm_deals', 'bookings', 'crm_tasks',
            'daily_reports', 'attendance_daily_summaries', 'attendance_events', 'attendance_leaves',
            'attendance_duties', 'attendance_devices', 'user_location_points', 'external_property_requests',
            'kpi_plans', 'kpi_rop_plans', 'kpi_period_locks', 'kpi_early_risk_alerts',
            'kpi_quality_issues', 'kpi_acceptance_runs', 'kpi_adjustment_logs',
            'rop_liquidity_results', 'rop_liquidity_history', 'notifications', 'selections', 'reels',
        ] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'branch_group_id')) continue;
            $query = \Illuminate\Support\Facades\DB::table($table)->where('branch_group_id', $this->id);
            // In MySQL REPEATABLE READ a plain exists() can retain the snapshot
            // from before waiting for the group lock. Locking reads see committed membership.
            if ($lock ? $query->lockForUpdate()->first(['id']) !== null : $query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
