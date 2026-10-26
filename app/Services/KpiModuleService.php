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
        private readonly KpiPlanScopePolicy $kpiPlanScopePolicy,
        private readonly SalesAttributionService $salesAttributionService
    )
    {
    }

    public function plans(string $role, ?int $groupId = null): Collection
    {
        $actor = auth()->user();
        $rop = $actor?->usesAssignedGroupScope() ?? false;
        if ($rop) $groupId = app(\App\Support\RopGroupAccess::class)->creationGroup($actor, $groupId);
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
            ? KpiPlan::query()->where('role_slug', $role)
                ->when($rop, fn ($query) => $query->where('branch_id', $actor->branch_id)->where('branch_group_id', $groupId)
                    ->whereNull('user_id')->whereNull('effective_from')->whereNull('effective_to'))
                ->orderBy('id')->get()->keyBy('metric_key')
            : collect();

        // Preserve legacy defaults while returning supported metrics written by the v2 API.
        foreach (self::PLAN_METRIC_WHITELIST as $metricKey) {
            if ($overrides->has($metricKey) && ! $base->contains('metric_key', $metricKey)) {
                $base->push(['role' => $role, 'metric_key' => $metricKey, 'daily_plan' => 0.0,
                    'weight' => 0.0, 'comment' => $metricKey]);
            }
        }

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

    public function upsertPlans(string $role, array $items, ?int $groupId = null): Collection
    {
        return DB::transaction(function () use ($role, $items, $groupId) {
            $actor = auth()->user();
            if ($actor) {
                [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($actor, null, null, $groupId);
                abort_unless($actor->hasPermission('kpi.plans.manage') && $actor->hasPermission('kpi.plans.legacy.manage'), 403, 'KPI_FORBIDDEN_ROLE_ACTION');
            }
            $scope = [];
            if ($actor?->usesAssignedGroupScope()) {
                $groupId = app(\App\Support\RopGroupAccess::class)->creationGroup($actor, $groupId);
                \App\Models\BranchGroup::query()->whereKey($groupId)->lockForUpdate()->firstOrFail();
                $scope = ['branch_id' => $actor->branch_id, 'branch_group_id' => $groupId,
                    'user_id' => null, 'effective_from' => null, 'effective_to' => null];
            }
            foreach ($items as $item) {
                $identity = ['role_slug' => $role, 'metric_key' => $item['metric_key']] + $scope;
                $plan = KpiPlan::query()->where($identity)->lockForUpdate()->first() ?? new KpiPlan($identity);
                $before = $plan->exists ? $plan->getAttributes() : [];
                $plan->fill($this->buildPlanWritePayload([
                    'daily_plan' => $this->planValueFromItem((array) $item),
                    'weight' => $item['weight'],
                    'comment' => $item['comment'] ?? null,
                ]));
                $changed = ! $plan->exists || $plan->isDirty();
                $plan->save();
                if ($changed) {
                    $this->auditLogger->log($plan, $actor, 'kpi_role_plan_upserted', $before, $plan->getAttributes(),
                        'KPI role plan updated', ['branch_id' => $plan->branch_id, 'branch_group_id' => $plan->branch_group_id]);
                }

            }
            return $this->plans($role, $groupId);
        });
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

        $personalRows = KpiPlan::query()->tap(fn ($query) => $this->scopePlanQuery($query))
            ->when(auth()->user()?->usesAssignedGroupScope(), fn ($query) => $query->where('branch_group_id', $user->branch_group_id))
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
        return DB::transaction(function () use ($actor, $userId, $payload) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [$userId]);
            $user = User::query()->with('role')->lockForUpdate()->findOrFail($userId);
            $this->ensurePlanScopeAccess($actor, $user);
            if (isset($payload['scope'])) {
                $this->kpiPlanScopePolicy->ensureTargetMatchesBulkScope($actor, $user, (array) $payload['scope']);
            }


            $from = (string) $payload['effective_from'];
            $to = $payload['effective_to'] ?? null;
            $replaceIfConflict = (bool) ($payload['replace_if_conflict'] ?? false);
            $conflictStrategy = (string) ($payload['conflict_strategy'] ?? ($replaceIfConflict ? 'replace' : 'error'));

            $conflictsQuery = KpiPlan::query()
                ->where('user_id', $userId)
                ->where('branch_id', $user->branch_id)
                ->where('branch_group_id', $user->branch_group_id)
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

            $hasConflicts = (clone $conflictsQuery)->lockForUpdate()->get(['id'])->isNotEmpty();

            if ($hasConflicts && $conflictStrategy !== 'replace') {
                throw new \DomainException('Plan period conflicts with an existing personal KPI plan interval.');
            }

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

            return $this->plansForUser($userId, Carbon::parse($from, self::TZ));
        });
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

        abort_unless($actor->hasPermission('kpi.plans.list.view'), 403);
        if ($actor->usesAssignedGroupScope()) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'kp.branch_group_id', 'kp.branch_id');
        }
        if ($actor->hasPermission('kpi.plans.list.scope.all')) {
            // No additional restriction; the ROP boundary above still applies.
        } elseif ($actor->hasPermission('kpi.plans.list.scope.branch')) {
            $query->where(function ($scope) use ($actor) {
                $scope->where(function ($q) use ($actor) {
                    $q->whereNotNull('kp.user_id')->where('u.branch_id', (int) $actor->branch_id);
                })->orWhere(function ($q) use ($actor) {
                    $q->whereNull('kp.user_id')->where(function ($q2) use ($actor) {
                        $q2->where('kp.branch_id', (int) $actor->branch_id)->orWhereNull('kp.branch_id');
                    });
                });
            });
        } elseif ($actor->hasPermission('kpi.plans.list.scope.group')) {
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
        } else {
            $query->whereRaw('1 = 0');
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
        $seed = KpiPlan::query()->tap(fn ($query) => $this->scopePlanQuery($query))->find($planId);
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
        return DB::transaction(function () use ($actor, $payload) {
            $groupId = isset($payload['branch_group_id']) ? (int) $payload['branch_group_id'] : null;
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($actor, null, null, $groupId);
            $this->kpiPlanScopePolicy->ensureCanManageCommonPlan($actor, $payload);
            if ($actor->usesAssignedGroupScope()) {
                $payload['branch_id'] = (int) $actor->branch_id;
            }
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
                ->lockForUpdate()->get();

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
        });
    }

    public function dailyRows(User $authUser, Carbon $date, array $filters): Collection
    {
        $query = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->with(['user.role'])->whereDate('report_date', $date->toDateString());
        $this->applyScope($query, $authUser, $filters);

        return $query->orderBy('user_id')->get();
    }

    public function periodRows(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->with(['user.role'])
            ->where('report_date', '>=', $from->toDateString())
            ->where('report_date', '<', $to->copy()->startOfDay()->addDay()->toDateString());
        $this->applyScope($query, $authUser, $filters);

        return $query->get()->groupBy(fn ($report) => $authUser->usesAssignedGroupScope() ? $report->user_id.'|'.$report->branch_group_id.'|'.$report->role_slug : $report->user_id)->map(function (Collection $rows) use ($periodType, $from, $to, $authUser) {
            $first = $rows->first();
            return [
                'period_type' => $periodType,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                ...($authUser->usesAssignedGroupScope() ? ['branch_group_id' => $first->branch_group_id, 'role_slug' => $first->role_slug] : []),
                'user' => [
                    'id' => $first?->user?->id,
                    'name' => $first?->user?->name,
                    ...($authUser->usesAssignedGroupScope() ? [] : ['role_slug' => $first?->user?->role?->slug]),
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
        $response = $this->dailyRowsV2($authUser, $date, array_merge($filters, ['page' => 1, 'per_page' => PHP_INT_MAX]));
        $rows = collect($response['data']);
        $ranking = $rows->sortByDesc('kpi_percent')->values()->map(function (array $row, int $i) use ($authUser) {
            $row['rank'] = $i + 1;
            $row['user'] = ['id' => $row['employee_id'], 'name' => $row['employee_name'],
                ...($authUser->usesAssignedGroupScope() ? [] : ['role_slug' => $row['role']])];
            return $row;
        });

        return [
            'summary' => [
                'date' => $date->toDateString(),
                'report_date' => $date->toDateString(),
                'rows_count' => $rows->count(),
                'totals' => $response['meta']['totals'],
                'total_objects' => $response['meta']['totals']['objects'],
                'total_calls' => $response['meta']['totals']['calls'],
                'total_sales' => $response['meta']['totals']['sales'],
                'average_kpi_percent' => $response['meta']['totals']['average_kpi_percent'],
            ],
            'ranking' => $ranking,
            'meta' => $response['meta'],
        ];
    }

    public function dashboardDebug(User $authUser, Carbon $date, array $filters): array
    {
        $base = $this->dashboard($authUser, $date, $filters);
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $sourceUsers = User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->whereIn('id', collect($base['ranking'])->pluck('user.id'))->get()->keyBy('id');
        $ranking = collect($base['ranking'])->map(function (array $row) use ($date, $authUser, $sourceUsers) {
            $userId = (int) ($row['user']['id'] ?? 0);
            $user = $sourceUsers->get($userId);
            if ($user && $authUser->usesAssignedGroupScope()) {
                $user = clone $user;
                $user->branch_group_id = $row['branch_group_id'];
                $user->branch_id = $authUser->branch_id;
            }
            $sourceCounts = $user ? $this->dailyReportService->autoMetrics($user, $date->toDateString()) : [];

            $row['source_counts'] = $sourceCounts;
            $row['stored_metrics_total'] = collect($row['metrics'])->sum(fn ($metric) => is_array($metric) ? (float) ($metric['final_value'] ?? 0) : (float) $metric);
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
        return DB::transaction(function () use ($payload) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire(auth()->user(), null, null, null);
            abort_unless($actor->hasPermission('kpi.operations.manage'), 403, 'KPI_FORBIDDEN_ROLE_ACTION');
            $config = $this->telegramConfig();
            $config->fill($payload)->save();

            return $config->fresh();
        });
    }

    public function periodContract(User $authUser, Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->periodRows($authUser, 'custom', $from, $to, $filters);
    }

    public function taskDailySummary(User $authUser, Carbon $date, array $filters): Collection
    {
        $start = $date->copy()->startOfDay();
        $query = CrmTask::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $start->copy()->addDay()->toDateTimeString());
        $this->applyTaskScope($query, $authUser, $filters);

        return $this->aggregateTaskSummary($query, ['date' => $date->toDateString()]);
    }

    public function taskWeeklySummary(User $authUser, int $year, int $week, array $filters): Collection
    {
        $start = Carbon::now(self::TZ)->setISODate($year, $week)->startOfWeek(Carbon::MONDAY);
        $query = CrmTask::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $start->copy()->addWeek()->toDateTimeString());
        $this->applyTaskScope($query, $authUser, $filters);

        return $this->aggregateTaskSummary($query, ['year' => $year, 'week' => $week]);
    }

    private function aggregateTaskSummary(Builder $query, array $period): Collection
    {
        return $query->select('assignee_id')
            ->selectRaw('COUNT(*) AS tasks_total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done_total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS overdue_total', ['done', 'overdue'])
            ->groupBy('assignee_id')->orderBy('assignee_id')->get()->map(fn ($row) => $period + [
                'assignee_id' => (int) $row->assignee_id,
                'tasks_total' => (int) $row->tasks_total,
                'done_total' => (int) $row->done_total,
                'overdue_total' => (int) $row->overdue_total,
            ]);
    }

    public function myDailyProgress(User $authUser, Carbon $date): array
    {
        abort_unless($authUser->hasPermission('kpi.reports.view'), 403, 'KPI_FORBIDDEN_SCOPE');
        $report = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
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
        $report = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->where('user_id', (int) $targetUser->id)
            ->whereDate('report_date', $date->toDateString())
            ->first();

        $auto = $this->dailyReportService->autoMetrics($targetUser, $date->toDateString());
        $bundle = $this->canonicalDailyMetricsBundle($targetUser, $date->toDateString(), $report, $auto);
        return [
            'date' => $date->toDateString(), 'timezone' => self::TZ,
            'submitted_daily_report' => $report?->submitted_at !== null,
            'overall_progress_pct' => $bundle['kpi_percent'], 'status' => $bundle['status'],
            'metrics' => collect($bundle['metrics'])->map(fn (array $metric) => $metric + ['fact' => $metric['final_value'], 'target' => $metric['target_value']])->all(),
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
        $query = User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
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

        if ($actor->usesAssignedGroupScope()) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'users.branch_group_id', 'users.branch_id')
                ->whereHas('role', fn (Builder $roles) => $roles->withPermission('kpi.subject.assigned_groups'));
        }
        if ($actor->hasPermission('kpi.plans.scope.all')) {
            // Every branch is available, subject to the ROP boundary above.
        } elseif ($actor->hasPermission('kpi.plans.scope.branch')) {
            $query->where('users.branch_id', (int) $actor->branch_id);
        } elseif ($actor->hasPermission('kpi.plans.scope.group')) {
            $query->where('users.branch_group_id', (int) $actor->branch_group_id)
                ->whereHas('role', fn (Builder $roles) => $roles->withPermission('kpi.subject.group_selection'));
        } else {
            $query->whereRaw('1 = 0');
        }

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
        $actor = User::query()->with('role')->findOrFail($actor->id);
        $this->kpiPlanScopePolicy->ensureCanManageCommonPlan($actor, $payload);
        if ($actor->usesAssignedGroupScope()) {
            $payload['branch_id'] = (int) $actor->branch_id;
        }
        $role = (string) $payload['role'];
        $from = Carbon::parse((string) $payload['effective_from'], self::TZ);

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
                $applied = DB::transaction(function () use ($actor, $targetUser, $payload, $role, $from) {
                    [$freshActor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany(
                        $actor, [], [$targetUser->id], [$payload['branch_group_id'] ?? null]
                    );
                    $this->kpiPlanScopePolicy->ensureCanManageCommonPlan($freshActor, $payload);
                    $commonItems = $this->commonPlans($role, $from, $payload['branch_id'] ?? null, $payload['branch_group_id'] ?? null);
                    if ($commonItems->isEmpty()) return false;
                    $this->upsertUserPlans($freshActor, (int) $targetUser->id, [
                        'scope' => array_intersect_key($payload, array_flip(['role', 'branch_id', 'branch_group_id'])),
                        'effective_from' => $payload['effective_from'],
                        'effective_to' => $payload['effective_to'] ?? null,
                        'items' => $commonItems->map(fn (array $item) => [
                            'metric_key' => (string) $item['metric_key'],
                            'daily_plan' => (float) $item['daily_plan'],
                            'weight' => (float) $item['weight'],
                            'comment' => $item['comment'] ?? null,
                        ])->values()->all(),
                    ]);
                    return true;
                });
                if (! $applied) continue;
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => true];
            } catch (\DomainException $e) {
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => false, 'code' => 'KPI_PLAN_PERIOD_CONFLICT', 'message' => $e->getMessage()];
            } catch (\Illuminate\Http\Exceptions\HttpResponseException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $status = $e instanceof \Illuminate\Http\Exceptions\HttpResponseException
                    ? $e->getResponse()->getStatusCode() : $e->getStatusCode();
                $results[] = ['user_id' => (int) $targetUser->id, 'ok' => false,
                    'code' => in_array($status, [403, 404], true) ? 'KPI_FORBIDDEN_SCOPE' : 'KPI_CONFLICT',
                    'message' => 'Unable to apply plan in current scope.'];
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
        return DB::transaction(function () use ($authUser, $rows) {
            [$authUser] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany(
                $authUser, [], array_column($rows, 'employee_id')
            );
            $saved = [];

            foreach ($rows as $row) {
                $employeeId = (int) Arr::get($row, 'employee_id');
                $date = (string) Arr::get($row, 'date');
                $targetUser = User::query()->with('role')->findOrFail($employeeId);
                $report = DailyReport::query()->where('user_id', $employeeId)->whereDate('report_date', $date)->lockForUpdate()->first();
                if ($authUser->usesAssignedGroupScope() && $report) {
                    app(\App\Support\RopGroupAccess::class)->ensureVisible($authUser, $report);
                    abort_unless($report->historicalRole?->hasPermission('kpi.subject.assigned_groups'), 404, 'NOT_FOUND');
                }
                $this->ensureCanUpsertDailyForUser($authUser, $targetUser, $report);
                if ($authUser->usesAssignedGroupScope()) {
                    abort_if(! $report && $date !== now(self::TZ)->toDateString(), 422, 'HISTORICAL_GROUP_UNCLASSIFIED');
                    $groupId = $report?->branch_group_id ?? $targetUser->branch_group_id;
                    foreach (['day', 'week', 'month'] as $periodType) {
                        abort_if($this->isPeriodLocked($periodType, Carbon::parse($date, self::TZ), ['branch_group_id' => $groupId]), 422, 'Period is locked. Use an adjustment.');
                    }
                }

                $writePayload = [
                    'role_slug' => (string) ($authUser->usesAssignedGroupScope() ? ($report?->role_slug ?? $targetUser->role?->slug) : (Arr::get($row, 'role') ?: $targetUser->role?->slug)),
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

                if ($authUser->usesAssignedGroupScope() && $report) {
                    $aliases = ['ad_count' => ['ads', 'advertisement'], 'calls_count' => ['calls', 'call'],
                        'new_clients_count' => ['kabul'], 'shows_count' => ['shows', 'show'], 'new_properties_count' => ['objects', 'lead'],
                        'deposits_count' => ['deposit'], 'deals_count' => ['sales', 'deal'], 'sales_count' => ['sales', 'deal'], 'comment' => ['comment']];
                    foreach ($aliases as $field => $inputs) {
                        if (array_intersect($inputs, array_keys($row)) === []) unset($writePayload[$field]);
                    }
                    $writePayload['submitted_at'] = $report->submitted_at ?? now();
                }

                // Date casts may persist a midnight timestamp (notably on SQLite).
                // Match the calendar day so a retry updates the same report.
                $report ??= new DailyReport(['user_id' => $employeeId, 'report_date' => $date]);
                if (! $report->exists && $authUser->usesAssignedGroupScope()) $report->branch_group_id = $targetUser->branch_group_id;
                $report->fill($writePayload)->save();

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
        });
    }

    public function dailyRowsV2(User $authUser, Carbon $date, array $filters): array
    {
        $from = $this->rangeDateFromFilters($filters, $date->copy()->startOfDay());
        $to = $this->rangeDateToFilters($filters, $date->copy()->endOfDay());

        return $this->buildScopedPeriodV2Response($authUser, $from, $to, $filters, 'day', (bool) ($filters['include_breakdown'] ?? false), false);
    }

    public function earliestScopedUserDate(User $authUser, array $filters): Carbon
    {
        $visibleRoles = app(KpiRoleCatalog::class)->reportPresentation($authUser)['visible_roles'];
        if ($visibleRoles !== null) $filters['roles'] = isset($filters['roles']) ? array_values(array_intersect($filters['roles'], $visibleRoles)) : $visibleRoles;
        $users = $this->weeklyUsersScopeQuery($authUser, $filters);
        $ids = (clone $users)->pluck('users.id')->all();
        $appTimezone = (string) config('app.timezone', self::TZ);
        $dates = [Carbon::now(self::TZ)->startOfDay()];
        $add = static function ($value, string $timezone) use (&$dates): void {
            if ($value !== null) $dates[] = Carbon::parse((string) $value, $timezone)->setTimezone(self::TZ)->startOfDay();
        };
        $add((clone $users)->min('users.created_at'), $appTimezone);
        $reports = DailyReport::query()->tap(fn ($query) => \App\Support\BranchVisibility::records($query));
        $this->applyScope($reports, $authUser, $filters);
        if (isset($filters['roles'])) $reports->whereIn('role_slug', $filters['roles']);
        $add($reports->min('report_date'), self::TZ);
        if ($ids !== []) {
            if (Schema::hasTable('properties')) {
                $properties = DB::table('properties')->tap(fn ($query) => \App\Support\BranchVisibility::records($query));
                if (Schema::hasColumn('properties', 'created_by')) {
                    $created = (clone $properties)->where(fn ($query) => $query->whereIn('created_by', $ids)
                        ->orWhere(fn ($legacy) => $legacy->whereIn('agent_id', $ids)->where(fn ($missing) => $missing->whereNull('created_by')->orWhere('created_by', 0))));
                    $add($created->min('created_at'), $appTimezone);
                }
                if (Schema::hasColumn('properties', 'sold_at')) {
                    $seller = $this->salesAttributionService->primarySellerExpression();
                    $add((clone $properties)->whereIn(DB::raw($seller), $ids)->where('moderation_status', 'sold')->min('sold_at'), $appTimezone);
                }
            }
            if (Schema::hasTable('clients')) {
                $add(DB::table('clients')->tap(fn ($query) => \App\Support\BranchVisibility::records($query))
                    ->where(fn ($query) => $query->whereIn('created_by', $ids)
                        ->orWhere(fn ($legacy) => $legacy->whereIn('responsible_agent_id', $ids)->where(fn ($missing) => $missing->whereNull('created_by')->orWhere('created_by', 0))))
                    ->min('created_at'), $appTimezone);
            }
            if (Schema::hasTable('bookings')) {
                $add(DB::table('bookings')->tap(fn ($query) => \App\Support\BranchVisibility::records($query))
                    ->whereIn(\App\Support\BookingAttribution::column(), $ids)->min('start_time'), 'UTC');
            }
            if (Schema::hasTable('crm_tasks')) {
                $add(DB::table('crm_tasks')->tap(fn ($query) => \App\Support\BranchVisibility::records($query))
                    ->whereIn('assignee_id', $ids)->where('status', 'done')->min('completed_at'), 'UTC');
            }
        }
        return collect($dates)->sort()->first();
    }

    public function periodRowsV2(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): array
    {
        return $this->buildScopedPeriodV2Response($authUser, $from, $to, $filters, $periodType,
            (bool) ($filters['include_breakdown'] ?? false), $periodType === 'week');
    }

    public function weeklyDailyRowsV2(User $authUser, Carbon $day, array $filters): array
    {
        return $this->dailyRowsV2($authUser, $day, $filters);
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
        $visibleRoles = app(KpiRoleCatalog::class)->reportPresentation($authUser)['visible_roles'];
        if ($visibleRoles !== null) {
            $filters['roles'] = isset($filters['roles']) ? array_values(array_intersect($filters['roles'], $visibleRoles)) : $visibleRoles;
        }
        $perPage = (int) ($filters['per_page'] ?? 50);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $allUsers = ($authUser->usesAssignedGroupScope() ? $this->historicalKpiUsersQuery($authUser, $filters, $from, $to) : $this->weeklyUsersScopeQuery($authUser, $filters))
            ->when(isset($filters['user_ids']), fn (Builder $query) => $query->whereIn('users.id', $filters['user_ids']))
            ->when(($filters['unified_employees'] ?? false) && ! ($filters['include_inactive'] ?? false), fn (Builder $query) => $query->where('users.status', '!=', 'inactive'))
            ->orderBy('users.name')
            ->orderBy('users.id')->get();
        foreach ($allUsers as $user) {
            if (array_key_exists('current_branch_group_id', $user->getAttributes())) {
                $historicalGroup = $user->branch_group_id;
                $user->branch_group_id = $user->current_branch_group_id;
                $user->syncOriginalAttribute('branch_group_id');
                $user->branch_group_id = $historicalGroup;
            }
        }
        // The general report has one row per person, even when a former employee
        // has several authorized historical group/role contexts in this period.
        $unifiedEmployees = (bool) ($filters['unified_employees'] ?? false);
        if ($unifiedEmployees) $allUsers = $allUsers->unique('id')->values();
        $userIds = $allUsers->pluck('id')->map(fn ($id) => (int) $id)->values();

        // Current team members have the same employee totals for every viewer.
        // Former members retain the restricted historical group context below.
        $currentContexts = $authUser->usesAssignedGroupScope()
            ? $this->weeklyUsersScopeQuery($authUser, $filters)->whereIn('users.id', $userIds->all())->get()
                ->mapWithKeys(fn (User $user) => [$user->id.'|'.$user->branch_group_id.'|'.$user->role?->slug => (int) $user->id])->all()
            : [];
        $currentUserIds = array_values(array_unique(array_values($currentContexts)));
        $currentReports = $currentUserIds === [] ? collect() : DailyReport::query()->with(['branchGroup', 'historicalRole'])->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->whereIn('user_id', $currentUserIds)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())->get()->groupBy('user_id');
        $currentAutoMetrics = $this->preloadAutoMetricsByUserDate($currentUserIds, $from, $to, $authUser, false);
        $currentSales = $this->preloadSalesCreditsByUserDate($currentUserIds, $from, $to, $authUser, false, (bool) ($filters['all_period'] ?? false));

        $reports = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->with(['user.role', 'user.branch', 'user.branchGroup', 'branchGroup', 'historicalRole'])
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->whereIn('user_id', $userIds->all())
            ->when($authUser->usesAssignedGroupScope(), fn (Builder $q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'daily_reports.branch_group_id'))
            ->when($unifiedEmployees && $authUser->usesAssignedGroupScope() && ! empty($filters['branch_group_id']),
                fn (Builder $q) => $q->where('daily_reports.branch_group_id', $filters['branch_group_id']))
            ->when($unifiedEmployees && isset($filters['roles']), fn (Builder $q) => $q->whereIn('role_slug', $filters['roles']))
            ->get()
            ->groupBy(fn ($row) => $authUser->usesAssignedGroupScope() && ! $unifiedEmployees ? $row->user_id.'|'.$row->branch_group_id : $row->user_id);

        // Do not call DailyReportService::autoMetrics() for every user/day.  That
        // method issues several queries per invocation, which made a 50-user
        // monthly page fan out into thousands of SQL statements.  Load each
        // source once for the whole page and aggregate it in memory.
        $autoMetricsByUserDate = $this->preloadAutoMetricsByUserDate($userIds->all(), $from, $to, $authUser);
        $authorizedPlans = $this->loadCanonicalPlans($allUsers, $from, $to);
        $lockedScopes = $this->preloadLockedScopes($periodType, $from);

        $mapping = (array) config('kpi.v2.metric_mapping', []);
        $defaultTargetMap = (array) config('kpi.v2.targets', []);
        $weightMap = (array) config('kpi.v2.weights', []);
        $daysInPeriod = max(1, $from->diffInDays($to) + 1);
        $globalSourceError = false;
        $debugPlanTrace = (bool) ($filters['debug_plan_trace'] ?? false);
        $debugFormulaTrace = (bool) ($filters['debug_kpi_trace'] ?? false);
        $traceId = (string) (request()->attributes->get('trace_id') ?? '');
        $planTraceRows = [];
        $preloadedSalesByUserDate = $this->preloadSalesCreditsByUserDate($userIds->all(), $from, $to, $authUser, true, (bool) ($filters['all_period'] ?? false));

        if ($unifiedEmployees && $authUser->usesAssignedGroupScope()) {
            if (! empty($filters['branch_group_id'])) {
                $inGroup = fn ($key) => (int) (explode('|', (string) $key)[1] ?? 0) === (int) $filters['branch_group_id'];
                $autoMetricsByUserDate = array_filter($autoMetricsByUserDate, $inGroup, ARRAY_FILTER_USE_KEY);
                $preloadedSalesByUserDate = array_filter($preloadedSalesByUserDate, $inGroup, ARRAY_FILTER_USE_KEY);
            }
            $autoMetricsByUserDate = $this->mergeEmployeeContexts($autoMetricsByUserDate);
            $preloadedSalesByUserDate = $this->mergeEmployeeContexts($preloadedSalesByUserDate);
        }

        $hasClients = Schema::hasTable('clients');
        $data = $allUsers->map(function (User $user) use ($unifiedEmployees, $hasClients, $currentContexts, $currentReports, $currentAutoMetrics, $currentSales, $authUser, $reports, $periodType, $from, $to, $mapping, $defaultTargetMap, $weightMap, $withBreakdown, $daysInPeriod, $includeWeeklyStats, $preloadedSalesByUserDate, $autoMetricsByUserDate, $authorizedPlans, $lockedScopes, &$globalSourceError, &$planTraceRows) {
            $contextKey = $authUser->usesAssignedGroupScope() && ! $unifiedEmployees ? $user->id.'|'.$user->branch_group_id : $user->id;
            $rows = collect($reports->get($contextKey, collect()));
            if ($authUser->usesAssignedGroupScope() && ! $unifiedEmployees) $rows = $rows->where('role_slug', $user->role?->slug)->values();
            $autoByDate = $autoMetricsByUserDate[$contextKey] ?? [];
            $currentMember = isset($currentContexts[$user->id.'|'.$user->branch_group_id.'|'.$user->role?->slug]);
            if ($currentMember) {
                $rows = $currentReports->get($user->id, collect());
                $autoByDate = $currentAutoMetrics[$user->id] ?? [];
            }
            $sourceErrors = [];
            $targetResolution = $this->periodPlanResolution($user, $from, $to, $periodType, $authorizedPlans, $withBreakdown, $rows);
            $targetMap = $targetResolution['monthly_targets'];
            $metricPlanSourceMap = $targetResolution['sources'];
            $metricPlanDailyMap = $targetResolution['plan_daily_values'];
            $metricPlanMetaMap = $targetResolution['plan_meta'];

            $salesByDate = $currentMember ? ($currentSales[$user->id] ?? []) : ($preloadedSalesByUserDate[$contextKey] ?? []);
            foreach ($salesByDate as $dayKey => $salesCredit) {
                $autoByDate[$dayKey] = array_merge($autoByDate[$dayKey] ?? [], [
                    'deals_count' => (int) floor($salesCredit),
                    'sales_count' => $salesCredit,
                ]);
            }

            foreach ($rows as $report) {
                $day = $report->report_date->toDateString();
                $autoByDate[$day] = $this->canonicalDailyFacts($user, $day, $report, $autoByDate[$day] ?? []);
            }
            $metrics = $this->buildMetricsForRows($rows, $autoByDate, $sourceErrors, $mapping, $targetMap, $periodType, $daysInPeriod, $metricPlanSourceMap, $from, $targetResolution['targets']);
            $score = $this->kpiScoreFromMetrics($metrics, $targetResolution['weights']);
            $kpiValue = $score['kpi_value'];
            $kpiPercent = $score['kpi_percent'];
            $status = $this->statusForKpiPercent($kpiPercent, $score);
            $locked = $periodType === 'day'
                ? $this->dailyReportService->isDateLocked($user, $from->toDateString())
                : $this->lockedForContext($lockedScopes, $user);
            $rowSourceError = collect($metrics)->contains(fn (array $metric) => (bool) $metric['source_error']);
            $globalSourceError = $globalSourceError || $rowSourceError;
            $liveClients = $hasClients
                ? (float) collect($autoByDate)->sum(
                    fn (array $dayMetrics) => (float) ($dayMetrics['new_clients_count'] ?? 0)
                )
                : (float) $rows->sum('new_clients_count');

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
                    'range' => 'all',
                    default => $from->format('Y-m'),
                },
                'week' => $periodType === 'week' ? (int) $from->isoWeek() : null,
                'month' => $periodType === 'month' ? (int) $from->month : null,
                'year' => match ($periodType) {
                    'week' => (int) $from->isoWeekYear(),
                    'month', 'day' => (int) $from->year,
                    default => null,
                },
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
                'clients_raw' => $liveClients,
                'clients_display' => $this->displayNumber($liveClients),
                'sales_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                'sales_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                'sales_count_raw' => (float) ($metrics['sales']['final_value'] ?? 0),
                'sales_count_display' => $this->displayNumber((float) ($metrics['sales']['final_value'] ?? 0)),
                'objects' => (float) ($metrics['objects']['final_value'] ?? 0),
                'shows' => (float) ($metrics['shows']['final_value'] ?? 0),
                'ads' => (float) ($metrics['ads']['final_value'] ?? 0),
                'calls' => (float) ($metrics['calls']['final_value'] ?? 0),
                'clients' => $liveClients,
                'sales' => (float) ($metrics['sales']['final_value'] ?? 0),
                'undated_sales_count' => (float) ($salesByDate['__undated__'] ?? 0),
                'kpi_value' => $kpiValue,
                'kpi_percent' => $kpiPercent,
                'overall_progress_pct' => $kpiPercent,
                'average_kpi_percent' => $kpiPercent,
                'status' => $status,
                'locked' => $locked,
                'submitted_daily_report' => (bool) $submittedDailyReport,
                'kpi_trace' => $score['trace'],
            ];

            if ($includeWeeklyStats && $dailyReportStats !== null) {
                $payload['submitted_days_count'] = $dailyReportStats['submitted_days_count'];
                $payload['required_days_count'] = $dailyReportStats['required_days_count'];
                $payload['missing_report_dates'] = $dailyReportStats['missing_report_dates'];
                $payload['sunday_submitted'] = $dailyReportStats['sunday_submitted'];
            }

            if ($withBreakdown) {
                $payload['breakdown_by_day'] = $this->breakdownByDay($rows, $mapping, $targetMap, $sourceErrors, $autoByDate, $targetResolution['by_date']);
            }

            return $payload;
        })->values();

        $completeness = $this->completenessPct($data);
        $qualityIssuesCount = Schema::hasTable('kpi_quality_issues')
            ? KpiQualityIssue::query()
                ->forRop($authUser, isset($filters['branch_group_id']) ? (int) $filters['branch_group_id'] : null)
                ->where('status', 'open')
                ->whereDate('detected_at', '>=', $from->toDateString())
                ->whereDate('detected_at', '<=', $to->toDateString())
                ->count()
            : 0;

        if (in_array($periodType, ['month', 'range'], true)) {
            $data = $data->sortByDesc(fn (array $row) => (float) ($row['average_kpi_percent'] ?? 0))->values();
        }
        $undatedSalesCount = (float) $data->sum('undated_sales_count');
        $anyLocked = $data->contains(fn (array $row) => (bool) $row['locked'])
            || $this->isPeriodLocked($periodType, $from, $filters);
        $totalRows = $data->count();
        $totals = collect(['objects', 'shows', 'ads', 'calls', 'sales', 'clients'])
            ->mapWithKeys(fn ($key) => [$key => $this->normalizeNumber((float) $data->sum($key))])->all();
        $totals['average_kpi_percent'] = round((float) ($data->avg('kpi_percent') ?? 0), 2);
        $totals['employees_count'] = $data->pluck('employee_id')->unique()->count();
        $data = $data->slice(($page - 1) * $perPage, $perPage)->values();

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

        if ($debugFormulaTrace || $traceId !== '') {
            Log::info('kpi.v2.formula_trace', [
                'trace_id' => $traceId !== '' ? $traceId : null,
                'period_type' => $periodType,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'filters' => Arr::only($filters, ['role', 'branch_id', 'branch_group_id', 'assignee_id', 'agent_id', 'mop_id', 'page', 'per_page']),
                'sample' => $data->map(fn (array $row) => [
                    'employee_id' => (int) ($row['employee_id'] ?? 0),
                    'kpi_percent' => (float) ($row['kpi_percent'] ?? 0),
                    'status' => (string) ($row['status'] ?? ''),
                    'kpi_trace' => (array) ($row['kpi_trace'] ?? []),
                ])->take(100)->values()->all(),
            ]);
        }

        return [
            'data' => $data,
            'rows' => $data,
            'meta' => [
                'version' => '2',
                'totals' => $totals,
                'period_type' => $periodType,
                'period_key' => match ($periodType) {
                    'day' => $from->toDateString(),
                    'range' => 'all',
                    default => null,
                },
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'locked' => $anyLocked,
                'quality' => [
                    'duplicate_check_passed' => true,
                    'completeness_pct' => $completeness,
                    'source_error' => $globalSourceError,
                    'issues_count' => $qualityIssuesCount,
                    'undated_sales_count' => $undatedSalesCount,
                ],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalRows,
                    'last_page' => max(1, (int) ceil($totalRows / $perPage)),
                ],
                'debug' => $debugPlanTrace ? [
                    'trace_id' => $traceId !== '' ? $traceId : null,
                    'plan_source_samples' => array_slice($planTraceRows, 0, 100),
                ] : null,
            ],
        ];
    }

    private function mergeEmployeeContexts(array $contexts): array
    {
        $result = [];
        foreach ($contexts as $context => $dates) {
            $id = (int) explode('|', (string) $context)[0];
            foreach ($dates as $date => $value) {
                if (is_array($value)) {
                    foreach ($value as $key => $count) $result[$id][$date][$key] = ($result[$id][$date][$key] ?? 0) + $count;
                } else {
                    $result[$id][$date] = ($result[$id][$date] ?? 0) + $value;
                }
            }
        }
        return $result;
    }

    private function preloadSalesCreditsByUserDate(array $userIds, Carbon $from, Carbon $to, User $authUser, bool $scopeRecords = true, bool $includeUndated = false): array
    {
        $historical = $scopeRecords && $authUser->usesAssignedGroupScope();
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === [] || ! Schema::hasTable('properties') || ! Schema::hasColumn('properties', 'sold_at')) {
            return [];
        }

        $select = ['id', 'sold_at', 'moderation_status', 'sale_user_id', 'agent_id', 'created_by'];
        if (Schema::hasColumn('properties', 'sale_agent_id')) {
            $select[] = 'sale_agent_id';
        }

        if ($historical) $select[] = 'branch_group_id';
        $soldProperties = DB::table('properties')->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->when($historical, fn ($q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'properties.branch_group_id'))
            ->select($select)
            ->where('moderation_status', 'sold')
            ->where(function ($query) use ($from, $to, $includeUndated) {
                $query->whereBetween('sold_at', [
                    $from->copy()->startOfDay()->setTimezone((string) config('app.timezone', self::TZ))->toDateTimeString(),
                    $to->copy()->endOfDay()->setTimezone((string) config('app.timezone', self::TZ))->toDateTimeString(),
                ]);
                if ($includeUndated) $query->orWhereNull('sold_at');
            })
            ->get();

        if ($soldProperties->isEmpty()) {
            return [];
        }

        $targetUsers = array_fill_keys($userIds, true);
        $result = [];
        $creditsByProperty = $this->salesAttributionService->creditsByProperty($soldProperties, ['sold']);

        foreach ($soldProperties as $property) {
            $soldAt = $property->sold_at ? Carbon::parse((string) $property->sold_at, (string) config('app.timezone', self::TZ))->setTimezone(self::TZ) : null;
            if (! $soldAt && ! $includeUndated) continue;
            $dayKey = $soldAt?->toDateString() ?? '__undated__';
            $propertyId = (int) ($property->id ?? 0);
            $credits = $creditsByProperty[$propertyId] ?? [];
            foreach ($credits as $agentId => $credit) {
                if (! isset($targetUsers[(int) $agentId])) {
                    continue;
                }
                $key = $historical ? $agentId.'|'.$property->branch_group_id : (int) $agentId;
                $result[$key][$dayKey] = round((float) ($result[$key][$dayKey] ?? 0.0) + (float) $credit, 4);
            }
        }

        return $result;
    }

    /**
     * Loads all automatic KPI facts for the paginated users in a bounded number
     * of index-friendly range queries. Dates are converted in PHP so the SQL
     * predicates remain sargable (no DATE()/CONVERT_TZ() on indexed columns).
     */
    private function preloadAutoMetricsByUserDate(array $userIds, Carbon $from, Carbon $to, User $authUser, bool $scopeRecords = true): array
    {
        $historical = $scopeRecords && $authUser->usesAssignedGroupScope();
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $start = $from->copy()->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $end = $to->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString();
        $appTimezone = (string) config('app.timezone', self::TZ);
        $localStart = $from->copy()->startOfDay()->setTimezone($appTimezone)->toDateTimeString();
        $localEnd = $to->copy()->endOfDay()->setTimezone($appTimezone)->toDateTimeString();
        $appDate = static fn ($value): string => Carbon::parse((string) $value, $appTimezone)->setTimezone(self::TZ)->toDateString();
        $result = [];
        $put = static function (array &$target, int|string $userId, string $date, string $metric, float $value): void {
            $target[$userId][$date][$metric] = (float) ($target[$userId][$date][$metric] ?? 0) + $value;
        };
        $localDate = static fn ($value): string => Carbon::parse((string) $value, 'UTC')->setTimezone(self::TZ)->toDateString();

        if (Schema::hasTable('crm_tasks') && Schema::hasTable('crm_task_types')) {
            $tasks = DB::table('crm_tasks')->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->when($historical, fn ($q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'crm_tasks.branch_group_id'))
                ->join('crm_task_types', 'crm_task_types.id', '=', 'crm_tasks.task_type_id')
                ->select(['crm_tasks.assignee_id', 'crm_tasks.completed_at', 'crm_task_types.code'])
                ->whereIn('crm_tasks.assignee_id', $userIds)
                ->where('crm_tasks.status', 'done')
                ->whereIn('crm_task_types.code', ['CALL', 'AD_PUBLICATION', 'AD_CREATE'])
                ->whereBetween('crm_tasks.completed_at', [$start, $end])
                ->when($historical, fn ($q) => $q->addSelect('crm_tasks.branch_group_id'))->get();
            foreach ($tasks as $task) {
                $metric = $task->code === 'CALL' ? 'calls_count' : 'ad_count';
                $put($result, $historical ? (int) $task->assignee_id.'|'.$task->branch_group_id : (int) $task->assignee_id, $localDate($task->completed_at), $metric, 1);
            }
        }

        if (Schema::hasTable('bookings')) {
            foreach (DB::table('bookings')->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->when($historical, fn ($q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'bookings.branch_group_id'))->select([\App\Support\BookingAttribution::column().' as agent_id', 'start_time'])
                ->whereIn(\App\Support\BookingAttribution::column(), $userIds)->whereBetween('start_time', [$start, $end])->when($historical, fn ($q) => $q->addSelect('bookings.branch_group_id'))->get() as $booking) {
                $date = $localDate($booking->start_time);
                $put($result, $historical ? (int) $booking->agent_id.'|'.$booking->branch_group_id : (int) $booking->agent_id, $date, 'shows_count', 1);
                $put($result, $historical ? (int) $booking->agent_id.'|'.$booking->branch_group_id : (int) $booking->agent_id, $date, 'meetings_count', 1);
            }
        }

        if (Schema::hasTable('clients')) {
            foreach (DB::table('clients')->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->when($historical, fn ($q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'clients.branch_group_id'))->select(['created_by', 'responsible_agent_id', 'created_at'])
                ->whereBetween('created_at', [$localStart, $localEnd])
                ->where(function ($q) use ($userIds) {
                    $q->whereIn('created_by', $userIds)->orWhere(fn ($legacy) => $legacy
                        ->whereIn('responsible_agent_id', $userIds)
                        ->where(fn ($missing) => $missing->whereNull('created_by')->orWhere('created_by', 0)));
                })->when($historical, fn ($q) => $q->addSelect('clients.branch_group_id'))->get() as $client) {
                $clientUserIds = [(int) $client->created_by > 0 ? (int) $client->created_by : (int) $client->responsible_agent_id];
                foreach ($clientUserIds as $userId) {
                    if ($userId <= 0 || ! in_array($userId, $userIds, true)) {
                        continue;
                    }
                    $put($result, $historical ? $userId.'|'.$client->branch_group_id : $userId, $appDate($client->created_at), 'new_clients_count', 1);
                }
            }
        }

        if (Schema::hasTable('properties')) {
            foreach (DB::table('properties')->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->when($historical, fn ($q) => app(\App\Support\RopGroupAccess::class)->scope($q, $authUser, 'properties.branch_group_id'))->select(['created_by', 'agent_id', 'created_at'])
                ->whereBetween('created_at', [$localStart, $localEnd])
                ->where(function ($q) use ($userIds) {
                    $q->whereIn('created_by', $userIds)->orWhere(function ($legacy) use ($userIds) {
                        $legacy->whereIn('agent_id', $userIds)->where(function ($creatorMissing) {
                            $creatorMissing->whereNull('created_by')->orWhere('created_by', 0);
                        });
                    });
                })->when($historical, fn ($q) => $q->addSelect('properties.branch_group_id'))->get() as $property) {
                $creator = (int) $property->created_by;
                $userId = $creator > 0 ? $creator : (int) $property->agent_id;
                if ($userId > 0) {
                    $put($result, $historical ? $userId.'|'.$property->branch_group_id : $userId, $appDate($property->created_at), 'new_properties_count', 1);
                }
            }
        }

        return $result;
    }

    private function defaultPeriodTargetMap(array $defaultTargetMap, Carbon $date): array
    {
        $targets = collect($defaultTargetMap)->mapWithKeys(fn ($value, $key) => [(string) $key => (float) $value * max(1, $date->daysInMonth)])->all();
        return ['targets' => $targets, 'sources' => array_fill_keys(array_keys($targets), 'system'), 'plan_daily_values' => array_fill_keys(array_keys($targets), null), 'plan_meta' => array_fill_keys(array_keys($targets), null)];
    }

    /** Resolve each metric independently, including its weight, at the date of the fact. */
    private function dailyPlanResolution(User $user, Carbon $date, Collection $plans): array
    {
        $defaults = (array) config('kpi.v2.targets', []);
        $weights = (array) config('kpi.v2.weights', []);
        $resolution = $this->defaultPeriodTargetMap($defaults, $date);
        $eligible = $plans->filter(function (KpiPlan $plan) use ($user, $date) {
            $day = $date->toDateString();
            if (($plan->effective_from && $plan->effective_from->toDateString() > $day)
                || ($plan->effective_to && $plan->effective_to->toDateString() < $day)) return false;
            if ($plan->user_id !== null) {
                return (int) $plan->user_id === (int) $user->id
                    && (! auth()->user()?->usesAssignedGroupScope()
                        || ((int) $plan->branch_group_id === (int) $user->branch_group_id && $plan->role_slug === $user->role?->slug));
            }
            return $plan->role_slug === $user->role?->slug
                && ($plan->branch_id === null || (int) $plan->branch_id === (int) $user->branch_id)
                && ($plan->branch_group_id === null || (int) $plan->branch_group_id === (int) $user->branch_group_id);
        })->sort(function (KpiPlan $a, KpiPlan $b) {
            $priority = static fn (KpiPlan $plan) => $plan->user_id !== null ? 3 : ($plan->branch_group_id !== null ? 2 : ($plan->branch_id !== null ? 1 : 0));
            return ($priority($b) <=> $priority($a)) ?: ($b->id <=> $a->id);
        })->groupBy('metric_key');
        foreach (array_keys($defaults) as $key) {
            $plan = $eligible->get($key, collect())->first();
            if (! $plan) continue;
            $resolution['targets'][$key] = (float) $plan->daily_plan;
            $resolution['sources'][$key] = $plan->user_id === null ? 'common' : 'personal';
            $resolution['plan_daily_values'][$key] = (float) $plan->daily_plan;
            $resolution['plan_meta'][$key] = ['plan_id' => (int) $plan->id, 'user_id' => $plan->user_id,
                'branch_id' => $plan->branch_id, 'branch_group_id' => $plan->branch_group_id,
                'effective_from' => optional($plan->effective_from)->toDateString(), 'effective_to' => optional($plan->effective_to)->toDateString()];
            $weights[$key] = max(0.0, (float) $plan->weight);
        }
        $sum = array_sum($weights);
        $resolution['weights'] = array_map(fn ($weight) => $sum > 0 ? $weight / $sum : 0.0, $weights);
        return $resolution;
    }

    private function periodPlanResolution(User $user, Carbon $from, Carbon $to, string $periodType, Collection $plans, bool $withBreakdown = false, ?Collection $reports = null): array
    {
        $reportsByDate = ($reports ?? collect())->keyBy(fn (DailyReport $report) => $report->report_date->toDateString());
        $contexts = $reportsByDate->map(fn (DailyReport $report) => $this->planContextForReport($user, $report));
        $contexts->put('__current__', $user);
        // Restrict the candidate set once per employee before iterating dates.
        $plans = $plans->filter(fn (KpiPlan $plan) => $plan->user_id !== null
            ? (int) $plan->user_id === (int) $user->id
            : $contexts->contains(fn (User $context) => $plan->role_slug === $context->role?->slug
                && ($plan->branch_id === null || (int) $plan->branch_id === (int) $context->branch_id)
                && ($plan->branch_group_id === null || (int) $plan->branch_group_id === (int) $context->branch_group_id)));
        $resolution = $this->dailyPlanResolution($contexts->get($from->toDateString(), $user), $from, $plans);
        $resolution['monthly_targets'] = $resolution['targets'];
        $resolution['targets'] = array_fill_keys(array_keys($resolution['targets']), 0.0);
        $resolution['weights'] = array_fill_keys(array_keys($resolution['weights']), 0.0);
        $resolution['by_date'] = [];
        $days = 0;
        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $daily = $this->dailyPlanResolution($contexts->get($date->toDateString(), $user), $date, $plans);
            if ($withBreakdown) $resolution['by_date'][$date->toDateString()] = $daily;
            foreach ($daily['targets'] as $key => $monthlyTarget) {
                $resolution['targets'][$key] += $periodType === 'day' && $key === 'sales'
                    ? $monthlyTarget : $monthlyTarget / $date->daysInMonth;
                $resolution['weights'][$key] += $daily['weights'][$key] ?? 0.0;
                if ($resolution['sources'][$key] !== $daily['sources'][$key]) $resolution['sources'][$key] = 'mixed';
            }
            $days++;
        }
        // A period uses the average of its normalized daily weights. Targets sum daily
        // portions, so plan changes and weeks spanning months retain their effective dates.
        $resolution['weights'] = array_map(fn ($weight) => round($weight / max(1, $days), 10), $resolution['weights']);
        return $resolution;
    }

    private function loadCanonicalPlans(Collection $users, Carbon $from, Carbon $to): Collection
    {
        if ($users->isEmpty() || ! Schema::hasTable('kpi_plans')) return collect();
        return KpiPlan::query()->tap(fn ($query) => $this->scopePlanQuery($query))
            ->where(fn ($q) => $q->whereIn('user_id', $users->pluck('id'))->orWhereNull('user_id'))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->get();
    }

    private function planContextForReport(User $user, ?DailyReport $report): User
    {
        if (! $report || $report->branch_group_id === null) return $user;
        $report->loadMissing(['branchGroup', 'historicalRole']);
        $context = clone $user;
        $context->branch_group_id = $report->branch_group_id;
        $context->branch_id = $report->branchGroup?->branch_id ?? $user->branch_id;
        if ($report->historicalRole) {
            $context->role_id = $report->historicalRole->id;
            $context->setRelation('role', $report->historicalRole);
        }
        return $context;
    }

    /** Preload the same scoped automatic facts used by a single daily report. */
    public function reportAutoMetrics(Collection $reports, User $actor): array
    {
        if ($reports->isEmpty()) return [];
        (new EloquentCollection($reports->all()))->loadMissing(['user.role', 'branchGroup', 'historicalRole']);
        $ids = $reports->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $from = Carbon::parse($reports->min(fn (DailyReport $report) => $report->report_date->toDateString()), self::TZ)->startOfDay();
        $to = Carbon::parse($reports->max(fn (DailyReport $report) => $report->report_date->toDateString()), self::TZ)->endOfDay();
        $facts = $this->preloadAutoMetricsByUserDate($ids, $from, $to, $actor);
        $sales = $this->preloadSalesCreditsByUserDate($ids, $from, $to, $actor);
        $result = [];
        foreach ($reports as $report) {
            $group = $report->branch_group_id ?? $report->user?->branch_group_id;
            $key = $actor->usesAssignedGroupScope() ? $report->user_id.'|'.$group : (int) $report->user_id;
            $date = $report->report_date->toDateString();
            $auto = $facts[$key][$date] ?? [];
            $auto['sales_count'] = (float) ($sales[$key][$date] ?? 0);
            $auto['deals_count'] = (int) floor($auto['sales_count']);
            $result[(int) $report->id] = $auto;
        }
        return $result;
    }

    /** Shared source policy for the daily report and all KPI aggregation routes. */
    public function canonicalDailyFacts(User $user, string $date, ?DailyReport $report, array $live): array
    {
        if ($report && $this->dailyReportService->preservesSnapshot($user, $report, $date)) {
            foreach (['calls_count', 'ad_count', 'shows_count', 'meetings_count', 'new_properties_count', 'new_clients_count'] as $column) {
                $live[$column] = (float) ($report->{$column} ?? 0);
            }
            $live['sales_count'] = $this->dealMetricValue($report);
            $live['deals_count'] = (int) floor($live['sales_count']);
        }
        return $live;
    }

    public function canonicalDailyMetricsBundle(User $user, string $date, ?DailyReport $report, array $auto, ?Collection $authorizedPlans = null): array
    {
        $user = $this->planContextForReport($user, $report);
        $day = Carbon::parse($date, self::TZ)->startOfDay();
        $plans = $authorizedPlans ?? $this->loadCanonicalPlans(collect([$user]), $day, $day);
        $resolution = $this->dailyPlanResolution($user, $day, $plans);
        $metrics = $this->buildMetricsForRows($report ? collect([$report]) : collect(),
            [$date => $this->canonicalDailyFacts($user, $date, $report, $auto)], [],
            (array) config('kpi.v2.metric_mapping', []), $resolution['targets'], 'day', 1, $resolution['sources'], $day);
        $score = $this->kpiScoreFromMetrics($metrics, $resolution['weights']);
        $debug = [];
        foreach ($metrics as $key => &$metric) {
            $metric['fact'] = $metric['final_value'];
            $metric['target'] = $metric['target_value'];
            $metric['monthly_target_value'] = $resolution['targets'][$key];
            $debug[$key] = ['resolved_from' => $metric['plan_source'], 'target_value' => $metric['target_value'],
                'source_record_id' => $resolution['plan_meta'][$key]['plan_id'] ?? null];
        }
        unset($metric);
        return ['metrics' => $metrics, 'kpi_percent' => $score['kpi_percent'], 'kpi_trace' => $score['trace'],
            'status' => $this->statusForKpiPercent($score['kpi_percent'], $score),
            'plan_resolution_debug' => ['employee_id' => (int) $user->id, 'date' => $date, 'metrics' => $debug]];
    }

    private function preloadLockedScopes(string $periodType, Carbon $periodStart): array
    {
        if ($periodType === 'range' || ! Schema::hasTable('kpi_period_locks')) return [];
        $keys = match ($periodType) {
            'month' => [$periodStart->format('Y-m')],
            'week' => [$periodStart->format('o-\WW'), $periodStart->copy()->startOfWeek(Carbon::MONDAY)->toDateString()],
            default => [$periodStart->toDateString()],
        };
        return KpiPeriodLock::query()->where('period_type', $periodType)->whereIn('period_key', $keys)
            ->get(['branch_id', 'branch_group_id'])->toArray();
    }

    private function lockedForContext(array $locks, User $user): bool
    {
        foreach ($locks as $lock) {
            if (($lock['branch_id'] === null || (int) $lock['branch_id'] === (int) $user->branch_id)
                && ($lock['branch_group_id'] === null || (int) $lock['branch_group_id'] === (int) $user->branch_group_id)) return true;
        }
        return false;
    }

    private function buildMetricsForRows(
        Collection $rows,
        array $autoByDate,
        array $sourceErrors,
        array $mapping,
        array $targetMap,
        string $periodType,
        ?int $daysInPeriodOverride = null,
        array $metricPlanSourceMap = [],
        ?Carbon $targetReferenceDate = null,
        ?array $resolvedTargets = null
    ): array {
        $metrics = [];
        $daysInPeriod = max(1, $daysInPeriodOverride ?? $rows->count());
        $from = $rows->isNotEmpty()
            ? $rows->min(fn (DailyReport $row) => $row->report_date)?->copy()
            : null;
        $daysInMonth = max(1, ($targetReferenceDate ?? $from)?->daysInMonth ?? $daysInPeriod);

        foreach ($mapping as $metricKey => $cfg) {
            $column = $this->resolveMetricSourceColumn((string) ($cfg['source_column'] ?? ''));
            $sourceType = (string) ($cfg['source_type'] ?? 'manual');
            $monthlyTarget = (float) ($targetMap[$metricKey] ?? 0);
            $target = $resolvedTargets[$metricKey] ?? match ($periodType) {
                'month' => $monthlyTarget,
                'week', 'range' => ($monthlyTarget / $daysInMonth) * $daysInPeriod,
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

            // Sales KPI is system-only: always use computed sales credits from properties.
            if ($metricKey === 'sales') {
                $finalValue = $factValue;
                $manualResult = 0.0;
                $source = 'system';
                $progress = $target > 0 ? round(($finalValue / $target) * 100, 2) : 0.0;

                $metrics[$metricKey] = [
                    'fact_value' => $this->normalizeNumber($factValue),
                    'manual_value' => 0,
                    'final_value' => $this->normalizeNumber($finalValue),
                    'target_value' => $this->normalizeNumber($target),
                    'progress_pct' => $progress,
                    'source' => $source,
                    'source_error' => $sourceError,
                    'plan_source' => (string) ($metricPlanSourceMap[$metricKey] ?? 'system'),
                ];

                continue;
            }

            if ($sourceType === 'system') {
                $finalValue = $factValue;
                $manualResult = 0.0;
                $source = 'system';
            } elseif ($sourceType === 'mixed') {
                // CRM facts can be configured as canonical while preserving the
                // manual report as a fallback for periods without system facts.
                $mixedStrategy = (string) ($cfg['mixed_strategy'] ?? 'manual_priority');
                $manualByDate = $rows->groupBy(fn (DailyReport $row) => $row->report_date->toDateString());
                $dates = array_unique(array_merge(array_keys($autoByDate), $manualByDate->keys()->all()));
                $finalValue = 0.0;
                foreach ($dates as $date) {
                    $system = empty($sourceErrors[$date]) ? (float) ($autoByDate[$date][$column] ?? 0) : 0.0;
                    $manual = (float) ($manualByDate->get($date, collect())->sum($column));
                    $finalValue += $mixedStrategy === 'system_priority'
                        ? ($system > 0 ? $system : $manual)
                        : ($manual > 0 ? $manual : $system);
                }
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

    private function historicalKpiUsersQuery(User $actor, array $filters, Carbon $from, Carbon $to): Builder
    {
        $current = $this->weeklyUsersScopeQuery($actor, $filters)->reorder()->select([
            'users.id as user_id', 'users.branch_group_id', 'users.role_id',
        ])->toBase();
        $history = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->join('roles as historical_roles', 'historical_roles.slug', '=', 'daily_reports.role_slug')
            ->where('report_date', '>=', $from->toDateString())
            ->where('report_date', '<', $to->copy()->addDay()->toDateString());
        $this->applyScope($history, $actor, $filters);
        if (isset($filters['roles'])) $history->whereIn('daily_reports.role_slug', $filters['roles']);
        foreach (['assignee_id', 'agent_id', 'mop_id'] as $key) {
            if (! empty($filters[$key])) $history->where('daily_reports.user_id', (int) $filters[$key]);
        }
        // A current member's full-period row already contains their earlier activity.
        // Do not add a second historical row and double-count that employee.
        $history->whereNotIn('daily_reports.user_id', (clone $current)->select('users.id'));
        $contexts = $current->union($history->select(['daily_reports.user_id', 'daily_reports.branch_group_id', 'historical_roles.id as role_id'])->toBase());
        return User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->joinSub($contexts, 'kpi_context', fn ($join) => $join->on('users.id', '=', 'kpi_context.user_id'))
            ->join('branch_groups as historical_groups', 'historical_groups.id', '=', 'kpi_context.branch_group_id')
            ->select(['users.id', 'users.name', 'kpi_context.branch_group_id', 'users.branch_group_id as current_branch_group_id', 'kpi_context.role_id', 'historical_groups.branch_id'])
            ->with(['role:id,slug', 'branch:id,name', 'branchGroup:id,branch_id,name']);
    }

    public function reportUsersQuery(User $actor): Builder
    {
        $query = User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->select(['users.id', 'users.name', 'users.role_id', 'users.branch_id', 'users.branch_group_id', 'users.status'])
            ->with(['role:id,name,slug', 'branch:id,name', 'branchGroup:id,branch_id,name'])
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereHas('role', fn (Builder $roles) => $roles->withPermission('kpi.subject.report_selection'));
        $this->applyReadScope($query, $actor, 'users');
        return $query;
    }

    private function weeklyUsersScopeQuery(User $authUser, array $filters): Builder
    {
        $authUser->loadMissing('role');
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        $query = User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->select('users.*')
            ->with(['role:id,slug', 'branch:id,name', 'branchGroup:id,branch_id,name'])
            ->where(function (Builder $query) use ($filters) {
                $query->whereHas('role', fn (Builder $q) => $q->withPermission('kpi.subject.period_reports'));
                // Internal inventory owners, still subject to every employee access
                // and branch/group restriction below. Not accepted by KPI HTTP APIs.
                if (($filters['unified_employees'] ?? false) && ! empty($filters['inventory_owner_ids'])) {
                    $query->orWhereIn('users.id', $filters['inventory_owner_ids']);
                }
            });

        if (! $includeInactive) {
            $query->where('users.status', '!=', 'inactive');
        }

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
        if (isset($filters['roles'])) $query->whereHas('role', fn (Builder $q) => $q->whereIn('slug', $filters['roles']));

        $this->applyReadScope($query, $authUser, 'users');

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

    private function breakdownByDay(Collection $rows, array $mapping, array $targetMap, array $sourceErrors, ?array $preloaded = null, array $plansByDate = []): array
    {
        $byDate = $rows->groupBy(fn (DailyReport $row) => $row->report_date->toDateString());
        $dates = array_unique(array_merge($byDate->keys()->all(), array_values(array_filter(array_keys($preloaded ?? []), fn ($date) => $date !== '__undated__'))));
        sort($dates);
        return array_map(function (string $day) use ($byDate, $mapping, $targetMap, $sourceErrors, $preloaded, $plansByDate) {
            $dayRows = $byDate->get($day, collect());
            $auto = $preloaded[$day] ?? [];
            if ($preloaded === null && $dayRows->isNotEmpty()) {
                $report = $dayRows->first();
                $auto = $this->canonicalDailyFacts($report->user, $day, $report, $this->dailyReportService->autoMetrics($report->user, $day));
            }
            $resolution = $plansByDate[$day] ?? null;
            $metrics = $this->buildMetricsForRows($dayRows, [$day => $auto], $sourceErrors, $mapping,
                $resolution['targets'] ?? $targetMap, 'day', 1, $resolution['sources'] ?? [], Carbon::parse($day, self::TZ));
            $this->kpiScoreFromMetrics($metrics, $resolution['weights'] ?? (array) config('kpi.v2.weights', []));
            return ['date' => $day, 'period_key' => $day, 'metrics' => $metrics];
        }, $dates);
    }

    private function kpiScoreFromMetrics(array &$metrics, array $weightMap): array
    {
        $value = 0.0;
        $capsEnabled = (bool) config('kpi.v2.formula.cap_metric_progress_at_100', true);
        $gateConfig = (array) config('kpi.v2.formula.hard_gate', []);
        $gateEnabled = (bool) ($gateConfig['enabled'] ?? true);
        $gateMax = (float) ($gateConfig['max_kpi_percent'] ?? 79.9);
        $metricGates = (array) ($gateConfig['metrics'] ?? []);
        $doneMinMetricProgress = (float) config('kpi.v2.formula.done_min_metric_progress_pct', 80.0);
        $traceMetrics = [];
        $gateTriggered = false;
        $gateReasons = [];

        foreach ($metrics as $key => $metric) {
            $target = (float) ($metric['target_value'] ?? 0);
            $final = (float) ($metric['final_value'] ?? 0);
            $progressPct = (float) ($metric['progress_pct'] ?? 0);
            $weight = (float) ($weightMap[$key] ?? 0);
            $ratioRaw = $target > 0 ? ($final / $target) : 0.0;
            $ratioUsed = $capsEnabled ? min(1.0, $ratioRaw) : $ratioRaw;
            $contributionPct = round($ratioUsed * $weight * 100, 2);

            $metrics[$key]['weight_used'] = $weight;
            $metrics[$key]['contribution_pct'] = $contributionPct;
            $metrics[$key]['progress_pct_capped'] = round($ratioUsed * 100, 2);

            if ($target <= 0) {
                continue;
            }

            if ($gateEnabled && isset($metricGates[$key])) {
                $minPct = (float) $metricGates[$key];
                if ($progressPct < $minPct) {
                    $gateTriggered = true;
                    $gateReasons[] = [
                        'metric' => $key,
                        'min_progress_pct' => $minPct,
                        'actual_progress_pct' => round($progressPct, 2),
                    ];
                }
            }

            $traceMetrics[$key] = [
                'target_value' => $this->normalizeNumber($target),
                'final_value' => $this->normalizeNumber($final),
                'progress_pct' => round($progressPct, 2),
                'weight_used' => $weight,
                'contribution_pct' => $contributionPct,
            ];

            $value += $ratioUsed * $weight;
        }

        $kpiValueRaw = round($value, 4);
        $kpiPercentRaw = round($kpiValueRaw * 100, 1);
        $kpiPercentFinal = $kpiPercentRaw;

        if ($gateEnabled && $gateTriggered) {
            $kpiPercentFinal = min($kpiPercentFinal, $gateMax);
        }

        return [
            'kpi_value' => round($kpiPercentFinal / 100, 4),
            'kpi_percent' => round($kpiPercentFinal, 1),
            'raw_kpi_percent' => $kpiPercentRaw,
            'gate_triggered' => $gateTriggered,
            'done_min_metric_progress_pct' => $doneMinMetricProgress,
            'trace' => [
                'formula_version' => 'v2.1',
                'cap_metric_progress_at_100' => $capsEnabled,
                'hard_gate' => [
                    'enabled' => $gateEnabled,
                    'triggered' => $gateTriggered,
                    'max_kpi_percent' => $gateMax,
                    'reasons' => $gateReasons,
                ],
                'metrics' => $traceMetrics,
                'raw_kpi_percent' => $kpiPercentRaw,
                'final_kpi_percent' => round($kpiPercentFinal, 1),
            ],
        ];
    }

    private function statusForKpiPercent(float $kpiPercent, array $score = []): string
    {
        if ((bool) ($score['gate_triggered'] ?? false) && $kpiPercent >= 100) {
            return self::STATUS_CONTROL;
        }

        if ($kpiPercent >= 100) {
            $doneMinMetricProgress = (float) ($score['done_min_metric_progress_pct'] ?? config('kpi.v2.formula.done_min_metric_progress_pct', 80.0));
            $traceMetrics = (array) ($score['trace']['metrics'] ?? []);
            foreach (['calls', 'ads'] as $criticalMetric) {
                if ((float) ($traceMetrics[$criticalMetric]['progress_pct'] ?? 0.0) < $doneMinMetricProgress) {
                    return self::STATUS_CONTROL;
                }
            }
        }

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
        if ($periodType === 'range' || ! Schema::hasTable('kpi_period_locks')) {
            return false;
        }

        $periodKey = match ($periodType) {
            'day' => $periodStart->toDateString(),
            'week' => $periodStart->format('o-\WW'),
            'month' => $periodStart->format('Y-m'),
            default => $periodStart->toDateString(),
        };

        $query = KpiPeriodLock::query()
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey);
        $actor = auth()->user();
        if ($actor?->usesAssignedGroupScope()) app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'kpi_period_locks.branch_group_id', 'kpi_period_locks.branch_id');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['branch_group_id'])) {
            $query->where('branch_group_id', (int) $filters['branch_group_id']);
        }

        return $query->exists();
    }

    private function isPeriodLockedForUser(string $periodType, Carbon $periodStart, ?User $user, ?int $reportGroupId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (auth()->user()?->usesAssignedGroupScope()) {
            return $reportGroupId !== null && $this->isPeriodLocked($periodType, $periodStart, ['branch_group_id' => $reportGroupId]);
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
        $historical = $authUser->usesAssignedGroupScope();
        if ($historical) $query->whereHas('historicalRole', fn (Builder $roles) => $roles->withPermission('kpi.subject.assigned_groups'));
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        if (! $includeInactive) {
            $query->whereHas('user', fn (Builder $q) => $q->where('status', '!=', 'inactive'));
        }

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
            $historical ? $query->whereHas('branchGroup', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']))
                : $query->whereHas('user', fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']));
        }

        if (! empty($filters['branch_group_id'])) {
            $historical ? $query->where('daily_reports.branch_group_id', (int) $filters['branch_group_id'])
                : $query->whereHas('user', fn (Builder $q) => $q->where('branch_group_id', (int) $filters['branch_group_id']));
        }

        if (! empty($filters['role'])) {
            $historical ? $query->where('daily_reports.role_slug', (string) $filters['role'])
                : $query->whereHas('user.role', fn (Builder $q) => $q->where('slug', (string) $filters['role']));
        }

        $this->applyReadScope($query, $authUser, 'reports');
    }

    private function applyReadScope(Builder $query, User $actor, string $kind): void
    {
        abort_unless($actor->hasPermission('kpi.reports.view'), 403, 'KPI_FORBIDDEN_SCOPE');
        $historical = $actor->usesAssignedGroupScope();
        $relation = $kind === 'reports' ? 'user' : 'assignee';
        $userColumn = match ($kind) { 'users' => 'users.id', 'reports' => 'daily_reports.user_id', default => 'crm_tasks.assignee_id' };
        $groupColumn = match ($kind) { 'users' => 'users.branch_group_id', 'reports' => 'daily_reports.branch_group_id', default => 'crm_tasks.branch_group_id' };
        if ($historical) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $actor, $groupColumn);
            if ($kind === 'users') $query->whereHas('role', fn (Builder $roles) => $roles->withPermission('kpi.subject.assigned_groups'));
        }
        if ($actor->hasPermission('kpi.reports.scope.all')) return;
        if ($actor->hasPermission('kpi.reports.scope.branch')) {
            if (! $actor->branch_id) { $query->whereRaw('1 = 0'); return; }
            if ($historical) return; // Assigned historical groups already belong to the actor's branch.
            $kind === 'users' ? $query->where('users.branch_id', $actor->branch_id)
                : $query->whereHas($relation, fn (Builder $q) => $q->where('branch_id', $actor->branch_id));
            return;
        }
        if ($actor->hasPermission('kpi.reports.scope.group')) {
            if (! $actor->branch_group_id) { $query->whereRaw('1 = 0'); return; }
            ($historical || $kind === 'users') ? $query->where($groupColumn, $actor->branch_group_id)
                : $query->whereHas($relation, fn (Builder $q) => $q->where('branch_group_id', $actor->branch_group_id));
            return;
        }
        $actor->hasPermission('kpi.reports.scope.own') ? $query->where($userColumn, $actor->id) : $query->whereRaw('1 = 0');
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
        // Automatic facts always expose sales_count, even on a legacy report schema.
        // Sales are system-only; no report column introspection is needed per employee.
        return $column;
    }

    private function dealMetricValue(DailyReport $row): float
    {
        return (float) ($row->sales_count ?? $row->deals_count ?? 0);
    }

    private function applyTaskScope(Builder $query, User $authUser, array $filters): void
    {
        $access = app(\App\Support\RopGroupAccess::class);
        if ($access->applies($authUser)) {
            $access->scope($query, $authUser, 'crm_tasks.branch_group_id');
            if (! empty($filters['branch_id'])) {
                abort_unless((int) $filters['branch_id'] === (int) $authUser->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            }
            if (! empty($filters['branch_group_id'])) {
                $access->ensureGroup($authUser, (int) $filters['branch_group_id']);
                $query->where('crm_tasks.branch_group_id', (int) $filters['branch_group_id']);
            }
            if (! empty($filters['assignee_id'])) $query->where('assignee_id', (int) $filters['assignee_id']);

            return;
        }

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

        $this->applyReadScope($query, $authUser, 'tasks');
    }

    private function ensureCanUpsertDailyForUser(User $authUser, User $targetUser, ?DailyReport $report = null): void
    {
        abort_unless($authUser->hasPermission('kpi.daily.input.manage'), 403, 'KPI_FORBIDDEN_ROLE_ACTION');
        $branchId = $targetUser->branch_id;
        $groupId = $targetUser->branch_group_id;
        if ($authUser->usesAssignedGroupScope()) {
            if ($report) {
                $groupId = $report->branch_group_id;
                $branchId = \App\Models\BranchGroup::query()->whereKey($groupId)->value('branch_id');
            } else {
                abort_unless(app(\App\Support\RopGroupAccess::class)->employees($authUser, 'kpi.subject.assigned_groups')->whereKey($targetUser->id)->exists(), 403, 'KPI_FORBIDDEN_SCOPE');
            }
        }
        $allowed = $authUser->hasPermission('kpi.daily.input.scope.all')
            || ($authUser->hasPermission('kpi.daily.input.scope.branch') && $authUser->branch_id && (int) $authUser->branch_id === (int) $branchId)
            || ($authUser->hasPermission('kpi.daily.input.scope.group') && $authUser->branch_group_id && (int) $authUser->branch_group_id === (int) $groupId)
            || ($authUser->hasPermission('kpi.daily.input.scope.own') && (int) $authUser->id === (int) $targetUser->id);
        abort_unless($allowed, 403, 'KPI_FORBIDDEN_SCOPE');
    }

    private function strictPeriodRows(User $authUser, string $periodType, Carbon $from, Carbon $to, array $filters): array
    {
        $v2 = $this->periodRowsV2($authUser, $periodType, $from, $to, array_merge($filters, [
            'per_page' => 200,
            'page' => 1,
        ]));
        $rows = collect((array) ($v2['rows'] ?? []));

        $reportedUserIds = DailyReport::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->when($authUser->usesAssignedGroupScope(), fn (Builder $q) => $this->applyScope($q, $authUser, $filters))
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
            ->map(function (array $row) use ($authUser, $periodType, $from) {
                return [
                    $periodType === 'week' ? 'week' : 'month' => $periodType === 'week' ? (int) $from->isoWeek() : (int) $from->month,
                    'year' => $periodType === 'week' ? (int) $from->isoWeekYear() : (int) $from->year,
                    ...($authUser->usesAssignedGroupScope() ? ['branch_group_id' => $row['branch_group_id'] ?? null, 'role' => $row['role'] ?? null] : []),
                    'employee_id' => (int) ($row['employee_id'] ?? 0),
                    'employee_name' => (string) ($row['employee_name'] ?? ''),
                    'objects' => $this->normalizeNumber((float) ($row['objects'] ?? 0)),
                    'shows' => $this->normalizeNumber((float) ($row['shows'] ?? 0)),
                    'ads' => $this->normalizeNumber((float) ($row['ads'] ?? 0)),
                    'calls' => $this->normalizeNumber((float) ($row['calls'] ?? 0)),
                    'clients' => $this->normalizeNumber((float) ($row['clients'] ?? 0)),
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
        abort_unless($authUser->hasPermission('kpi.reports.view'), 403, 'KPI_FORBIDDEN_SCOPE');
        // Personal progress remains available even before a branch/group is assigned.
        if ($targetUserId === null || $targetUserId === (int) $authUser->id) {
            return $authUser;
        }

        $target = User::query()->with('role')->findOrFail($targetUserId);
        $visible = User::query()->whereKey($target->id);
        $this->applyReadScope($visible, $authUser, 'users');
        abort_unless($visible->exists(), 403, 'KPI_FORBIDDEN_SCOPE');

        return $target;
    }

    private function scopePlanQuery(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $actor = auth()->user();
        $access = app(\App\Support\RopGroupAccess::class);
        if ($access->applies($actor)) $access->scope($query, $actor, 'kpi_plans.branch_group_id', 'kpi_plans.branch_id');
    }

    private function findCommonPlanRows(string $role, Carbon $date, ?int $branchId, ?int $branchGroupId): EloquentCollection
    {
        $queryBase = KpiPlan::query()->tap(fn ($query) => $this->scopePlanQuery($query))
            ->whereNull('user_id')
            ->where('role_slug', $role)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString());
            });

        $scopes = [];

        if (app(KpiRoleCatalog::class)->allowsGroupTemplates($role)) {
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

        $resolved = new EloquentCollection();
        $keys = [];
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

            foreach ($q->orderByDesc('id')->get() as $row) {
                if (! isset($keys[$row->metric_key])) {
                    $keys[$row->metric_key] = true;
                    $resolved->push($row);
                }
            }
        }

        return $resolved;
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

    private function ensurePlanScopeAccess(User $actor, User $target): void
    {
        $this->kpiPlanScopePolicy->ensureCanManageUserPlan($actor, $target);
    }
}
