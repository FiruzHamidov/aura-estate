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
        $validated = $request->validate(['role' => 'nullable|string|max:64']);
        $role = (string) ($validated['role'] ?? 'mop');

        return response()->json(['data' => $this->service->plans($role)]);
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

        return response()->json(['data' => $this->service->dailyRows($this->authUser(), $date, $validated)]);
    }

    public function upsertDaily(Request $request)
    {
        return app(DailyReportController::class)->store($request);
    }

    public function weekly(Request $request)
    {
        $validated = array_merge($this->validateKpiFilters($request, false), $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'week' => 'required|integer|min:1|max:53',
        ]));

        $start = Carbon::now('Asia/Dushanbe')->setISODate((int) $validated['year'], (int) $validated['week'])->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        return response()->json(['data' => $this->service->periodRows($this->authUser(), 'week', $start, $end, $validated), 'meta' => ['year' => (int) $validated['year'], 'week' => (int) $validated['week']]]);
    }

    public function monthly(Request $request)
    {
        $validated = array_merge($this->validateKpiFilters($request, false), $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]));

        $start = Carbon::createFromDate((int) $validated['year'], (int) $validated['month'], 1, 'Asia/Dushanbe')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return response()->json(['data' => $this->service->periodRows($this->authUser(), 'month', $start, $end, $validated), 'meta' => ['year' => (int) $validated['year'], 'month' => (int) $validated['month']]]);
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
            'role' => ['nullable', Rule::in(['admin', 'superadmin', 'owner', 'branch_director', 'rop', 'mop', 'agent', 'intern'])],
            'assignee_id' => 'nullable|integer|exists:users,id',
            'mop_id' => 'nullable|integer|exists:users,id',
            'agent_id' => 'nullable|integer|exists:users,id',
            'group_id' => 'nullable|integer|exists:branch_groups,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]));
    }

    private function ensureManageAccess(User $user, array $allowed): void
    {
        abort_unless(in_array($user->role?->slug, $allowed, true), 403, 'Forbidden');
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
