<?php

namespace App\Http\Controllers;

use App\Models\KpiAcceptanceRun;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiIntegrationStatus;
use App\Models\KpiQualityIssue;
use App\Models\User;
use App\Services\KpiModuleService;
use App\Support\KpiPlanScopePolicy;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiModuleController extends Controller
{
    public function __construct(
        private readonly KpiModuleService $service,
        private readonly RbacBranchScope $branchScope,
        private readonly KpiPlanScopePolicy $kpiPlanScopePolicy
    )
    {
    }

    public function plans(Request $request)
    {
        $validated = $request->validate([
            'role' => 'nullable|string|max:64',
            'user_id' => 'nullable|integer|exists:users,id',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        if (! empty($validated['user_id'])) {
            $date = isset($validated['date'])
                ? Carbon::parse($validated['date'], 'Asia/Dushanbe')
                : Carbon::now('Asia/Dushanbe');

            $target = User::query()->findOrFail((int) $validated['user_id']);
            $this->kpiPlanScopePolicy->ensureCanReadUserPlan($this->authUser(), $target);
            $effective = $this->service->effectivePlanForUser((int) $validated['user_id'], $date);

            return response()->json([
                'data' => $effective['items'],
                'plans' => $effective['items'],
                'source' => $effective['source'],
                'meta' => [
                    'exists' => count($effective['items']) > 0,
                    'source' => $effective['source'],
                ],
            ]);
        }

        $role = (string) ($validated['role'] ?? 'mop');

        return response()->json(['data' => $this->service->plans($role)]);
    }

    public function updateUserPlans(Request $request, int $userId)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'items' => 'required|array|min:1',
            'items.*.metric' => ['nullable', 'required_without:items.*.metric_key', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'items.*.metric_key' => ['nullable', 'required_without:items.*.metric', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'items.*.daily_plan' => 'required|numeric|min:0',
            'items.*.weight' => 'required|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ]);

        $validated['items'] = $this->normalizePlanItems((array) $validated['items']);
        $this->assertWeightSumOrKpiError($validated['items']);

        try {
            $result = $this->service->upsertUserPlans($this->authUser(), $userId, $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        return response()->json(['data' => $result]);
    }

    public function bulkUpsertUserPlans(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor, ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'scope' => 'required|array',
            'scope.role' => ['nullable', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'scope.roles' => 'nullable|array|min:1',
            'scope.roles.*' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'scope.target_roles' => 'nullable|array|min:1',
            'scope.target_roles.*' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'scope.branch_id' => 'nullable|integer|exists:branches,id',
            'scope.branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'rows' => 'required|array|min:1|max:500',
            'rows.*.user_id' => 'required|integer|exists:users,id',
            'rows.*.items' => 'required|array|min:1',
            'rows.*.items.*.metric' => ['nullable', 'required_without:rows.*.items.*.metric_key', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'rows.*.items.*.metric_key' => ['nullable', 'required_without:rows.*.items.*.metric', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'rows.*.items.*.daily_plan' => 'required|numeric|min:0',
            'rows.*.items.*.weight' => 'required|numeric|min:0|max:1',
            'rows.*.items.*.comment' => 'nullable|string|max:500',
        ]);

        $scope = (array) $validated['scope'];
        $scopeRoles = $this->extractScopeRoles($scope);

        if ($scopeRoles === []) {
            $this->kpiPlanScopePolicy->ensureCanManageBulkScope($actor, $scope);
        } else {
            foreach ($scopeRoles as $scopeRole) {
                $scopeForRole = array_merge($scope, ['role' => $scopeRole]);
                $this->kpiPlanScopePolicy->ensureCanManageBulkScope($actor, $scopeForRole);
            }
        }

        $results = [];
        foreach ((array) $validated['rows'] as $index => $row) {
            $userId = (int) $row['user_id'];
            $items = $this->normalizePlanItems((array) ($row['items'] ?? []));
            $rowRole = null;

            try {
                $target = User::query()->with('role')->findOrFail($userId);
                $rowRole = (string) ($target->role?->slug ?? '');

                $this->kpiPlanScopePolicy->ensureCanManageBulkScope($actor, array_merge($scope, ['role' => $rowRole]));

                if ($scopeRoles !== [] && ! in_array($rowRole, $scopeRoles, true)) {
                    $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'User role is out of requested scope.');
                }

                if ($scopeRoles === [] && isset($scope['role']) && $scope['role'] !== null && $rowRole !== (string) $scope['role']) {
                    $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'User role is out of requested scope.');
                }

                $this->validateWeightSum($items);
                $this->service->upsertUserPlans($actor, $userId, [
                    'effective_from' => $validated['effective_from'],
                    'effective_to' => $validated['effective_to'] ?? null,
                    'items' => $items,
                ]);

                $results[] = ['user_id' => $userId, 'ok' => true];
            } catch (ValidationException $e) {
                $results[] = [
                    'user_id' => $userId,
                    'ok' => false,
                    'code' => 'KPI_VALIDATION_FAILED',
                    'message' => 'Validation failed.',
                    'details' => ['row' => $index, 'role' => $rowRole, 'errors' => $e->errors()],
                ];
            } catch (\DomainException $e) {
                $results[] = [
                    'user_id' => $userId,
                    'ok' => false,
                    'code' => 'KPI_PLAN_PERIOD_CONFLICT',
                    'message' => $e->getMessage(),
                    'details' => ['row' => $index, 'role' => $rowRole, 'errors' => []],
                ];
            } catch (HttpResponseException $e) {
                $response = $e->getResponse();
                $payload = method_exists($response, 'getData')
                    ? (array) $response->getData(true)
                    : [];

                $results[] = [
                    'user_id' => $userId,
                    'ok' => false,
                    'code' => (string) ($payload['code'] ?? 'KPI_FORBIDDEN_SCOPE'),
                    'message' => (string) ($payload['message'] ?? 'Forbidden in current scope.'),
                    'details' => array_merge(['row' => $index, 'role' => $rowRole, 'errors' => []], (array) ($payload['details'] ?? [])),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'user_id' => $userId,
                    'ok' => false,
                    'code' => 'KPI_CONFLICT',
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'KPI conflict.',
                    'details' => ['row' => $index, 'role' => $rowRole, 'errors' => []],
                ];
            }
        }

        $successCount = collect($results)->where('ok', true)->count();
        $failedCount = collect($results)->where('ok', false)->count();

        return response()->json([
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ]);
    }

    public function updatePlans(Request $request)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'role' => 'nullable|string|max:64',
            'items' => 'required|array|min:1',
            'items.*.metric_key' => ['required', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'items.*.daily_plan' => 'required|numeric|min:0',
            'items.*.weight' => 'required|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ]);

        $role = (string) ($validated['role'] ?? 'mop');

        return response()->json(['data' => $this->service->upsertPlans($role, $validated['items'])]);
    }

    public function commonPlans(Request $request)
    {
        $validated = $request->validate([
            'role' => ['nullable', Rule::in(['agent', 'intern', 'mop', 'rop']), 'required_without_all:roles,role_in'],
            'roles' => 'nullable|array|min:1',
            'roles.*' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'role_in' => 'nullable|array|min:1',
            'role_in.*' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'date' => 'required|date_format:Y-m-d',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]);

        $roles = $this->extractRolesFromRequest($validated);
        $date = Carbon::parse($validated['date'], 'Asia/Dushanbe');
        $actor = $this->authUser();
        $scope = [
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'branch_group_id' => isset($validated['branch_group_id']) ? (int) $validated['branch_group_id'] : null,
        ];

        foreach ($roles as $role) {
            $this->kpiPlanScopePolicy->ensureCanReadCommonPlan($actor, array_merge($scope, ['role' => $role]));
        }

        $data = collect($roles)
            ->map(fn (string $role) => $this->service->commonPlans(
                $role,
                $date,
                $scope['branch_id'],
                $scope['branch_group_id']
            ))
            ->flatten(1)
            ->values();

        return response()->json([
            'data' => $data,
            'plans' => $data,
            'meta' => [
                'exists' => $data->isNotEmpty(),
                'source' => 'common',
            ],
        ]);
    }

    public function upsertCommonPlans(Request $request)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'items' => 'required|array|min:1',
            'items.*.metric' => ['nullable', 'required_without:items.*.metric_key', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'items.*.metric_key' => ['nullable', 'required_without:items.*.metric', 'string', 'max:64', Rule::in((array) config('kpi.v2.metric_keys', []))],
            'items.*.daily_plan' => 'required|numeric|min:0',
            'items.*.weight' => 'required|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ]);

        $validated['items'] = collect((array) $validated['items'])->map(function (array $item) {
            $item['metric_key'] = (string) ($item['metric_key'] ?? $item['metric'] ?? '');
            return $item;
        })->all();

        $this->assertWeightSumOrKpiError($validated['items']);
        $this->kpiPlanScopePolicy->ensureCanManageCommonPlan($this->authUser(), $validated);

        try {
            $result = $this->service->upsertCommonPlans($this->authUser(), $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        return response()->json(['data' => $result]);
    }

    public function applyCommonPlansToUsers(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor, ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $scope = [
            'role' => $validated['role'],
            'branch_id' => $validated['branch_id'] ?? null,
            'branch_group_id' => $validated['branch_group_id'] ?? null,
        ];
        $this->kpiPlanScopePolicy->ensureCanManageCommonPlan($actor, $scope);

        try {
            $result = $this->service->applyCommonPlanToUsers($actor, $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        return response()->json($result);
    }

    public function eligibleUsers(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor, ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'role' => ['nullable', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'q' => 'nullable|string|max:255',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $this->kpiPlanScopePolicy->ensureCanReadCommonPlan($actor, $validated);
        if (($actor->role?->slug ?? '') === 'mop' && isset($validated['role']) && (string) $validated['role'] === 'rop') {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
        }

        return response()->json($this->service->eligibleUsers($actor, $validated));
    }

    public function daily(Request $request)
    {
        $validated = $this->validateKpiFilters($request, true);
        $date = isset($validated['date']) ? Carbon::parse($validated['date'], 'Asia/Dushanbe') : Carbon::now('Asia/Dushanbe');

        if ($this->wantsV2($request)) {
            return response()->json($this->service->dailyRowsV2($this->authUser(), $date, $validated));
        }

        return response()->json(['data' => $this->service->dailyRows($this->authUser(), $date, $validated)]);
    }

    public function upsertDaily(Request $request)
    {
        if ($this->wantsV2($request)) {
            $validated = $request->validate([
                'rows' => 'required|array|min:1',
                'rows.*.date' => 'required|date_format:Y-m-d',
                'rows.*.role' => 'nullable|string|max:64',
                'rows.*.employee_id' => 'required|integer|exists:users,id',
                'rows.*.employee_name' => 'nullable|string|max:255',
                'rows.*.group_name' => 'nullable|string|max:255',
                'rows.*.advertisement' => 'nullable|integer|min:0',
                'rows.*.call' => 'nullable|integer|min:0',
                'rows.*.kabul' => 'nullable|integer|min:0',
                'rows.*.show' => 'nullable|integer|min:0',
                'rows.*.lead' => 'nullable|integer|min:0',
                'rows.*.deposit' => 'nullable|integer|min:0',
                'rows.*.deal' => 'nullable|integer|min:0',
                'rows.*.objects' => 'nullable|numeric|min:0',
                'rows.*.shows' => 'nullable|numeric|min:0',
                'rows.*.ads' => 'nullable|numeric|min:0',
                'rows.*.calls' => 'nullable|numeric|min:0',
                'rows.*.sales' => 'nullable|numeric|min:0',
                'rows.*.comment' => 'nullable|string',
            ]);

            return response()->json([
                'data' => $this->service->upsertDailyRowsV2($this->authUser(), $validated['rows']),
            ], 201);
        }

        return app(DailyReportController::class)->store($request);
    }

    public function weekly(Request $request)
    {
        $validated = array_merge($this->validateKpiFilters($request, false), $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'week' => 'nullable|integer|min:1|max:53',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]));

        if (! empty($validated['date_from']) && ! empty($validated['date_to'])) {
            $start = Carbon::parse($validated['date_from'], 'Asia/Dushanbe')->startOfDay();
            $end = Carbon::parse($validated['date_to'], 'Asia/Dushanbe')->endOfDay();
        } else {
            $year = (int) ($validated['year'] ?? Carbon::now('Asia/Dushanbe')->year);
            $week = (int) ($validated['week'] ?? Carbon::now('Asia/Dushanbe')->weekOfYear);
            $start = Carbon::now('Asia/Dushanbe')->setISODate($year, $week)->startOfWeek(Carbon::MONDAY);
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
        }

        if ($this->wantsV2($request)) {
            return response()->json($this->service->periodRowsV2($this->authUser(), 'week', $start, $end, $validated));
        }

        return response()->json(['data' => $this->service->periodRows($this->authUser(), 'week', $start, $end, $validated), 'meta' => ['year' => (int) $validated['year'], 'week' => (int) $validated['week']]]);
    }

    public function monthly(Request $request)
    {
        $validated = array_merge($this->validateKpiFilters($request, false), $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]));

        if (! empty($validated['date_from']) && ! empty($validated['date_to'])) {
            $start = Carbon::parse($validated['date_from'], 'Asia/Dushanbe')->startOfDay();
            $end = Carbon::parse($validated['date_to'], 'Asia/Dushanbe')->endOfDay();
        } else {
            $year = (int) ($validated['year'] ?? Carbon::now('Asia/Dushanbe')->year);
            $month = (int) ($validated['month'] ?? Carbon::now('Asia/Dushanbe')->month);
            $start = Carbon::createFromDate($year, $month, 1, 'Asia/Dushanbe')->startOfMonth();
            $end = $start->copy()->endOfMonth();
        }

        if ($this->wantsV2($request)) {
            return response()->json($this->service->periodRowsV2($this->authUser(), 'month', $start, $end, $validated));
        }

        return response()->json(['data' => $this->service->periodRows($this->authUser(), 'month', $start, $end, $validated), 'meta' => ['year' => (int) $validated['year'], 'month' => (int) $validated['month']]]);
    }

    public function metricMapping()
    {
        return response()->json(['data' => $this->service->metricMapping()]);
    }

    public function dashboard(Request $request)
    {
        $validated = $this->validateKpiFilters($request, true);
        $date = isset($validated['date']) ? Carbon::parse($validated['date'], 'Asia/Dushanbe') : Carbon::now('Asia/Dushanbe');

        return response()->json(['data' => $this->service->dashboard($this->authUser(), $date, $validated)]);
    }

    public function dashboardDebug(Request $request)
    {
        $validated = $this->validateKpiFilters($request, true);
        $date = isset($validated['date']) ? Carbon::parse($validated['date'], 'Asia/Dushanbe') : Carbon::now('Asia/Dushanbe');

        return response()->json(['data' => $this->service->dashboardDebug($this->authUser(), $date, $validated)]);
    }

    public function integrationsStatus()
    {
        return response()->json(['data' => KpiIntegrationStatus::query()->orderBy('id')->get()]);
    }

    public function telegramConfig()
    {
        return response()->json(['data' => $this->service->telegramConfig()]);
    }

    public function updateTelegramConfig(Request $request)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director']);

        $validated = $request->validate([
            'daily_enabled' => 'sometimes|boolean',
            'daily_time' => 'sometimes|date_format:H:i',
            'weekly_enabled' => 'sometimes|boolean',
            'weekly_day' => 'sometimes|integer|min:1|max:7',
            'weekly_time' => 'sometimes|date_format:H:i',
            'timezone' => 'sometimes|string|max:64',
        ]);

        return response()->json(['data' => $this->service->updateTelegramConfig($validated)]);
    }

    public function qualityIssues(Request $request)
    {
        $validated = $request->validate(['date_from' => 'nullable|date', 'date_to' => 'nullable|date']);
        $query = KpiQualityIssue::query()->orderByDesc('detected_at');

        if (! empty($validated['date_from'])) {
            $query->whereDate('detected_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('detected_at', '<=', $validated['date_to']);
        }

        $rows = $query->get()->map(function (KpiQualityIssue $issue) {
            $details = (array) ($issue->details ?? []);
            $details['metric_key'] = $this->normalizeMetricKey($details['metric_key'] ?? null);
            $details['version'] = '2';

            return array_merge($issue->toArray(), ['details' => $details]);
        });

        return response()->json(['data' => $rows, 'meta' => ['version' => '2']]);
    }

    public function earlyRiskAlerts(Request $request)
    {
        $validated = $request->validate(['date' => 'nullable|date']);
        $query = KpiEarlyRiskAlert::query()->orderByDesc('id');
        if (! empty($validated['date'])) {
            $query->whereDate('alert_date', $validated['date']);
        }

        $rows = $query->get()->map(function (KpiEarlyRiskAlert $alert) {
            $meta = (array) ($alert->meta ?? []);
            $meta['metric_key'] = $this->normalizeMetricKey($meta['metric_key'] ?? null);
            $meta['version'] = '2';

            return array_merge($alert->toArray(), ['meta' => $meta]);
        });

        return response()->json(['data' => $rows, 'meta' => ['version' => '2']]);
    }

    public function updateEarlyRiskStatus(Request $request)
    {
        $validated = $request->validate([
            'alert_id' => 'required|integer|exists:kpi_early_risk_alerts,id',
            'status' => ['required', Rule::in(['acknowledged', 'closed', 'escalated'])],
        ]);

        KpiEarlyRiskAlert::query()->whereKey($validated['alert_id'])->update(['status' => $validated['status']]);

        return response()->json(['success' => true]);
    }

    public function periodContract(Request $request)
    {
        $validated = array_merge($this->validateKpiFilters($request, false), $request->validate([
            'period_type' => ['required', Rule::in(['day', 'week', 'month'])],
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]));

        $from = Carbon::parse($validated['date_from'], 'Asia/Dushanbe')->startOfDay();
        $to = Carbon::parse($validated['date_to'], 'Asia/Dushanbe')->endOfDay();

        return response()->json(['data' => $this->service->periodContract($this->authUser(), $from, $to, $validated)]);
    }

    public function acceptanceRuns()
    {
        $rows = KpiAcceptanceRun::query()->orderByDesc('id')->get()->map(function (KpiAcceptanceRun $run) {
            $details = (array) ($run->details ?? []);
            if (isset($details['metric_key'])) {
                $details['metric_key'] = $this->normalizeMetricKey($details['metric_key']);
            }
            $details['version'] = '2';

            return array_merge($run->toArray(), ['details' => $details]);
        });

        return response()->json(['data' => $rows, 'meta' => ['version' => '2']]);
    }

    public function adjustments(Request $request)
    {
        return app(KpiPeriodLockController::class)->adjustmentHistory($request);
    }

    public function createAdjustment(Request $request)
    {
        return app(KpiPeriodLockController::class)->adjustments($request);
    }

    public function adjustmentMeta()
    {
        return response()->json([
            'fields' => [
                ['value' => 'objects', 'label' => 'Объекты', 'hint' => 'Количество добавленных объектов за период.'],
                ['value' => 'shows', 'label' => 'Показы', 'hint' => 'Количество проведённых показов за период.'],
                ['value' => 'ads', 'label' => 'Реклама', 'hint' => 'Количество рекламных активностей за период.'],
                ['value' => 'calls', 'label' => 'Звонки', 'hint' => 'Количество звонков за период.'],
                ['value' => 'sales', 'label' => 'Сделки', 'hint' => 'Количество завершённых сделок за период.'],
            ],
            'period_types' => [
                ['value' => 'day', 'label' => 'День'],
                ['value' => 'week', 'label' => 'Неделя'],
                ['value' => 'month', 'label' => 'Месяц'],
            ],
        ]);
    }

    public function adjustmentEntities(Request $request)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director']);

        $validated = $request->validate([
            'role' => 'nullable|string|max:128',
            'active' => 'nullable|boolean',
        ]);

        $roles = collect(explode(',', (string) ($validated['role'] ?? 'agent,mop,rop')))
            ->map(fn (string $role) => trim($role))
            ->filter()
            ->values()
            ->all();

        $query = User::query()
            ->select(['users.id', 'users.name', 'users.branch_id', 'users.role_id'])
            ->with('role:id,slug')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles));

        if (array_key_exists('active', $validated) && (bool) $validated['active']) {
            $query->where(function ($q) {
                $q->where('status', User::STATUS_ACTIVE)
                    ->orWhereNull('status');
            });
        }

        $rows = $query->orderBy('users.name')
            ->get()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => (string) ($user->role?->slug ?? ''),
                'branch_id' => $user->branch_id !== null ? (int) $user->branch_id : null,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function crmTaskDailySummary(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'assignee_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]);

        return response()->json(['data' => $this->service->taskDailySummary($this->authUser(), Carbon::parse($validated['date'], 'Asia/Dushanbe'), $validated)]);
    }

    public function crmTaskWeeklySummary(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'week' => 'required|integer|min:1|max:53',
            'assignee_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]);

        return response()->json(['data' => $this->service->taskWeeklySummary($this->authUser(), (int) $validated['year'], (int) $validated['week'], $validated)]);
    }

    public function myDailyProgress(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'], 'Asia/Dushanbe')
            : Carbon::now('Asia/Dushanbe');

        return response()->json($this->service->myDailyProgress($this->authUser(), $date));
    }

    private function validateKpiFilters(Request $request, bool $withDate): array
    {
        return $request->validate(array_merge($withDate ? ['date' => 'nullable|date_format:Y-m-d'] : [], [
            'v' => 'nullable|string|max:16',
            'period_type' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'role' => ['nullable', Rule::in(['admin', 'superadmin', 'owner', 'branch_director', 'rop', 'mop', 'agent', 'intern'])],
            'assignee_id' => 'nullable|integer|exists:users,id',
            'mop_id' => 'nullable|integer|exists:users,id',
            'agent_id' => 'nullable|integer|exists:users,id',
            'group_id' => 'nullable|integer|exists:branch_groups,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]));
    }

    private function wantsV2(Request $request): bool
    {
        return (string) $request->query('v', '') === '2'
            || (string) $request->header('X-KPI-Version', '') === '2';
    }

    private function ensureManageAccess(User $user, array $allowed): void
    {
        if (! in_array($user->role?->slug, $allowed, true)) {
            abort(response()->json([
                'code' => 'KPI_FORBIDDEN',
                'message' => 'Forbidden.',
                'details' => (object) [],
                'trace_id' => request()->attributes->get('trace_id'),
            ], 403));
        }
    }

    private function kpiError(string $code, string $message, int $status, array $details = []): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'trace_id' => request()->attributes->get('trace_id'),
        ], $status);
    }

    private function normalizeMetricKey(null|string $metricKey): ?string
    {
        if ($metricKey === null || $metricKey === '') {
            return null;
        }

        return match ($metricKey) {
            'ad_count', 'advertisement' => 'ads',
            'calls_count', 'call' => 'calls',
            'shows_count', 'show', 'meetings_count' => 'shows',
            'new_properties_count', 'lead' => 'objects',
            'sales_count', 'deals_count', 'deal' => 'sales',
            default => $metricKey,
        };
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function validateWeightSum(array $items): void
    {
        $sum = round((float) collect($items)->sum(fn (array $item) => (float) ($item['weight'] ?? 0)), 4);
        if (abs($sum - 1.0) > 0.0001) {
            throw ValidationException::withMessages([
                'items' => ['The sum of all items.weight must be exactly 1.0.'],
            ]);
        }
    }

    private function normalizePlanItems(array $items): array
    {
        return collect($items)->map(function (array $item) {
            $item['metric_key'] = (string) ($item['metric_key'] ?? $item['metric'] ?? '');
            return $item;
        })->all();
    }

    private function assertWeightSumOrKpiError(array $items): void
    {
        try {
            $this->validateWeightSum($items);
        } catch (ValidationException $e) {
            abort(response()->json([
                'code' => 'KPI_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => ['errors' => $e->errors()],
                'trace_id' => request()->attributes->get('trace_id'),
            ], 422));
        }
    }

    private function extractScopeRoles(array $scope): array
    {
        if (! empty($scope['roles']) && is_array($scope['roles'])) {
            return collect((array) $scope['roles'])
                ->filter(fn ($role) => is_string($role) && $role !== '')
                ->map(fn (string $role) => trim($role))
                ->unique()
                ->values()
                ->all();
        }

        if (! empty($scope['target_roles']) && is_array($scope['target_roles'])) {
            return collect((array) $scope['target_roles'])
                ->filter(fn ($role) => is_string($role) && $role !== '')
                ->map(fn (string $role) => trim($role))
                ->unique()
                ->values()
                ->all();
        }

        if (isset($scope['role']) && is_string($scope['role']) && $scope['role'] !== '') {
            return [(string) $scope['role']];
        }

        return [];
    }

    private function extractRolesFromRequest(array $validated): array
    {
        if (! empty($validated['roles']) && is_array($validated['roles'])) {
            return collect((array) $validated['roles'])
                ->map(fn (string $role) => trim($role))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (! empty($validated['role_in']) && is_array($validated['role_in'])) {
            return collect((array) $validated['role_in'])
                ->map(fn (string $role) => trim($role))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return [(string) $validated['role']];
    }

    private function ensureCanReadUserPlans(User $actor, int $targetUserId): void
    {
        $target = User::query()->findOrFail($targetUserId);
        $actor->loadMissing('role');

        $allowed = match ($actor->role?->slug) {
            'admin', 'superadmin', 'owner' => true,
            'rop', 'branch_director' => (int) $actor->branch_id === (int) $target->branch_id,
            'mop' => (int) $actor->branch_group_id === (int) $target->branch_group_id,
            default => (int) $actor->id === (int) $target->id,
        };

        if (! $allowed) {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
        }
    }

    private function ensureCommonScopeReadable(User $actor, array $scope): void
    {
        $actor->loadMissing('role');
        $role = (string) ($actor->role?->slug ?? '');

        if ($role === 'rop') {
            $this->ensureSameBranchOrKpiDeny(isset($scope['branch_id']) ? (int) $scope['branch_id'] : null, $actor);
            $this->ensureBranchGroupInUserBranchOrKpiDeny(isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null, $actor);
            return;
        }

        if ($role === 'branch_director') {
            $this->ensureSameBranchOrKpiDeny(isset($scope['branch_id']) ? (int) $scope['branch_id'] : null, $actor);
            return;
        }

        if ($role === 'mop') {
            if (isset($scope['role']) && (string) $scope['role'] === 'rop') {
                $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
            }
            $this->ensureSameBranchOrKpiDeny(isset($scope['branch_id']) ? (int) $scope['branch_id'] : null, $actor);
            $this->ensureSameBranchGroupOrKpiDeny(isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null, $actor);
            return;
        }
    }

    private function ensureCommonScopeManageable(User $actor, array $scope): void
    {
        $actor->loadMissing('role');

        if (in_array($actor->role?->slug, ['admin', 'superadmin', 'owner'], true)) {
            return;
        }

        if ($actor->role?->slug === 'branch_director' || $actor->role?->slug === 'rop') {
            $this->ensureSameBranchOrKpiDeny(isset($scope['branch_id']) ? (int) $scope['branch_id'] : null, $actor);
            $this->ensureBranchGroupInUserBranchOrKpiDeny(isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null, $actor);
            return;
        }

        if ($actor->role?->slug === 'mop') {
            $planRole = (string) ($scope['role'] ?? '');

            if ($planRole === 'rop') {
                $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
            }

            $this->ensureSameBranchGroupOrKpiDeny(isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null, $actor);
            $this->ensureSameBranchOrKpiDeny(isset($scope['branch_id']) ? (int) $scope['branch_id'] : null, $actor);
            return;
        }

        $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
    }

    private function ensureSameBranchOrKpiDeny(?int $branchId, User $actor): void
    {
        try {
            $this->branchScope->ensureSameBranchOrDeny($branchId, $actor);
        } catch (HttpResponseException) {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
        }
    }

    private function ensureSameBranchGroupOrKpiDeny(?int $branchGroupId, User $actor): void
    {
        try {
            $this->branchScope->ensureSameBranchGroupOrDeny($branchGroupId, $actor);
        } catch (HttpResponseException) {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
        }
    }

    private function ensureBranchGroupInUserBranchOrKpiDeny(?int $branchGroupId, User $actor): void
    {
        try {
            $this->branchScope->ensureBranchGroupInUserBranchOrDeny($branchGroupId, $actor);
        } catch (HttpResponseException) {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
        }
    }
}
