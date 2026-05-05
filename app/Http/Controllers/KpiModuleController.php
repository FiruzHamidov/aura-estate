<?php

namespace App\Http\Controllers;

use App\Models\KpiAcceptanceRun;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiIntegrationStatus;
use App\Models\KpiQualityIssue;
use App\Models\User;
use App\Services\KpiModuleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class KpiModuleController extends Controller
{
    public function __construct(private readonly KpiModuleService $service)
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

            return response()->json([
                'data' => $this->service->plansForUser((int) $validated['user_id'], $date),
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
            'items.*.metric_key' => 'required|string|max:64',
            'items.*.daily_plan' => 'required|numeric|min:0',
            'items.*.weight' => 'required|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->service->upsertUserPlans($this->authUser(), $userId, $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        return response()->json(['data' => $result]);
    }

    public function updatePlans(Request $request)
    {
        $this->ensureManageAccess($this->authUser(), ['admin', 'superadmin', 'rop', 'branch_director', 'mop']);

        $validated = $request->validate([
            'role' => 'nullable|string|max:64',
            'items' => 'required|array|min:1',
            'items.*.metric_key' => 'required|string|max:64',
            'items.*.daily_plan' => 'required|numeric|min:0',
            'items.*.weight' => 'required|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ]);

        $role = (string) ($validated['role'] ?? 'mop');

        return response()->json(['data' => $this->service->upsertPlans($role, $validated['items'])]);
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

        return response()->json(['data' => $query->get()]);
    }

    public function earlyRiskAlerts(Request $request)
    {
        $validated = $request->validate(['date' => 'nullable|date']);
        $query = KpiEarlyRiskAlert::query()->orderByDesc('id');
        if (! empty($validated['date'])) {
            $query->whereDate('alert_date', $validated['date']);
        }

        return response()->json(['data' => $query->get()]);
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
        return response()->json(['data' => KpiAcceptanceRun::query()->orderByDesc('id')->get()]);
    }

    public function adjustments(Request $request)
    {
        return app(KpiPeriodLockController::class)->adjustmentHistory($request);
    }

    public function createAdjustment(Request $request)
    {
        return app(KpiPeriodLockController::class)->adjustments($request);
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

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }
}
