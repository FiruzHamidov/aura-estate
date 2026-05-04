<?php

namespace App\Services;

use App\Models\CrmTask;
use App\Models\DailyReport;
use App\Models\KpiAdjustmentLog;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiIntegrationStatus;
use App\Models\KpiPlan;
use App\Models\KpiQualityIssue;
use App\Models\KpiTelegramReportConfig;
use App\Models\User;
use App\Services\DailyReportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class KpiModuleService
{
    private const TZ = 'Asia/Dushanbe';

    public function __construct(private readonly DailyReportService $dailyReportService)
    {
    }

    public function plans(string $role): Collection
    {
        $base = collect(config('kpi.metrics', []))->map(function (array $cfg, string $metricKey) use ($role) {
            return [
                'role' => $role,
                'metric_key' => $metricKey,
                'daily_plan' => (float) ($cfg['target'] ?? 0),
                'weight' => (float) ($cfg['weight'] ?? 0),
                'comment' => (string) ($cfg['label'] ?? $metricKey),
            ];
        })->values();

        $overrides = Schema::hasTable('kpi_plans')
            ? KpiPlan::query()->where('role_slug', $role)->get()->keyBy('metric_key')
            : collect();

        return $base->map(function (array $row) use ($overrides) {
            $override = $overrides->get($row['metric_key']);
            if (! $override) {
                return $row;
            }

            $row['daily_plan'] = (float) $override->daily_plan;
            $row['weight'] = (float) $override->weight;
            $row['comment'] = (string) ($override->comment ?? $row['comment']);

            return $row;
        });
    }

    public function upsertPlans(string $role, array $items): Collection
    {
        foreach ($items as $item) {
            KpiPlan::query()->updateOrCreate(
                ['role_slug' => $role, 'metric_key' => $item['metric_key']],
                [
                    'daily_plan' => $item['daily_plan'],
                    'weight' => $item['weight'],
                    'comment' => $item['comment'] ?? null,
                ]
            );
        }

        return $this->plans($role);
    }

    public function dailyRows(User $authUser, Carbon $date, array $filters): Collection
    {
        $query = DailyReport::query()->with(['user.role'])->whereDate('report_date', $date->toDateString());
        $this->applyScope($query, $authUser, $filters);

        return $query->orderBy('user_id')->get();
    }

    public function periodRows(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DailyReport::query()->with(['user.role'])
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyScope($query, $authUser, $filters);

        return $query->get()->groupBy('user_id')->map(function (Collection $rows) use ($periodType, $from, $to) {
            $first = $rows->first();
            return [
                'period_type' => $periodType,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'user' => [
                    'id' => $first?->user?->id,
                    'name' => $first?->user?->name,
                    'role_slug' => $first?->user?->role?->slug,
                ],
                'metrics' => [
                    'calls_count' => (int) $rows->sum('calls_count'),
                    'ad_count' => (int) $rows->sum('ad_count'),
                    'meetings_count' => (int) $rows->sum('meetings_count'),
                    'shows_count' => (int) $rows->sum('shows_count'),
                    'new_clients_count' => (int) $rows->sum('new_clients_count'),
                    'deposits_count' => (int) $rows->sum('deposits_count'),
                    'deals_count' => (int) $rows->sum('deals_count'),
                ],
            ];
        })->values();
    }

    public function dashboard(User $authUser, Carbon $date, array $filters): array
    {
        $rows = $this->periodRows($authUser, 'day', $date->copy()->startOfDay(), $date->copy()->endOfDay(), $filters);

        $ranking = $rows->sortByDesc(fn (array $row) => array_sum($row['metrics']))->values()->map(function (array $row, int $i) {
            $row['rank'] = $i + 1;
            return $row;
        });

        return [
            'summary' => [
                'date' => $date->toDateString(),
                'rows_count' => $rows->count(),
            ],
            'ranking' => $ranking,
        ];
    }

    public function dashboardDebug(User $authUser, Carbon $date, array $filters): array
    {
        $base = $this->dashboard($authUser, $date, $filters);
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $ranking = collect($base['ranking'])->map(function (array $row) use ($date) {
            $userId = (int) ($row['user']['id'] ?? 0);
            $user = $userId > 0 ? User::query()->find($userId) : null;
            $sourceCounts = $user ? $this->dailyReportService->autoMetrics($user, $date->toDateString()) : [];

            $row['source_counts'] = $sourceCounts;
            $row['stored_metrics_total'] = array_sum($row['metrics']);
            $row['source_metrics_total'] = array_sum($sourceCounts);

            return $row;
        })->values();

        return [
            'summary' => $base['summary'],
            'ranking' => $ranking,
            'applied_filters' => [
                'date' => $date->toDateString(),
                'role' => $filters['role'] ?? null,
                'assignee_id' => $filters['assignee_id'] ?? null,
                'mop_id' => $filters['mop_id'] ?? null,
                'agent_id' => $filters['agent_id'] ?? null,
                'branch_id' => $filters['branch_id'] ?? null,
                'branch_group_id' => $filters['branch_group_id'] ?? null,
                'auth_role' => $authUser->role?->slug,
                'auth_user_id' => $authUser->id,
            ],
            'timezone' => self::TZ,
            'period_bounds' => [
                'local' => ['start' => $dayStart->toDateTimeString(), 'end' => $dayEnd->toDateTimeString()],
                'utc' => ['start' => $dayStartUtc->toDateTimeString(), 'end' => $dayEndUtc->toDateTimeString()],
            ],
        ];
    }

    public function telegramConfig(): KpiTelegramReportConfig
    {
        return KpiTelegramReportConfig::query()->firstOrCreate([], [
            'daily_enabled' => false,
            'daily_time' => '09:00',
            'weekly_enabled' => true,
            'weekly_day' => 1,
            'weekly_time' => '10:00',
            'timezone' => self::TZ,
        ]);
    }

    public function updateTelegramConfig(array $payload): KpiTelegramReportConfig
    {
        $config = $this->telegramConfig();
        $config->fill($payload)->save();

        return $config->fresh();
    }

    public function periodContract(User $authUser, Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->periodRows($authUser, 'custom', $from, $to, $filters);
    }

    public function taskDailySummary(User $authUser, Carbon $date, array $filters): Collection
    {
        $query = CrmTask::query()->with(['assignee.role'])
            ->whereDate('created_at', $date->toDateString());

        $this->applyTaskScope($query, $authUser, $filters);

        return $query->get()->groupBy('assignee_id')->map(function (Collection $rows, $assigneeId) use ($date) {
            return [
                'date' => $date->toDateString(),
                'assignee_id' => (int) $assigneeId,
                'tasks_total' => $rows->count(),
                'done_total' => $rows->where('status', 'done')->count(),
                'overdue_total' => $rows->where('status', 'overdue')->count(),
            ];
        })->values();
    }

    public function taskWeeklySummary(User $authUser, int $year, int $week, array $filters): Collection
    {
        $start = Carbon::now(self::TZ)->setISODate($year, $week)->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        $query = CrmTask::query()->with(['assignee.role'])
            ->whereBetween('created_at', [$start->toDateString(), $end->toDateString()]);

        $this->applyTaskScope($query, $authUser, $filters);

        return $query->get()->groupBy('assignee_id')->map(function (Collection $rows, $assigneeId) use ($year, $week) {
            return [
                'year' => $year,
                'week' => $week,
                'assignee_id' => (int) $assigneeId,
                'tasks_total' => $rows->count(),
                'done_total' => $rows->where('status', 'done')->count(),
                'overdue_total' => $rows->where('status', 'overdue')->count(),
            ];
        })->values();
    }

    private function applyScope(Builder $query, User $authUser, array $filters): void
    {
        $authUser->loadMissing('role');

        if (! empty($filters['assignee_id'])) {
            $query->where('user_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('user_id', (int) $filters['agent_id']);
        }

        if (! empty($filters['mop_id'])) {
            $query->where('user_id', (int) $filters['mop_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']));
        }

        if (! empty($filters['branch_group_id'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', (int) $filters['branch_group_id']));
        }

        if (! empty($filters['role'])) {
            $query->where('role_slug', (string) $filters['role']);
        }

        match ($authUser->role?->slug) {
            'admin', 'superadmin' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function applyTaskScope(Builder $query, User $authUser, array $filters): void
    {
        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']));
        }

        if (! empty($filters['branch_group_id'])) {
            $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_group_id', (int) $filters['branch_group_id']));
        }

        $authUser->loadMissing('role');

        match ($authUser->role?->slug) {
            'admin', 'superadmin' => null,
            'rop', 'branch_director' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('assignee_id', $authUser->id),
        };
    }
}
