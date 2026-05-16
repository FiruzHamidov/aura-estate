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
use App\Services\Crm\AuditLogger;
use App\Services\DailyReportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Support\KpiPlanScopePolicy;
use Throwable;

class KpiModuleService
{
    private const TZ = 'Asia/Dushanbe';
    private const STATUS_DONE = 'done';
    private const STATUS_CONTROL = 'control';
    private const STATUS_RISK = 'risk';
    private const STATUS_WEAK = 'weak';
    private const STATUS_URGENT = 'urgent';
    private const PLAN_METRIC_WHITELIST = ['objects', 'shows', 'ads', 'calls', 'sales'];

    public function __construct(
        private readonly DailyReportService $dailyReportService,
        private readonly AuditLogger $auditLogger,
        private readonly KpiPlanScopePolicy $kpiPlanScopePolicy
    )
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
                $this->buildPlanWritePayload([
                    'daily_plan' => $this->planValueFromItem((array) $item),
                    'weight' => $item['weight'],
                    'comment' => $item['comment'] ?? null,
                ])
            );
        }

        return $this->plans($role);
    }

    public function plansForUser(int $userId, Carbon $date): Collection
    {
        $user = User::query()->with('role')->findOrFail($userId);
        $merged = collect();

        $commonRows = $this->findCommonPlanRows(
            (string) ($user->role?->slug ?? 'mop'),
            $date,
            $user->branch_id ? (int) $user->branch_id : null,
            $user->branch_group_id ? (int) $user->branch_group_id : null
        );
        if ($commonRows->isNotEmpty()) {
            $merged = $this->serializePlanRows($commonRows, 'common')->keyBy('metric_key');
        }

        $personalRows = KpiPlan::query()
            ->where('user_id', $userId)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->get();

        if ($personalRows->isNotEmpty()) {
            $personal = $this->serializePlanRows($personalRows, 'personal')->keyBy('metric_key');
            foreach ($personal as $metricKey => $item) {
                $merged->put($metricKey, $item);
            }
        }

        return $merged->values();
    }

    public function effectivePlanForUser(int $userId, Carbon $date): array
    {
        $rows = $this->plansForUser($userId, $date);
        $sources = $rows
            ->map(fn (array $row) => (string) ($row['plan_source'] ?? $row['source'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        $source = null;
        if ($sources->count() === 1) {
            $source = (string) $sources->first();
        } elseif ($sources->contains('personal')) {
            $source = 'personal';
        } elseif ($sources->contains('common')) {
            $source = 'common';
        }

        return [
            'source' => $source,
            'items' => $rows->values(),
        ];
    }

    public function upsertUserPlans(User $actor, int $userId, array $payload): Collection
    {
        $user = User::query()->with('role')->findOrFail($userId);
        $this->ensurePlanScopeAccess($actor, $user);

        $from = (string) $payload['effective_from'];
        $to = $payload['effective_to'] ?? null;
        $replaceIfConflict = (bool) ($payload['replace_if_conflict'] ?? false);
        $conflictStrategy = (string) ($payload['conflict_strategy'] ?? ($replaceIfConflict ? 'replace' : 'error'));

        $conflictsQuery = KpiPlan::query()
            ->where('user_id', $userId)
            ->where(function ($q) use ($from, $to) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
            })
            ->where(function ($q) use ($to) {
                if ($to === null) {
                    $q->whereNotNull('id');
                    return;
                }

                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to);
            });

        $hasConflicts = (clone $conflictsQuery)->exists();

        if ($hasConflicts && $conflictStrategy !== 'replace') {
            throw new \DomainException('Plan period conflicts with an existing personal KPI plan interval.');
        }

        DB::transaction(function () use ($conflictsQuery, $hasConflicts, $conflictStrategy, $user, $userId, $payload, $from, $to, $actor): void {
            if ($hasConflicts && $conflictStrategy === 'replace') {
                $conflictsQuery->delete();
            }

            foreach ((array) $payload['items'] as $item) {
                $newPlan = KpiPlan::query()->create($this->buildPlanWritePayload([
                    'role_slug' => (string) ($user->role?->slug ?? 'mop'),
                    'user_id' => $userId,
                    'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
                    'branch_group_id' => $user->branch_group_id ? (int) $user->branch_group_id : null,
                    'metric_key' => (string) $item['metric_key'],
                    'daily_plan' => $this->planValueFromItem((array) $item),
                    'weight' => (float) $item['weight'],
                    'comment' => $item['comment'] ?? null,
                    'effective_from' => $from,
                    'effective_to' => $to,
                ]));

                $this->auditLogger->log(
                    $newPlan,
                    $actor,
                    'kpi_personal_plan_upserted',
                    [],
                    [
                        'target_user_id' => $userId,
                        'target_role' => (string) ($user->role?->slug ?? 'mop'),
                        'scope' => [
                            'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
                            'branch_group_id' => $user->branch_group_id ? (int) $user->branch_group_id : null,
                        ],
                        'metric_key' => (string) $item['metric_key'],
                        'monthly_plan' => $this->planValueFromItem((array) $item),
                        'weight' => (float) $item['weight'],
                        'comment' => $item['comment'] ?? null,
                        'effective_from' => $from,
                        'effective_to' => $to,
                    ],
                    'KPI personal plan upserted'
                );
            }
        });

        return $this->plansForUser($userId, Carbon::parse($from, self::TZ));
    }

    public function commonPlans(string $role, Carbon $date, ?int $branchId, ?int $branchGroupId): Collection
    {
        $rows = $this->findCommonPlanRows($role, $date, $branchId, $branchGroupId);
        if ($rows->isEmpty()) {
            return collect();
        }

        return $this->serializePlanRows($rows, 'common');
    }

    public function listPlans(User $actor, array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = max((int) ($filters['per_page'] ?? 20), 1);

        $query = KpiPlan::query()
            ->from('kpi_plans as kp')
            ->leftJoin('users as u', 'u.id', '=', 'kp.user_id')
            ->selectRaw("MIN(kp.id) as plan_id")
            ->selectRaw("CASE WHEN kp.user_id IS NULL THEN 'common' ELSE 'personal' END as type")
            ->selectRaw('kp.user_id')
            ->selectRaw('u.name as user_name')
            ->selectRaw('kp.role_slug as role')
            ->selectRaw('kp.branch_id')
            ->selectRaw('kp.branch_group_id')
            ->selectRaw("CASE WHEN kp.user_id IS NULL THEN 'common' ELSE 'personal' END as source")
            ->selectRaw('kp.effective_from')
            ->selectRaw('kp.effective_to')
            ->selectRaw('MAX(kp.updated_at) as updated_at')
            ->selectRaw('COUNT(*) as items_count')
            ->groupBy(
                'kp.user_id',
                'u.name',
                'kp.role_slug',
                'kp.branch_id',
                'kp.branch_group_id',
                'kp.effective_from',
                'kp.effective_to'
            )
            ->orderByDesc(DB::raw('MAX(kp.updated_at)'));

        if (($filters['type'] ?? null) === 'personal') {
            $query->whereNotNull('kp.user_id');
        } elseif (($filters['type'] ?? null) === 'common') {
            $query->whereNull('kp.user_id');
        }

        if (isset($filters['user_id'])) {
            $query->where('kp.user_id', (int) $filters['user_id']);
        }

        $roles = collect((array) ($filters['roles'] ?? []))
            ->filter(fn ($role) => is_string($role) && $role !== '')
            ->values();
        if ($roles->isNotEmpty()) {
            $query->whereIn('kp.role_slug', $roles->all());
        } elseif (isset($filters['role'])) {
            $query->where('kp.role_slug', (string) $filters['role']);
        }

        foreach (['branch_id', 'branch_group_id'] as $scopeField) {
            if (isset($filters[$scopeField])) {
                $query->where('kp.'.$scopeField, (int) $filters[$scopeField]);
            }
        }

        if (! empty($filters['effective_from_from'])) {
            $query->whereDate('kp.effective_from', '>=', (string) $filters['effective_from_from']);
        }
        if (! empty($filters['effective_from_to'])) {
            $query->whereDate('kp.effective_from', '<=', (string) $filters['effective_from_to']);
        }
        if (! empty($filters['effective_to_from'])) {
            $query->whereDate('kp.effective_to', '>=', (string) $filters['effective_to_from']);
        }
        if (! empty($filters['effective_to_to'])) {
            $query->whereDate('kp.effective_to', '<=', (string) $filters['effective_to_to']);
        }

        $actor->loadMissing('role');
        $role = (string) ($actor->role?->slug ?? '');
        if (in_array($role, ['rop', 'branch_director'], true)) {
            $query->where(function ($scope) use ($actor) {
                $scope->where(function ($q) use ($actor) {
                    $q->whereNotNull('kp.user_id')->where('u.branch_id', (int) $actor->branch_id);
                })->orWhere(function ($q) use ($actor) {
                    $q->whereNull('kp.user_id')->where(function ($q2) use ($actor) {
                        $q2->where('kp.branch_id', (int) $actor->branch_id)->orWhereNull('kp.branch_id');
                    });
                });
            });
        } elseif ($role === 'mop') {
            $query->where(function ($scope) use ($actor) {
                $scope->where(function ($q) use ($actor) {
                    $q->whereNotNull('kp.user_id')->where('u.branch_group_id', (int) $actor->branch_group_id);
                })->orWhere(function ($q) use ($actor) {
                    $q->whereNull('kp.user_id')->where(function ($q2) use ($actor) {
                        $q2->where('kp.branch_group_id', (int) $actor->branch_group_id)
                            ->orWhereNull('kp.branch_group_id');
                    });
                });
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $data = collect($paginator->items())->map(function ($row) {
            return [
                'plan_id' => (int) $row->plan_id,
                'type' => (string) $row->type,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => $row->user_name !== null ? (string) $row->user_name : null,
                'role' => $row->role !== null ? (string) $row->role : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'branch_group_id' => $row->branch_group_id !== null ? (int) $row->branch_group_id : null,
                'source' => $row->source !== null ? (string) $row->source : null,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
                'updated_at' => $row->updated_at,
                'updated_by' => null,
                'items_count' => (int) $row->items_count,
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => (int) $paginator->lastPage(),
            ],
        ];
    }

    public function planById(int $planId, ?string $forcedType = null): ?array
    {
        $seed = KpiPlan::query()->find($planId);
        if (! $seed) {
            return null;
        }

        $type = $seed->user_id === null ? 'common' : 'personal';
        if ($forcedType !== null && $forcedType !== $type) {
            return null;
        }

        $query = KpiPlan::query()
            ->where('role_slug', $seed->role_slug)
            ->where('user_id', $seed->user_id)
            ->where('branch_id', $seed->branch_id)
            ->where('branch_group_id', $seed->branch_group_id);

        if ($seed->effective_from === null) {
            $query->whereNull('effective_from');
        } else {
            $query->whereDate('effective_from', optional($seed->effective_from)->toDateString());
        }

        if ($seed->effective_to === null) {
            $query->whereNull('effective_to');
        } else {
            $query->whereDate('effective_to', optional($seed->effective_to)->toDateString());
        }

        $rows = $query->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'source' => $type,
            'type' => $type,
            'user_id' => $seed->user_id ? (int) $seed->user_id : null,
            'role' => (string) $seed->role_slug,
            'branch_id' => $seed->branch_id ? (int) $seed->branch_id : null,
            'branch_group_id' => $seed->branch_group_id ? (int) $seed->branch_group_id : null,
            'items' => $this->serializePlanRows($rows, $type)->values()->all(),
        ];
    }

    public function upsertCommonPlans(User $actor, array $payload): Collection
    {
        $role = (string) $payload['role'];
        $from = (string) $payload['effective_from'];
        $to = $payload['effective_to'] ?? null;
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $branchGroupId = isset($payload['branch_group_id']) ? (int) $payload['branch_group_id'] : null;

        $existing = KpiPlan::query()
            ->whereNull('user_id')
            ->where('role_slug', $role)
            ->where('branch_id', $branchId)
            ->where('branch_group_id', $branchGroupId)
            ->where(function ($q) use ($from, $to) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
            })
            ->where(function ($q) use ($to) {
                if ($to === null) {
                    $q->whereNotNull('id');
                    return;
                }

                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to);
            })
            ->get();

        $samePeriod = $existing->every(function (KpiPlan $plan) use ($from, $to) {
            return optional($plan->effective_from)->toDateString() === $from
                && optional($plan->effective_to)->toDateString() === $to;
        });

        if ($existing->isNotEmpty() && ! $samePeriod) {
            throw new \DomainException('Plan period conflicts with an existing common KPI plan interval.');
        }

        KpiPlan::query()
            ->whereNull('user_id')
            ->where('role_slug', $role)
            ->where('branch_id', $branchId)
            ->where('branch_group_id', $branchGroupId)
            ->whereDate('effective_from', $from)
            ->where(function ($q) use ($to) {
                if ($to === null) {
                    $q->whereNull('effective_to');
                    return;
                }

                $q->whereDate('effective_to', $to);
            })
            ->delete();

        foreach ((array) $payload['items'] as $item) {
            $newPlan = KpiPlan::query()->create($this->buildPlanWritePayload([
                'role_slug' => $role,
                'user_id' => null,
                'branch_id' => $branchId,
                'branch_group_id' => $branchGroupId,
                'metric_key' => (string) $item['metric_key'],
                'daily_plan' => $this->planValueFromItem((array) $item),
                'weight' => (float) $item['weight'],
                'comment' => $item['comment'] ?? null,
                'effective_from' => $from,
                'effective_to' => $to,
            ]));

            $this->auditLogger->log(
                $newPlan,
                $actor,
                'kpi_common_plan_upserted',
                [],
                [
                    'role' => $role,
                    'branch_id' => $branchId,
                    'branch_group_id' => $branchGroupId,
                    'metric_key' => (string) $item['metric_key'],
                    'monthly_plan' => $this->planValueFromItem((array) $item),
                    'weight' => (float) $item['weight'],
                    'comment' => $item['comment'] ?? null,
                    'effective_from' => $from,
                    'effective_to' => $to,
                ],
                'KPI common plan upserted'
            );
        }

        return $this->commonPlans($role, Carbon::parse($from, self::TZ), $branchId, $branchGroupId);
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
                    'deals_count' => $this->normalizeNumber($rows->sum(fn (DailyReport $row) => $this->dealMetricValue($row))),
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
                (float) ($report ? $this->dealMetricValue($report) : ($auto['sales_count'] ?? $auto['deals_count'] ?? 0)),
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

    public function myDailyProgressStrict(User $authUser, Carbon $date, array $filters = []): array
    {
        $targetUser = $this->resolveTargetUser($authUser, isset($filters['user_id']) ? (int) $filters['user_id'] : null);
        $report = DailyReport::query()
            ->where('user_id', (int) $targetUser->id)
            ->whereDate('report_date', $date->toDateString())
            ->first();

        $auto = $this->dailyReportService->autoMetrics($targetUser, $date->toDateString());
        $targetResolution = $this->resolvePeriodTargetMapForUser($targetUser, $date, (array) config('kpi.v2.targets', []));
        $monthlyTargets = (array) ($targetResolution['targets'] ?? []);
        $daysInMonth = max(1, (int) $date->daysInMonth);

        $targetForDay = function (string $metricKey) use ($monthlyTargets, $daysInMonth): float {
            $monthly = (float) ($monthlyTargets[$metricKey] ?? 0);
            if ($metricKey === 'sales') {
                return $monthly;
            }

            return $monthly / $daysInMonth;
        };

        $metrics = [
            'objects' => $this->metricProgress(
                (float) ($auto['new_properties_count'] ?? 0),
                $targetForDay('objects')
            ),
            'shows' => $this->metricProgress(
                (float) ($auto['shows_count'] ?? 0),
                $targetForDay('shows')
            ),
            'ads' => $this->metricProgress(
                (float) ($report?->ad_count ?? 0),
                $targetForDay('ads')
            ),
            'calls' => $this->metricProgress(
                (float) ($report?->calls_count ?? 0),
                $targetForDay('calls')
            ),
            'sales' => $this->metricProgress(
                (float) ($auto['sales_count'] ?? $auto['deals_count'] ?? 0),
                $targetForDay('sales')
            ),
        ];

        $overallProgressPct = round((float) collect($metrics)->avg('progress_pct'), 2);

        return [
            'date' => $date->toDateString(),
            'timezone' => self::TZ,
            'submitted_daily_report' => (bool) ($report?->submitted_at !== null),
            'overall_progress_pct' => $overallProgressPct,
            'status' => $this->statusForKpiPercent($overallProgressPct),
            'metrics' => $metrics,
        ];
    }

    public function weeklyStrict(User $authUser, Carbon $day, array $filters = []): array
    {
        $start = $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $periodKey = $start->format('o-\WW');
        $strict = $this->strictPeriodRows($authUser, 'week', $start, $end, $filters);

        return [
            'meta' => [
                'period_type' => 'week',
                'period_key' => $periodKey,
                'timezone' => self::TZ,
            ],
            'rows' => $strict,
        ];
    }

    public function monthlyStrict(User $authUser, Carbon $monthStart, array $filters = []): array
    {
        $start = $monthStart->copy()->startOfMonth()->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();
        $periodKey = $start->format('Y-m');
        $strict = $this->strictPeriodRows($authUser, 'month', $start, $end, $filters);

        return [
            'meta' => [
                'period_type' => 'month',
                'period_key' => $periodKey,
                'timezone' => self::TZ,
            ],
            'rows' => $strict,
        ];
    }

    public function metricMapping(): array
    {
        $labels = [
            'objects' => 'Объекты',
            'shows' => 'Показы',
            'ads' => 'Реклама',
            'calls' => 'Звонки',
            'sales' => 'Сделки',
        ];
        $descriptions = [
            'objects' => 'Количество добавленных объектов за период.',
            'shows' => 'Количество проведённых показов за период.',
            'ads' => 'Количество рекламных активностей за период.',
            'calls' => 'Количество звонков за период.',
            'sales' => 'Количество завершённых сделок за период.',
        ];
        $mapping = (array) config('kpi.v2.metric_mapping', []);

        foreach ((array) config('kpi.v2.metric_keys', []) as $key) {
            $mapping[$key] = array_merge((array) ($mapping[$key] ?? []), [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'description' => $descriptions[$key] ?? null,
            ]);
        }

        return [
            'metric_keys' => (array) config('kpi.v2.metric_keys', []),
            'mapping' => $mapping,
        ];
    }

    public function eligibleUsers(User $actor, array $filters): array
    {
        $actor->loadMissing('role');
        $selectColumns = ['users.id', 'users.name', 'users.phone', 'users.branch_id', 'users.branch_group_id', 'users.role_id'];
        if (Schema::hasColumn('users', 'email')) {
            $selectColumns[] = 'users.email';
        }
        $query = User::query()
            ->select($selectColumns)
            ->with('role:id,slug');

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('users.name', 'like', '%'.$q.'%')
                    ->orWhere('users.phone', 'like', '%'.$q.'%');
                if (Schema::hasColumn('users', 'email')) {
                    $inner->orWhere('users.email', 'like', '%'.$q.'%');
                }
            });
        }

        if (!empty($filters['role'])) {
            $query->whereHas('role', fn (Builder $roleQ) => $roleQ->where('slug', (string) $filters['role']));
        }
        if (!empty($filters['branch_id'])) {
            $query->where('users.branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['branch_group_id'])) {
            $query->where('users.branch_group_id', (int) $filters['branch_group_id']);
        }

        match ($actor->role?->slug) {
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->where('users.branch_id', (int) $actor->branch_id),
            'mop' => $query
                ->where('users.branch_group_id', (int) $actor->branch_group_id)
                ->whereHas('role', fn (Builder $roleQ) => $roleQ->whereIn('slug', ['agent', 'intern'])),
            default => $query->where('users.id', (int) $actor->id),
        };

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query->orderBy('users.name')->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => (string) ($user->role?->slug ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'email' => (string) ($user->email ?? ''),
                'branch_id' => $user->branch_id !== null ? (int) $user->branch_id : null,
                'branch_group_id' => $user->branch_group_id !== null ? (int) $user->branch_group_id : null,
            ])->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function applyCommonPlanToUsers(User $actor, array $payload): array
    {
        $role = (string) $payload['role'];
        $from = Carbon::parse((string) $payload['effective_from'], self::TZ);
        $commonItems = $this->commonPlans(
            $role,
            $from,
            isset($payload['branch_id']) ? (int) $payload['branch_id'] : null,
            isset($payload['branch_group_id']) ? (int) $payload['branch_group_id'] : null
        );

        if ($commonItems->isEmpty()) {
            return ['success_count' => 0, 'failed_count' => 0, 'results' => []];
        }

        $query = User::query()->with('role')->whereHas('role', fn (Builder $q) => $q->where('slug', $role));
        if (isset($payload['branch_id'])) {
            $query->where('branch_id', (int) $payload['branch_id']);
        }
        if (isset($payload['branch_group_id'])) {
            $query->where('branch_group_id', (int) $payload['branch_group_id']);
        }
        if (!empty($payload['user_ids'])) {
            $query->whereIn('id', array_map('intval', (array) $payload['user_ids']));
        }

        $results = [];
        foreach ($query->get() as $targetUser) {
            try {
                $this->upsertUserPlans($actor, (int) $targetUser->id, [
                    'effective_from' => $payload['effective_from'],
                    'effective_to' => $payload['effective_to'] ?? null,
                    'items' => $commonItems->map(fn (array $item) => [
                        'metric_key' => (string) $item['metric_key'],
                        'daily_plan' => (float) $item['daily_plan'],
                        'weight' => (float) $item['weight'],
                        'comment' => $item['comment'] ?? null,
                    ])->values()->all(),
                ]);
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => true];
            } catch (\DomainException $e) {
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => false, 'code' => 'KPI_PLAN_PERIOD_CONFLICT', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => false, 'code' => 'KPI_CONFLICT', 'message' => $e->getMessage()];
            }
        }

        return [
            'success_count' => collect($results)->where('ok', true)->count(),
            'failed_count' => collect($results)->where('ok', false)->count(),
            'results' => $results,
        ];
    }

    public function upsertDailyRowsV2(User $authUser, array $rows): array
    {
        $saved = [];

        foreach ($rows as $row) {
            $employeeId = (int) Arr::get($row, 'employee_id');
            $date = (string) Arr::get($row, 'date');
            $targetUser = User::query()->with('role')->findOrFail($employeeId);
            $this->ensureCanUpsertDailyForUser($authUser, $targetUser);
            $writePayload = [
                'role_slug' => (string) (Arr::get($row, 'role') ?: $targetUser->role?->slug),
                'ad_count' => (int) Arr::get($row, 'ads', Arr::get($row, 'advertisement', 0)),
                'calls_count' => (int) Arr::get($row, 'calls', Arr::get($row, 'call', 0)),
                'new_clients_count' => (int) Arr::get($row, 'kabul', 0),
                'shows_count' => (int) Arr::get($row, 'shows', Arr::get($row, 'show', 0)),
                'new_properties_count' => (int) Arr::get($row, 'objects', Arr::get($row, 'lead', 0)),
                'deposits_count' => (int) Arr::get($row, 'deposit', 0),
                'deals_count' => (int) floor((float) Arr::get($row, 'sales', Arr::get($row, 'deal', 0))),
                'comment' => Arr::get($row, 'comment'),
                'submitted_at' => now(),
            ];
            if (Schema::hasColumn('daily_reports', 'sales_count')) {
                $writePayload['sales_count'] = (float) Arr::get($row, 'sales', Arr::get($row, 'deal', 0));
            }

            $report = DailyReport::query()->updateOrCreate(
                [
                    'user_id' => $employeeId,
                    'report_date' => $date,
                ],
                $writePayload
            );

            $saved[] = [
                'date' => $report->report_date->toDateString(),
                'role' => $report->role_slug,
                'employee_id' => $employeeId,
                'employee_name' => (string) ($targetUser->name ?? Arr::get($row, 'employee_name', '')),
                'group_name' => (string) Arr::get($row, 'group_name', ''),
                'objects' => (int) $report->new_properties_count,
                'shows' => (int) $report->shows_count,
                'ads' => (int) $report->ad_count,
                'calls' => (int) $report->calls_count,
                'sales' => (float) ($report->sales_count ?? $report->deals_count),
                'comment' => (string) ($report->comment ?? ''),
            ];
        }

        return $saved;
    }

    public function dailyRowsV2(User $authUser, Carbon $date, array $filters): array
    {
        $from = $this->rangeDateFromFilters($filters, $date->copy()->startOfDay());
        $to = $this->rangeDateToFilters($filters, $date->copy()->endOfDay());

        return $this->buildScopedPeriodV2Response($authUser, $from, $to, $filters, 'day', false, false);
    }

    public function periodRowsV2(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): array
    {
        $periodKey = $periodType === 'week'
            ? $from->format('o-\WW')
            : $from->format('Y-m');

        $withBreakdown = (bool) ($filters['include_breakdown'] ?? false);

        return $this->buildV2Response($authUser, $periodType, $from, $to, $filters, $periodKey, $withBreakdown);
    }

    public function weeklyDailyRowsV2(User $authUser, Carbon $day, array $filters): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $withBreakdown = (bool) ($filters['include_breakdown'] ?? false);

        return $this->buildV2Response($authUser, 'day', $from, $to, $filters, $from->toDateString(), $withBreakdown);
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
        if (in_array($periodType, ['week', 'month'], true)) {
            return $this->buildScopedPeriodV2Response(
                $authUser,
                $from,
                $to,
                $filters,
                $periodType,
                $withBreakdown,
                $periodType === 'week'
            );
        }

        $query = DailyReport::query()
            ->with(['user.role', 'user.branch', 'user.branchGroup'])
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString());
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
            ->map(function (Collection $rows) use ($periodType, $from, $to, $mapping, $targetMap, $weightMap, $withBreakdown, &$globalSourceError) {
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

                $daysInPeriod = max(1, $from->diffInDays($to) + 1);
                $metrics = $this->buildMetricsForRows($rows, $autoByDate, $sourceErrors, $mapping, $targetMap, $periodType, $daysInPeriod);
                if (
                    $periodType === 'day'
                    && $user
                    && in_array((string) ($metrics['sales']['plan_source'] ?? 'system'), ['personal', 'common'], true)
                ) {
                    $this->overrideDailySalesMetricWithMonthlyCompletion($metrics, $user, $from);
                }
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
                    'role' => (string) ($user?->role?->slug ?? ''),
                    'metrics' => $metrics,
                    'objects_raw' => (float) ($metrics['objects']['final_value'] ?? 0),
                    'objects_display' => $this->displayNumber((float) ($metrics['objects']['final_value'] ?? 0)),
                    'shows_raw' => (float) ($metrics['shows']['final_value'] ?? 0),
                    'shows_display' => $this->displayNumber((float) ($metrics['shows']['final_value'] ?? 0)),
                    'ads_raw' => (float) ($metrics['ads']['final_value'] ?? 0),
                    'ads_display' => $this->displayNumber((float) ($metrics['ads']['final_value'] ?? 0)),
                    'calls_raw' => (float) ($metrics['calls']['final_value'] ?? 0),
                    'calls_display' => $this->displayNumber((float) ($metrics['calls']['final_value'] ?? 0)),
                    'sales_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                    'sales_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                    'sales_count_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                    'sales_count_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                    'objects' => (float) ($metrics['objects']['final_value'] ?? 0),
                    'shows' => (float) ($metrics['shows']['final_value'] ?? 0),
                    'ads' => (float) ($metrics['ads']['final_value'] ?? 0),
                    'calls' => (float) ($metrics['calls']['final_value'] ?? 0),
                    'sales' => (float) ($metrics['sales']['final_value'] ?? 0),
                    'kpi_value' => $kpiValue,
                    'kpi_percent' => $kpiPercent,
                    'average_kpi_percent' => $kpiPercent,
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
        $qualityIssuesCount = Schema::hasTable('kpi_quality_issues')
            ? KpiQualityIssue::query()
                ->where('status', 'open')
                ->whereDate('detected_at', '>=', $from->toDateString())
                ->whereDate('detected_at', '<=', $to->toDateString())
                ->count()
            : 0;

        return [
            'data' => $data,
            'rows' => $data,
            'meta' => [
                'version' => '2',
                'period_type' => $periodType,
                'period_key' => $periodType === 'day' ? $from->toDateString() : null,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'locked' => $this->isPeriodLocked($periodType, $from, $filters),
                'quality' => [
                    'duplicate_check_passed' => true,
                    'completeness_pct' => $completeness,
                    'source_error' => $globalSourceError,
                    'issues_count' => $qualityIssuesCount,
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

    private function buildScopedPeriodV2Response(
        User $authUser,
        Carbon $from,
        Carbon $to,
        array $filters,
        string $periodType,
        bool $withBreakdown,
        bool $includeWeeklyStats
    ): array {
        $perPage = (int) ($filters['per_page'] ?? 50);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $usersPaginator = $this->weeklyUsersScopeQuery($authUser, $filters)
            ->orderBy('users.name')
            ->paginate($perPage, ['*'], 'page', $page);
        $userIds = collect($usersPaginator->items())->pluck('id')->map(fn ($id) => (int) $id)->values();

        $reports = DailyReport::query()
            ->with(['user.role', 'user.branch', 'user.branchGroup'])
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->when($userIds->isNotEmpty(), fn (Builder $q) => $q->whereIn('user_id', $userIds->all()))
            ->get()
            ->groupBy('user_id');

        $mapping = (array) config('kpi.v2.metric_mapping', []);
        $defaultTargetMap = (array) config('kpi.v2.targets', []);
        $weightMap = (array) config('kpi.v2.weights', []);
        $daysInPeriod = max(1, $from->diffInDays($to) + 1);
        $globalSourceError = false;
        $debugPlanTrace = (bool) ($filters['debug_plan_trace'] ?? false);
        $traceId = (string) (request()->attributes->get('trace_id') ?? '');
        $planTraceRows = [];
        $preloadedSalesByUserDate = $this->preloadSalesCreditsByUserDate($userIds->all(), $from, $to);

        $data = collect($usersPaginator->items())->map(function (User $user) use ($reports, $periodType, $from, $to, $mapping, $defaultTargetMap, $weightMap, $withBreakdown, $daysInPeriod, $includeWeeklyStats, $preloadedSalesByUserDate, &$globalSourceError, &$planTraceRows) {
            $rows = collect($reports->get($user->id, collect()));
            $autoByDate = [];
            $sourceErrors = [];
            $targetResolution = $this->resolvePeriodTargetMapForUser($user, $from, $defaultTargetMap);
            $targetMap = $targetResolution['targets'];
            $metricPlanSourceMap = $targetResolution['sources'];
            $metricPlanDailyMap = $targetResolution['plan_daily_values'];
            $metricPlanMetaMap = $targetResolution['plan_meta'];

            $periodStart = $from->copy()->startOfDay();
            $periodEnd = $to->copy()->startOfDay();
            for ($date = $periodStart; $date->lte($periodEnd); $date->addDay()) {
                $dayKey = $date->toDateString();
                try {
                    $autoByDate[$dayKey] = $this->dailyReportService->autoMetrics($user, $dayKey);
                } catch (Throwable) {
                    $salesCredit = (float) ($preloadedSalesByUserDate[(int) $user->id][$dayKey] ?? 0.0);
                    $autoByDate[$dayKey] = [
                        'ad_count' => 0,
                        'calls_count' => 0,
                        'meetings_count' => 0,
                        'shows_count' => 0,
                        'new_clients_count' => 0,
                        'new_properties_count' => 0,
                        'deals_count' => (int) floor($salesCredit),
                        'sales_count' => $salesCredit,
                    ];
                    $sourceErrors[$dayKey] = true;
                }
            }

            $metrics = $this->buildMetricsForRows($rows, $autoByDate, $sourceErrors, $mapping, $targetMap, $periodType, $daysInPeriod, $metricPlanSourceMap);
            $kpiValue = $this->kpiValueFromMetrics($metrics, $weightMap);
            $kpiPercent = round($kpiValue * 100, 1);
            $status = $this->statusForKpiPercent($kpiPercent);
            $locked = $this->isPeriodLockedForUser($periodType, $from, $user);
            $rowSourceError = collect($metrics)->contains(fn (array $metric) => (bool) $metric['source_error']);
            $globalSourceError = $globalSourceError || $rowSourceError;

            foreach ($metrics as $metricKey => $metricPayload) {
                $planTraceRows[] = [
                    'employee_id' => (int) $user->id,
                    'role' => (string) ($user->role?->slug ?? ''),
                    'metric' => (string) $metricKey,
                    'target_value' => $metricPayload['target_value'],
                    'plan_source' => (string) ($metricPayload['plan_source'] ?? 'system'),
                    'plan_daily_value' => $metricPlanDailyMap[$metricKey] ?? null,
                    'plan_meta' => $metricPlanMetaMap[$metricKey] ?? null,
                ];
            }
            $dailyReportStats = $includeWeeklyStats
                ? $this->weeklyDailyReportStats($rows, $from)
                : null;
            $submittedDailyReport = $periodType === 'day'
                && $rows->contains(fn (DailyReport $row) => $row->report_date->toDateString() === $from->toDateString() && $row->submitted_at !== null);

            $payload = [
                'period_key' => match ($periodType) {
                    'day' => $from->toDateString(),
                    'week' => $from->format('o-\WW'),
                    default => $from->format('Y-m'),
                },
                'week' => $periodType === 'week' ? (int) $from->isoWeek() : null,
                'month' => $periodType === 'month' ? (int) $from->month : null,
                'year' => $periodType === 'week' ? (int) $from->isoWeekYear() : (int) $from->year,
                'employee_id' => (int) $user->id,
                'employee_name' => (string) $user->name,
                'role' => (string) ($user->role?->slug ?? ''),
                'agent_id' => $user->role?->slug === 'agent' ? (int) $user->id : null,
                'agent_name' => $user->role?->slug === 'agent' ? (string) $user->name : null,
                'mop_id' => $user->role?->slug === 'mop' ? (int) $user->id : null,
                'mop_name' => $user->role?->slug === 'mop' ? (string) $user->name : null,
                'group_id' => $user->branch_group_id,
                'group_name' => $user->branchGroup?->name,
                'branch_id' => $user->branch_id,
                'branch_name' => $user->branch?->name,
                'branch_group_id' => $user->branch_group_id,
                'metrics' => $metrics,
                'objects_raw' => (float) ($metrics['objects']['final_value'] ?? 0),
                'objects_display' => $this->displayNumber((float) ($metrics['objects']['final_value'] ?? 0)),
                'shows_raw' => (float) ($metrics['shows']['final_value'] ?? 0),
                'shows_display' => $this->displayNumber((float) ($metrics['shows']['final_value'] ?? 0)),
                'ads_raw' => (float) ($metrics['ads']['final_value'] ?? 0),
                'ads_display' => $this->displayNumber((float) ($metrics['ads']['final_value'] ?? 0)),
                'calls_raw' => (float) ($metrics['calls']['final_value'] ?? 0),
                'calls_display' => $this->displayNumber((float) ($metrics['calls']['final_value'] ?? 0)),
                'sales_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                'sales_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                'sales_count_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                'sales_count_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                'objects' => (float) ($metrics['objects']['final_value'] ?? 0),
                'shows' => (float) ($metrics['shows']['final_value'] ?? 0),
                'ads' => (float) ($metrics['ads']['final_value'] ?? 0),
                'calls' => (float) ($metrics['calls']['final_value'] ?? 0),
                'sales' => (float) ($metrics['sales']['final_value'] ?? 0),
                'kpi_value' => $kpiValue,
                'kpi_percent' => $kpiPercent,
                'overall_progress_pct' => $kpiPercent,
                'average_kpi_percent' => $kpiPercent,
                'status' => $status,
                'locked' => $locked,
                'submitted_daily_report' => (bool) $submittedDailyReport,
            ];

            if ($includeWeeklyStats && $dailyReportStats !== null) {
                $payload['submitted_days_count'] = $dailyReportStats['submitted_days_count'];
                $payload['required_days_count'] = $dailyReportStats['required_days_count'];
                $payload['missing_report_dates'] = $dailyReportStats['missing_report_dates'];
                $payload['sunday_submitted'] = $dailyReportStats['sunday_submitted'];
            }

            if ($withBreakdown) {
                $payload['breakdown_by_day'] = $this->breakdownByDay($rows, $mapping, $targetMap, $sourceErrors);
            }

            return $payload;
        })->values();

        $completeness = $this->completenessPct($data);
        $qualityIssuesCount = Schema::hasTable('kpi_quality_issues')
            ? KpiQualityIssue::query()
                ->where('status', 'open')
                ->whereDate('detected_at', '>=', $from->toDateString())
                ->whereDate('detected_at', '<=', $to->toDateString())
                ->count()
            : 0;

        if ($periodType === 'month') {
            $data = $data->sortByDesc(fn (array $row) => (float) ($row['average_kpi_percent'] ?? 0))->values();
        }

        if ($debugPlanTrace || $traceId !== '') {
            Log::info('kpi.v2.plan_source_trace', [
                'trace_id' => $traceId !== '' ? $traceId : null,
                'period_type' => $periodType,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'filters' => Arr::only($filters, ['role', 'branch_id', 'branch_group_id', 'assignee_id', 'agent_id', 'mop_id', 'page', 'per_page']),
                'sample' => array_slice($planTraceRows, 0, 100),
            ]);
        }

        return [
            'data' => $data,
            'rows' => $data,
            'meta' => [
                'version' => '2',
                'period_type' => $periodType,
                'period_key' => $periodType === 'day' ? $from->toDateString() : null,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'locked' => $this->isPeriodLocked($periodType, $from, $filters),
                'quality' => [
                    'duplicate_check_passed' => true,
                    'completeness_pct' => $completeness,
                    'source_error' => $globalSourceError,
                    'issues_count' => $qualityIssuesCount,
                ],
                'pagination' => [
                    'page' => $usersPaginator->currentPage(),
                    'per_page' => $usersPaginator->perPage(),
                    'total' => $usersPaginator->total(),
                    'last_page' => $usersPaginator->lastPage(),
                ],
                'debug' => $debugPlanTrace ? [
                    'trace_id' => $traceId !== '' ? $traceId : null,
                    'plan_source_samples' => array_slice($planTraceRows, 0, 100),
                ] : null,
            ],
        ];
    }

    private function preloadSalesCreditsByUserDate(array $userIds, Carbon $from, Carbon $to): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === [] || ! Schema::hasTable('properties') || ! Schema::hasColumn('properties', 'sold_at')) {
            return [];
        }

        $soldProperties = DB::table('properties')
            ->select(['id', 'sold_at', 'sale_user_id', 'agent_id', 'created_by'])
            ->whereIn('moderation_status', ['sold', 'rented'])
            ->whereBetween('sold_at', [
                $from->copy()->startOfDay()->setTimezone('UTC')->toDateTimeString(),
                $to->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString(),
            ])
            ->get();

        if ($soldProperties->isEmpty()) {
            return [];
        }

        $targetUsers = array_fill_keys($userIds, true);
        $result = [];
        $propertyIds = $soldProperties->pluck('id')->all();
        $participantsByProperty = collect();

        if (Schema::hasTable('property_agent_sales')) {
            $participantsByProperty = DB::table('property_agent_sales')
                ->select(['property_id', 'agent_id'])
                ->whereIn('property_id', $propertyIds)
                ->whereNotNull('agent_id')
                ->get()
                ->groupBy('property_id')
                ->map(fn (Collection $rows) => $rows->pluck('agent_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        }

        foreach ($soldProperties as $property) {
            $soldAt = $property->sold_at ? Carbon::parse((string) $property->sold_at, 'UTC')->setTimezone(self::TZ) : null;
            if (! $soldAt) {
                continue;
            }
            $dayKey = $soldAt->toDateString();

            $participants = $participantsByProperty->get($property->id, []);
            if (! empty($participants)) {
                $denominator = max(1, count($participants));
                foreach ($participants as $agentId) {
                    if (! isset($targetUsers[$agentId])) {
                        continue;
                    }
                    $result[$agentId][$dayKey] = round((float) ($result[$agentId][$dayKey] ?? 0.0) + (1 / $denominator), 4);
                }
                continue;
            }

            $saleUserId = (int) ($property->sale_user_id ?? 0);
            if ($saleUserId > 0 && isset($targetUsers[$saleUserId])) {
                $result[$saleUserId][$dayKey] = round((float) ($result[$saleUserId][$dayKey] ?? 0.0) + 1.0, 4);
                continue;
            }

            $agentId = (int) ($property->agent_id ?? 0);
            if ($agentId > 0 && isset($targetUsers[$agentId])) {
                $result[$agentId][$dayKey] = round((float) ($result[$agentId][$dayKey] ?? 0.0) + 1.0, 4);
                continue;
            }

            $creatorId = (int) ($property->created_by ?? 0);
            if ($creatorId > 0 && isset($targetUsers[$creatorId])) {
                $result[$creatorId][$dayKey] = round((float) ($result[$creatorId][$dayKey] ?? 0.0) + 1.0, 4);
            }
        }

        return $result;
    }

    private function buildMetricsForRows(
        Collection $rows,
        array $autoByDate,
        array $sourceErrors,
        array $mapping,
        array $targetMap,
        string $periodType,
        ?int $daysInPeriodOverride = null,
        array $metricPlanSourceMap = []
    ): array {
        $metrics = [];
        $daysInPeriod = max(1, $daysInPeriodOverride ?? $rows->count());
        $from = $rows->isNotEmpty()
            ? $rows->min(fn (DailyReport $row) => $row->report_date)?->copy()
            : null;
        $daysInMonth = $from ? max(1, $from->daysInMonth) : $daysInPeriod;

        foreach ($mapping as $metricKey => $cfg) {
            $column = $this->resolveMetricSourceColumn((string) ($cfg['source_column'] ?? ''));
            $sourceType = (string) ($cfg['source_type'] ?? 'manual');
            $monthlyTarget = (float) ($targetMap[$metricKey] ?? 0);
            $target = match ($periodType) {
                'month' => $monthlyTarget,
                'week' => ($monthlyTarget / $daysInMonth) * $daysInPeriod,
                default => $metricKey === 'sales'
                    ? $monthlyTarget
                    : ($monthlyTarget / $daysInMonth),
            };

            $manualValue = (float) $rows->sum($column);
            $factValue = 0.0;
            $sourceError = false;

            foreach (array_keys($autoByDate) as $dateKey) {
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
                // Mixed rule: manual override has priority, fallback to system value.
                $finalValue = $manualValue > 0 ? $manualValue : $factValue;
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
                'plan_source' => (string) ($metricPlanSourceMap[$metricKey] ?? 'system'),
            ];
        }

        return $metrics;
    }

    private function resolvePeriodTargetMapForUser(User $user, Carbon $date, array $defaultTargetMap): array
    {
        $daysInMonth = max(1, $date->daysInMonth);
        $targets = collect($defaultTargetMap)
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (float) $value * $daysInMonth])
            ->all();
        $sources = [];
        $planDailyValues = [];
        $planMeta = [];

        try {
            $effective = $this->plansForUser((int) $user->id, $date);
            foreach ($effective as $item) {
                $metricKey = (string) ($item['metric_key'] ?? '');
                if ($metricKey === '') {
                    continue;
                }
                $targets[$metricKey] = (float) ($item['daily_plan'] ?? ($targets[$metricKey] ?? 0));
                $sources[$metricKey] = (string) ($item['plan_source'] ?? $item['source'] ?? 'common');
                $planDailyValues[$metricKey] = $item['daily_plan'] ?? null;
                $planMeta[$metricKey] = [
                    'plan_id' => isset($item['id']) ? (int) $item['id'] : null,
                    'user_id' => isset($item['user_id']) ? (int) $item['user_id'] : null,
                    'branch_id' => isset($item['branch_id']) ? (int) $item['branch_id'] : null,
                    'branch_group_id' => isset($item['branch_group_id']) ? (int) $item['branch_group_id'] : null,
                    'effective_from' => $item['effective_from'] ?? null,
                    'effective_to' => $item['effective_to'] ?? null,
                ];
            }
        } catch (Throwable) {
            // Fallback to default KPI targets when personal/common plan is unavailable.
        }

        foreach (array_keys($targets) as $metricKey) {
            if (! isset($sources[$metricKey])) {
                $sources[$metricKey] = 'system';
                $planDailyValues[$metricKey] = null;
                $planMeta[$metricKey] = null;
            }
        }

        return [
            'targets' => $targets,
            'sources' => $sources,
            'plan_daily_values' => $planDailyValues,
            'plan_meta' => $planMeta,
        ];
    }

    private function weeklyUsersScopeQuery(User $authUser, array $filters): Builder
    {
        $authUser->loadMissing('role');
        $includedTargetRoles = ['agent', 'intern', 'mop'];

        $query = User::query()
            ->select('users.*')
            ->with(['role:id,slug', 'branch:id,name', 'branchGroup:id,branch_id,name'])
            ->whereHas('role', fn (Builder $q) => $q->whereIn('slug', $includedTargetRoles));

        if (! empty($filters['assignee_id'])) {
            $query->where('users.id', (int) $filters['assignee_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('users.id', (int) $filters['user_id']);
        }
        if (! empty($filters['agent_id'])) {
            $query->where('users.id', (int) $filters['agent_id']);
        }
        if (! empty($filters['mop_id'])) {
            $query->where('users.id', (int) $filters['mop_id']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('users.branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['branch_group_id'])) {
            $query->where('users.branch_group_id', (int) $filters['branch_group_id']);
        }

        if (! empty($filters['role'])) {
            $query->whereHas('role', fn (Builder $q) => $q->where('slug', (string) $filters['role']));
        }

        match ($authUser->role?->slug) {
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->where('users.branch_id', (int) $authUser->branch_id),
            'mop' => $query->where('users.branch_group_id', (int) $authUser->branch_group_id),
            default => $query->where('users.id', (int) $authUser->id),
        };

        return $query;
    }

    private function weeklyDailyReportStats(Collection $rows, Carbon $weekStart): array
    {
        $submittedByDate = $rows
            ->groupBy(fn (DailyReport $row) => $row->report_date->toDateString())
            ->map(fn (Collection $dayRows) => $dayRows->contains(fn (DailyReport $row) => $row->submitted_at !== null));

        $submittedDaysCount = 0;
        $missingDates = [];
        $sundaySubmitted = false;

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->copy()->addDays($offset);
            $date = $day->toDateString();
            $isSubmitted = (bool) ($submittedByDate[$date] ?? false);

            if ($day->isSunday()) {
                $sundaySubmitted = $isSubmitted;
                if ($isSubmitted) {
                    $submittedDaysCount++;
                }
                continue;
            }

            if ($isSubmitted) {
                $submittedDaysCount++;
            } else {
                $missingDates[] = $date;
            }
        }

        return [
            'submitted_days_count' => $submittedDaysCount,
            'required_days_count' => 7,
            'missing_report_dates' => $missingDates,
            'sunday_submitted' => $sundaySubmitted,
        ];
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
                    'date' => $day,
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
        if ($kpiPercent >= 40) {
            return self::STATUS_RISK;
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
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
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
            $query->whereHas('user.role', fn (Builder $q) => $q->where('slug', (string) $filters['role']));
        }

        match ($authUser->role?->slug) {
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function metricProgress(float $fact, float $target): array
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

    private function displayNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function resolveMetricSourceColumn(string $column): string
    {
        if ($column === 'sales_count' && !Schema::hasColumn('daily_reports', 'sales_count')) {
            return 'deals_count';
        }

        return $column;
    }

    private function dealMetricValue(DailyReport $row): float
    {
        if (Schema::hasColumn('daily_reports', 'sales_count')) {
            return (float) ($row->sales_count ?? 0);
        }

        return (float) ($row->deals_count ?? 0);
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

    private function ensureCanUpsertDailyForUser(User $authUser, User $targetUser): void
    {
        $this->ensurePlanScopeAccess($authUser, $targetUser);
    }

    private function strictPeriodRows(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): array
    {
        $v2 = $this->periodRowsV2($authUser, $periodType, $from, $to, array_merge($filters, [
            'per_page' => 200,
            'page' => 1,
        ]));
        $rows = collect((array) ($v2['rows'] ?? []));

        $reportedUserIds = DailyReport::query()
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->when(! empty($filters['agent_id']), fn (Builder $q) => $q->where('user_id', (int) $filters['agent_id']))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($reportedUserIds->isEmpty()) {
            return [];
        }

        return $rows
            ->filter(function ($row) use ($reportedUserIds) {
                if (! is_array($row)) {
                    return false;
                }

                return in_array((int) ($row['employee_id'] ?? 0), $reportedUserIds->all(), true);
            })
            ->map(function (array $row) use ($periodType, $from) {
                return [
                    $periodType === 'week' ? 'week' : 'month' => $periodType === 'week' ? (int) $from->isoWeek() : (int) $from->month,
                    'year' => $periodType === 'week' ? (int) $from->isoWeekYear() : (int) $from->year,
                    'employee_id' => (int) ($row['employee_id'] ?? 0),
                    'employee_name' => (string) ($row['employee_name'] ?? ''),
                    'objects' => $this->normalizeNumber((float) ($row['objects'] ?? 0)),
                    'shows' => $this->normalizeNumber((float) ($row['shows'] ?? 0)),
                    'ads' => $this->normalizeNumber((float) ($row['ads'] ?? 0)),
                    'calls' => $this->normalizeNumber((float) ($row['calls'] ?? 0)),
                    'sales' => $this->normalizeNumber((float) ($row['sales'] ?? 0)),
                    'sales_count_display' => $this->normalizeNumber((float) ($row['sales'] ?? 0)),
                    'kpi_percent' => (float) ($row['kpi_percent'] ?? 0),
                    'average_kpi_percent' => (float) ($row['average_kpi_percent'] ?? 0),
                    'status' => (string) ($row['status'] ?? self::STATUS_URGENT),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveTargetUser(User $authUser, ?int $targetUserId): User
    {
        if ($targetUserId === null || $targetUserId === (int) $authUser->id) {
            return $authUser;
        }

        $target = User::query()->with('role')->findOrFail($targetUserId);
        $authUser->loadMissing('role');
        $role = (string) ($authUser->role?->slug ?? '');

        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            return $target;
        }

        if (in_array($role, ['rop', 'branch_director'], true) && (int) $target->branch_id === (int) $authUser->branch_id) {
            return $target;
        }

        if ($role === 'mop' && (int) $target->branch_group_id === (int) $authUser->branch_group_id) {
            return $target;
        }

        abort(403, 'Forbidden.');
    }

    private function findCommonPlanRows(string $role, Carbon $date, ?int $branchId, ?int $branchGroupId): EloquentCollection
    {
        $queryBase = KpiPlan::query()
            ->whereNull('user_id')
            ->where('role_slug', $role)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString());
            });

        $scopes = [];

        if (in_array($role, ['agent', 'intern'], true)) {
            if ($branchGroupId !== null) {
                $scopes[] = ['branch_group_id' => $branchGroupId, 'branch_id' => $branchId];
            }
            if ($branchId !== null) {
                $scopes[] = ['branch_group_id' => null, 'branch_id' => $branchId];
            }
            $scopes[] = ['branch_group_id' => null, 'branch_id' => null];
        } elseif ($role === 'mop') {
            if ($branchGroupId !== null) {
                $scopes[] = ['branch_group_id' => $branchGroupId, 'branch_id' => $branchId];
            }
            if ($branchId !== null) {
                $scopes[] = ['branch_group_id' => null, 'branch_id' => $branchId];
            }
            $scopes[] = ['branch_group_id' => null, 'branch_id' => null];
        } else {
            if ($branchId !== null) {
                $scopes[] = ['branch_group_id' => null, 'branch_id' => $branchId];
            }
            $scopes[] = ['branch_group_id' => null, 'branch_id' => null];
        }

        foreach ($scopes as $scope) {
            $q = (clone $queryBase);
            if ($scope['branch_group_id'] !== null) {
                $q->where('branch_group_id', $scope['branch_group_id']);
            } else {
                $q->whereNull('branch_group_id');
            }

            if ($scope['branch_id'] !== null) {
                $q->where('branch_id', $scope['branch_id']);
            } else {
                $q->whereNull('branch_id');
            }

            $rows = $q->orderBy('id')->get();
            if ($rows->isNotEmpty()) {
                return $rows;
            }
        }

        return new EloquentCollection();
    }

    private function serializePlanRows(EloquentCollection $rows, string $source): Collection
    {
        return $rows
            ->filter(fn (KpiPlan $row) => in_array((string) $row->metric_key, self::PLAN_METRIC_WHITELIST, true))
            ->map(function (KpiPlan $row) use ($source) {
            return [
                'id' => $row->id,
                'role' => (string) $row->role_slug,
                'metric' => (string) $row->metric_key,
                'metric_key' => (string) $row->metric_key,
                'daily_plan' => (float) $row->daily_plan,
                'monthly_plan' => (float) $row->daily_plan,
                'weight' => (float) $row->weight,
                'comment' => (string) ($row->comment ?? ''),
                'effective_from' => optional($row->effective_from)->toDateString(),
                'effective_to' => optional($row->effective_to)->toDateString(),
                'updated_at' => optional($row->updated_at)?->toISOString(),
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'branch_group_id' => $row->branch_group_id ? (int) $row->branch_group_id : null,
                'user_id' => $row->user_id ? (int) $row->user_id : null,
                'plan_period' => (string) ($row->plan_period ?? 'month'),
                'source' => $source,
                'plan_source' => $source,
            ];
        })->values();
    }

    private function planValueFromItem(array $item): float
    {
        if (array_key_exists('monthly_plan', $item)) {
            return (float) $item['monthly_plan'];
        }

        return (float) ($item['daily_plan'] ?? 0);
    }

    private function buildPlanWritePayload(array $payload): array
    {
        if ($this->supportsPlanPeriodColumn()) {
            $payload['plan_period'] = 'month';
        }

        return $payload;
    }

    private function supportsPlanPeriodColumn(): bool
    {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $supports = Schema::hasTable('kpi_plans') && Schema::hasColumn('kpi_plans', 'plan_period');

        return $supports;
    }

    private function overrideDailySalesMetricWithMonthlyCompletion(array &$metrics, User $user, Carbon $date): void
    {
        if (! isset($metrics['sales'])) {
            return;
        }

        $monthStart = $date->copy()->startOfMonth()->toDateString();
        $monthEnd = $date->copy()->endOfMonth()->toDateString();

        $query = DailyReport::query()
            ->where('user_id', (int) $user->id)
            ->whereBetween('report_date', [$monthStart, $monthEnd]);

        $salesFact = Schema::hasColumn('daily_reports', 'sales_count')
            ? (float) $query->sum('sales_count')
            : (float) $query->sum('deals_count');

        $target = (float) ($metrics['sales']['target_value'] ?? 0);
        $progress = $target > 0 ? round(($salesFact / $target) * 100, 2) : 0.0;

        $metrics['sales']['fact_value'] = $this->normalizeNumber($salesFact);
        $metrics['sales']['final_value'] = $this->normalizeNumber($salesFact);
        $metrics['sales']['progress_pct'] = $progress;
    }

    private function ensurePlanScopeAccess(User $actor, User $target): void
    {
        $this->kpiPlanScopePolicy->ensureCanManageUserPlan($actor, $target);
    }
}
