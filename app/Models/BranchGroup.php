<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchGroup extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('active', function ($query) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('branch_groups', 'is_archived')) {
                $query->where('branch_groups.is_archived', false);
            }
        });
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->withoutGlobalScope('active');
    }

    protected $casts = ['is_archived' => 'boolean'];

    public const CONTACT_VISIBILITY_GROUP_ONLY = 'group_only';
    public const CONTACT_VISIBILITY_BRANCH = 'branch';

    // Current ownership and historical snapshots both prevent physical deletion.
    public const DATA_REFERENCES = [
        'users' => 'Пользователи', 'clients' => 'Клиенты', 'properties' => 'Объекты недвижимости',
        'leads' => 'Лиды', 'crm_deals' => 'Сделки', 'bookings' => 'Показы', 'crm_tasks' => 'Задачи CRM',
        'daily_reports' => 'Ежедневные отчёты', 'attendance_daily_summaries' => 'История посещаемости',
        'attendance_events' => 'События посещаемости', 'attendance_leaves' => 'Отсутствия',
        'attendance_duties' => 'Дежурства', 'attendance_devices' => 'Устройства посещаемости',
        'user_location_points' => 'История местоположений', 'external_property_requests' => 'Заявки внешних агентов',
        'kpi_plans' => 'Планы KPI', 'kpi_rop_plans' => 'Планы РОП', 'kpi_period_locks' => 'Блокировки KPI',
        'kpi_early_risk_alerts' => 'Предупреждения KPI', 'kpi_quality_issues' => 'Проверки качества KPI',
        'kpi_acceptance_runs' => 'Приёмки KPI', 'kpi_adjustment_logs' => 'История корректировок KPI',
        'rop_liquidity_results' => 'Результаты ликвидности', 'rop_liquidity_history' => 'История ликвидности',
        'notifications' => 'Уведомления', 'selections' => 'Подборки', 'reels' => 'Reels',
    ];

    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'is_archived',
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
        foreach (array_keys(self::DATA_REFERENCES) as $table) {
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
