<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\KpiPeriodLock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class KpiReportService
{
    public function build(User $authUser, array $filters): array
    {
        $periodType = $filters['period_type'];
        $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();
        $dateTo = Carbon::parse($filters['date_to'])->endOfDay();

        $query = DailyReport::query()
            ->with(['user.role'])
            ->whereDate('report_date', '>=', $dateFrom->toDateString())
            ->whereDate('report_date', '<=', $dateTo->toDateString());

        $this->applyVisibilityScope($query, $authUser);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']));
        }

        if (! empty($filters['branch_group_id'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', (int) $filters['branch_group_id']));
        }

        $reports = $query->get();

        $metricsConfig = (array) config('kpi.metrics', []);
        $availableColumns = $this->availableMetricColumns(array_keys($metricsConfig));
        $grouped = $this->groupReports($reports, $periodType);

        $rows = $grouped->map(function (Collection $bucketReports, string $bucketKey) use ($periodType, $metricsConfig, $availableColumns) {
            /** @var DailyReport $first */
            $first = $bucketReports->first();
            $user = $first->user;
            [$periodOnlyKey] = explode('|', $bucketKey, 2);
            $periodRange = $this->resolvePeriodRange($periodType, $bucketKey);
            $lock = $this->resolveLock($periodType, $periodOnlyKey, $user?->branch_id, $user?->branch_group_id);

            $metrics = [];
            $kpiValue = 0.0;

            foreach ($metricsConfig as $column => $cfg) {
                $factValue = in_array($column, $availableColumns, true)
                    ? (float) $bucketReports->sum($column)
                    : 0.0;

                $targetValue = $this->targetForPeriod(
                    (float) ($cfg['target'] ?? 0),
                    $periodType,
                    $periodRange['start'],
                    $periodRange['end']
                );

                $progressPct = $targetValue > 0 ? round(($factValue / $targetValue) * 100, 2) : 0.0;
                $weight = (float) ($cfg['weight'] ?? 0);
                $kpiValue += $targetValue > 0 ? ($factValue / $targetValue) * $weight : 0.0;

                $metrics[$column] = [
                    'label' => (string) ($cfg['label'] ?? $column),
                    'fact_value' => $this->normalizeNumber($factValue),
                    'target_value' => $this->normalizeNumber($targetValue),
                    'progress_pct' => $progressPct,
                ];
            }

            $kpiValue = round($kpiValue, 4);

            return [
                'period_type' => $periodType,
                'period_key' => $periodOnlyKey,
                'period_start' => $periodRange['start']->toDateString(),
                'period_end' => $periodRange['end']->toDateString(),
                'user' => [
                    'id' => $user?->id,
                    'name' => $user?->name,
                    'role_slug' => $user?->role?->slug,
                    'branch_id' => $user?->branch_id,
                    'branch_group_id' => $user?->branch_group_id,
                ],
                'kpi_value' => $kpiValue,
                'status' => $this->statusForKpi($kpiValue),
                'is_locked' => $lock !== null,
                'locked_at' => $lock?->locked_at?->toDateTimeString(),
                'metrics' => $metrics,
            ];
        })->values();

        return [
            'filters' => [
                'period_type' => $periodType,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'branch_id' => $filters['branch_id'] ?? null,
                'branch_group_id' => $filters['branch_group_id'] ?? null,
                'user_id' => $filters['user_id'] ?? null,
            ],
            'data' => $rows,
        ];
    }

    private function applyVisibilityScope(Builder $query, User $authUser): void
    {
        $authUser->loadMissing('role');

        match ($authUser->role?->slug) {
            'admin', 'superadmin' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function groupReports(Collection $reports, string $periodType): Collection
    {
        return $reports->groupBy(function (DailyReport $report) use ($periodType) {
            $date = $report->report_date instanceof Carbon
                ? $report->report_date->copy()
                : Carbon::parse((string) $report->report_date);

            $periodKey = match ($periodType) {
                'week' => $date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                'month' => $date->format('Y-m'),
                default => $date->toDateString(),
            };

            return $periodKey.'|'.$report->user_id;
        });
    }

    private function resolvePeriodRange(string $periodType, string $bucketKey): array
    {
        [$periodKey] = explode('|', $bucketKey, 2);

        if ($periodType === 'week') {
            $start = Carbon::parse($periodKey)->startOfDay();
            $end = $start->copy()->addDays(6)->endOfDay();

            return ['start' => $start, 'end' => $end];
        }

        if ($periodType === 'month') {
            $start = Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            return ['start' => $start, 'end' => $end];
        }

        $start = Carbon::parse($periodKey)->startOfDay();

        return ['start' => $start, 'end' => $start->copy()->endOfDay()];
    }

    private function targetForPeriod(float $dailyTarget, string $periodType, Carbon $start, Carbon $end): float
    {
        if ($periodType === 'day') {
            return $dailyTarget;
        }

        if ($periodType === 'week') {
            return $dailyTarget * (float) config('kpi.working_days_per_week', 6);
        }

        $workDays = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if ((int) $cursor->dayOfWeek !== Carbon::SUNDAY) {
                $workDays++;
            }

            $cursor->addDay();
        }

        return $dailyTarget * $workDays;
    }

    private function statusForKpi(float $kpiValue): string
    {
        $thresholds = (array) config('kpi.status_thresholds', []);

        if ($kpiValue >= (float) ($thresholds['success'] ?? 1.0)) {
            return 'success';
        }

        if ($kpiValue >= (float) ($thresholds['control'] ?? 0.8)) {
            return 'control';
        }

        if ($kpiValue >= (float) ($thresholds['risk'] ?? 0.6)) {
            return 'risk';
        }

        return 'critical';
    }

    private function availableMetricColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('daily_reports', $column)));
    }

    private function normalizeNumber(float $value): int|float
    {
        $rounded = round($value, 4);

        return floor($rounded) == $rounded ? (int) $rounded : $rounded;
    }

    private function resolveLock(string $periodType, string $periodKey, ?int $branchId, ?int $branchGroupId): ?KpiPeriodLock
    {
        if (! Schema::hasTable('kpi_period_locks')) {
            return null;
        }

        return KpiPeriodLock::query()
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function ($query) use ($branchGroupId) {
                $query->whereNull('branch_group_id');

                if ($branchGroupId !== null) {
                    $query->orWhere('branch_group_id', $branchGroupId);
                }
            })
            ->orderByDesc('id')
            ->first();
    }
}
