<?php

namespace App\Services;

use App\Models\CrmTask;
use App\Models\DailyReport;
use App\Models\KpiAdjustmentLog;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiIntegrationStatus;
use App\Models\KpiPeriodLock;
use App\Models\KpiPlan;
use App\Models\KpiQualityIssue;
use App\Models\KpiTelegramReportConfig;
use App\Models\User;
use App\Services\DailyReportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class KpiModuleService
{
    private const TZ = 'Asia/Dushanbe';
    private const STATUS_DONE = 'done';
    private const STATUS_CONTROL = 'control';
    private const STATUS_WEAK = 'weak';
    private const STATUS_URGENT = 'urgent';

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

    public function myDailyProgress(User $authUser, Carbon $date): array
    {
        $report = DailyReport::query()
            ->where('user_id', $authUser->id)
            ->whereDate('report_date', $date->toDateString())
            ->first();

        $auto = $this->dailyReportService->autoMetrics($authUser, $date->toDateString());
        $submitted = $report?->submitted_at !== null;

        $metrics = [
            'call' => $this->metricProgress(
                (int) ($report?->calls_count ?? ($auto['calls_count'] ?? 0)),
                (float) (config('kpi.metrics.calls_count.target') ?? 0)
            ),
            'show' => $this->metricProgress(
                (int) ($report?->shows_count ?? ($auto['shows_count'] ?? 0)),
                (float) (config('kpi.metrics.shows_count.target') ?? 0)
            ),
            'deal' => $this->metricProgress(
                (int) ($report?->deals_count ?? ($auto['deals_count'] ?? 0)),
                (float) (config('kpi.metrics.deals_count.target') ?? 0)
            ),
            'advertisement' => $this->metricProgress(
                (int) ($report?->ad_count ?? 0),
                (float) (config('kpi.metrics.ad_count.target') ?? 0)
            ),
        ];

        $overallProgressPct = round(collect($metrics)->avg('progress_pct') ?? 0, 1);

        return [
            'date' => $date->toDateString(),
            'submitted_daily_report' => $submitted,
            'overall_progress_pct' => $overallProgressPct,
            'status' => $this->statusForProgressPct($overallProgressPct),
            'metrics' => $metrics,
        ];
    }

    public function metricMapping(): array
    {
        return [
            'metric_keys' => (array) config('kpi.v2.metric_keys', []),
            'mapping' => (array) config('kpi.v2.metric_mapping', []),
        ];
    }

    public function dailyRowsV2(User $authUser, Carbon $date, array $filters): array
    {
        $from = $this->rangeDateFromFilters($filters, $date->copy()->startOfDay());
        $to = $this->rangeDateToFilters($filters, $date->copy()->endOfDay());
        $periodKey = $from->toDateString();

        return $this->buildV2Response($authUser, 'day', $from, $to, $filters, $periodKey, false);
    }

    public function periodRowsV2(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): array
    {
        $periodKey = $periodType === 'week'
            ? $from->format('o-\WW')
            : $from->format('Y-m');

        return $this->buildV2Response($authUser, $periodType, $from, $to, $filters, $periodKey, true);
    }

    private function buildV2Response(
        User $authUser,
        string $periodType,
        Carbon $from,
        Carbon $to,
        array $filters,
        string $periodKey,
        bool $withBreakdown
    ): array {
        $query = DailyReport::query()
            ->with(['user.role', 'user.branch', 'user.branchGroup'])
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyScope($query, $authUser, $filters);

        $perPage = (int) ($filters['per_page'] ?? 50);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query->orderBy('user_id')->paginate($perPage, ['*'], 'page', $page);

        $mapping = (array) config('kpi.v2.metric_mapping', []);
        $targetMap = (array) config('kpi.v2.targets', []);
        $weightMap = (array) config('kpi.v2.weights', []);
        $globalSourceError = false;

        $data = collect($paginator->items())
            ->groupBy('user_id')
            ->map(function (Collection $rows) use ($periodType, $from, $mapping, $targetMap, $weightMap, $withBreakdown, &$globalSourceError) {
                $first = $rows->first();
                $user = $first?->user;
                $autoByDate = [];
                $sourceErrors = [];

                foreach ($rows as $row) {
                    try {
                        $autoByDate[$row->report_date->toDateString()] = $this->dailyReportService->autoMetrics($user, $row->report_date->toDateString());
                    } catch (Throwable) {
                        $autoByDate[$row->report_date->toDateString()] = [];
                        $sourceErrors[$row->report_date->toDateString()] = true;
                    }
                }

                $metrics = $this->buildMetricsForRows($rows, $autoByDate, $sourceErrors, $mapping, $targetMap, $periodType);
                $kpiValue = $this->kpiValueFromMetrics($metrics, $weightMap);
                $kpiPercent = round($kpiValue * 100, 1);
                $status = $this->statusForKpiPercent($kpiPercent);
                $locked = $this->isPeriodLockedForUser($periodType, $from, $user);
                $rowSourceError = collect($metrics)->contains(fn (array $metric) => (bool) $metric['source_error']);
                $globalSourceError = $globalSourceError || $rowSourceError;

                $payload = [
                    'period_key' => $periodType === 'day' ? $from->toDateString() : ($periodType === 'week' ? $from->format('o-\WW') : $from->format('Y-m')),
                    'employee_id' => $user?->id,
                    'employee_name' => $user?->name,
                    'agent_id' => $user?->role?->slug === 'agent' ? $user?->id : null,
                    'agent_name' => $user?->role?->slug === 'agent' ? $user?->name : null,
                    'mop_id' => $user?->role?->slug === 'mop' ? $user?->id : null,
                    'mop_name' => $user?->role?->slug === 'mop' ? $user?->name : null,
                    'group_id' => $user?->branch_group_id,
                    'group_name' => $user?->branchGroup?->name,
                    'branch_id' => $user?->branch_id,
                    'branch_name' => $user?->branch?->name,
                    'metrics' => $metrics,
                    'kpi_value' => $kpiValue,
                    'kpi_percent' => $kpiPercent,
                    'status' => $status,
                    'locked' => $locked,
                ];

                if ($withBreakdown) {
                    $payload['breakdown_by_day'] = $this->breakdownByDay($rows, $mapping, $targetMap, $sourceErrors);
                }

                return $payload;
            })
            ->values();

        $completeness = $this->completenessPct($data);

        return [
            'data' => $data,
            'meta' => [
                'period_type' => $periodType,
                'period_key' => $periodType === 'day' ? $from->toDateString() : null,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'locked' => $this->isPeriodLocked($periodType, $from, $filters),
                'quality' => [
                    'duplicate_check_passed' => true,
                    'completeness_pct' => $completeness,
                    'source_error' => $globalSourceError,
                ],
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ];
    }

    private function buildMetricsForRows(
        Collection $rows,
        array $autoByDate,
        array $sourceErrors,
        array $mapping,
        array $targetMap,
        string $periodType
    ): array {
        $metrics = [];
        $daysInPeriod = max(1, $rows->count());

        foreach ($mapping as $metricKey => $cfg) {
            $column = (string) ($cfg['source_column'] ?? '');
            $sourceType = (string) ($cfg['source_type'] ?? 'manual');
            $target = ((float) ($targetMap[$metricKey] ?? 0)) * ($periodType === 'day' ? 1 : $daysInPeriod);

            $manualValue = (float) $rows->sum($column);
            $factValue = 0.0;
            $sourceError = false;

            foreach ($rows as $row) {
                $dateKey = $row->report_date->toDateString();
                if (! empty($sourceErrors[$dateKey])) {
                    $sourceError = true;
                    continue;
                }
                $factValue += (float) ($autoByDate[$dateKey][$column] ?? 0);
            }

            if ($sourceType === 'system') {
                $finalValue = $factValue;
                $manualResult = 0.0;
                $source = 'system';
            } elseif ($sourceType === 'mixed') {
                $finalValue = $factValue + $manualValue;
                $manualResult = $manualValue;
                $source = 'mixed';
            } else {
                $finalValue = $manualValue;
                $manualResult = $manualValue;
                $factValue = 0.0;
                $source = 'manual';
            }

            $progress = $target > 0 ? round(($finalValue / $target) * 100, 2) : 0.0;

            $metrics[$metricKey] = [
                'fact_value' => $this->normalizeNumber($factValue),
                'manual_value' => $this->normalizeNumber($manualResult),
                'final_value' => $this->normalizeNumber($finalValue),
                'target_value' => $this->normalizeNumber($target),
                'progress_pct' => $progress,
                'source' => $source,
                'source_error' => $sourceError,
            ];
        }

        return $metrics;
    }

    private function breakdownByDay(Collection $rows, array $mapping, array $targetMap, array $sourceErrors): array
    {
        return $rows
            ->groupBy(fn (DailyReport $row) => $row->report_date->toDateString())
            ->map(function (Collection $dayRows, string $day) use ($mapping, $targetMap, $sourceErrors) {
                $autoByDate = [];
                foreach ($dayRows as $row) {
                    try {
                        $autoByDate[$day] = $this->dailyReportService->autoMetrics($row->user, $day);
                    } catch (Throwable) {
                        $autoByDate[$day] = [];
                    }
                }

                return [
                    'period_key' => $day,
                    'metrics' => $this->buildMetricsForRows($dayRows, $autoByDate, $sourceErrors, $mapping, $targetMap, 'day'),
                ];
            })
            ->values()
            ->all();
    }

    private function kpiValueFromMetrics(array $metrics, array $weightMap): float
    {
        $value = 0.0;

        foreach ($metrics as $key => $metric) {
            $target = (float) ($metric['target_value'] ?? 0);
            $final = (float) ($metric['final_value'] ?? 0);
            if ($target <= 0) {
                continue;
            }

            $weight = (float) ($weightMap[$key] ?? 0);
            $value += ($final / $target) * $weight;
        }

        return round($value, 4);
    }

    private function statusForKpiPercent(float $kpiPercent): string
    {
        if ($kpiPercent >= 100) {
            return self::STATUS_DONE;
        }
        if ($kpiPercent >= 80) {
            return self::STATUS_CONTROL;
        }
        if ($kpiPercent >= 60) {
            return self::STATUS_WEAK;
        }

        return self::STATUS_URGENT;
    }

    private function completenessPct(Collection $rows): float
    {
        if ($rows->isEmpty()) {
            return 100.0;
        }

        $metricCount = count((array) config('kpi.v2.metric_mapping', []));
        if ($metricCount === 0) {
            return 0.0;
        }

        $total = $rows->count() * $metricCount;
        $ok = 0;
        foreach ($rows as $row) {
            foreach ((array) ($row['metrics'] ?? []) as $metric) {
                if (! ($metric['source_error'] ?? false)) {
                    $ok++;
                }
            }
        }

        return round(($ok / max(1, $total)) * 100, 2);
    }

    private function isPeriodLocked(string $periodType, Carbon $periodStart, array $filters): bool
    {
        $periodKey = match ($periodType) {
            'day' => $periodStart->toDateString(),
            'week' => $periodStart->toDateString(),
            'month' => $periodStart->format('Y-m'),
            default => $periodStart->toDateString(),
        };

        $query = KpiPeriodLock::query()
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['branch_group_id'])) {
            $query->where('branch_group_id', (int) $filters['branch_group_id']);
        }

        return $query->exists();
    }

    private function isPeriodLockedForUser(string $periodType, Carbon $periodStart, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isPeriodLocked($periodType, $periodStart, [
            'branch_id' => $user->branch_id,
            'branch_group_id' => $user->branch_group_id,
        ]);
    }

    private function rangeDateFromFilters(array $filters, Carbon $fallback): Carbon
    {
        if (! empty($filters['date_from'])) {
            return Carbon::parse($filters['date_from'], self::TZ)->startOfDay();
        }

        return $fallback;
    }

    private function rangeDateToFilters(array $filters, Carbon $fallback): Carbon
    {
        if (! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_to'], self::TZ)->endOfDay();
        }

        return $fallback;
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
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function metricProgress(int $fact, float $target): array
    {
        $progressPct = $target > 0 ? round(($fact / $target) * 100, 1) : 0.0;

        return [
            'fact' => $fact,
            'target' => $this->normalizeNumber($target),
            'progress_pct' => $progressPct,
        ];
    }

    private function statusForProgressPct(float $progressPct): string
    {
        $thresholds = (array) config('kpi.status_thresholds', []);

        if ($progressPct >= (float) (($thresholds['success'] ?? 1.0) * 100)) {
            return 'success';
        }

        if ($progressPct >= (float) (($thresholds['control'] ?? 0.8) * 100)) {
            return 'control';
        }

        if ($progressPct >= (float) (($thresholds['risk'] ?? 0.6) * 100)) {
            return 'risk';
        }

        return 'weak';
    }

    private function normalizeNumber(float $value): int|float
    {
        $rounded = round($value, 4);

        return floor($rounded) == $rounded ? (int) $rounded : $rounded;
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
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('assignee_id', $authUser->id),
        };
    }
}
