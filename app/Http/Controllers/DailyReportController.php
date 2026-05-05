<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\KpiPeriodLock;
use App\Models\User;
use App\Services\DailyReportService;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyReportController extends Controller
{
    public function __construct(
        private readonly DailyReportService $dailyReports,
        private readonly RbacBranchScope $branchScope
    )
    {
    }

    public function status(Request $request)
    {
        return response()->json(
            $this->dailyReports->reportStatusPayload($this->authUser(), $request->input('report_date'))
        );
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'report_date' => 'nullable|date_format:Y-m-d',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'role' => 'nullable|string|exists:roles,slug',
            'user_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = DailyReport::query()
            ->with(['user.role', 'user.branch', 'user.branchGroup']);

        $this->validateScopeFilters($validated, $authUser);
        $this->applyVisibilityScope($query, $authUser);

        // Explicit priority: report_date wins over from/to and date_from/date_to when both are present.
        if (! empty($validated['report_date'])) {
            $query->whereDate('report_date', $validated['report_date']);
        } else {
            $from = $validated['from'] ?? $validated['date_from'] ?? null;
            $to = $validated['to'] ?? $validated['date_to'] ?? null;

            if (! empty($from)) {
                $query->whereDate('report_date', '>=', $from);
            }

            if (! empty($to)) {
                $query->whereDate('report_date', '<=', $to);
            }
        }

        if (! empty($validated['role'])) {
            $query->where('role_slug', $validated['role']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['branch_id'])) {
            $effectiveBranchId = $this->branchScope->isRop($authUser)
                ? (int) $authUser->branch_id
                : (int) $validated['branch_id'];

            $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $effectiveBranchId));
        }

        if (! empty($validated['branch_group_id'])) {
            $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_group_id', $validated['branch_group_id']));
        }

        return response()->json(
            $query
                ->orderByDesc('report_date')
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function my(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = DailyReport::query()
            ->where('user_id', $this->authUser()->id)
            ->orderByDesc('report_date');

        if (! empty($validated['date_from'])) {
            $query->whereDate('report_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('report_date', '<=', $validated['date_to']);
        }

        return response()->json(
            $query->paginate((int) ($validated['per_page'] ?? 15))->withQueryString()
        );
    }

    public function showMine(string $date)
    {
        abort_unless($this->isDate($date), 422, 'Invalid report date.');

        $user = $this->authUser();
        $report = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->first();

        return response()->json([
            'report_date' => $date,
            'auto' => $this->dailyReports->autoMetrics($user, $date),
            'report' => $report,
            'manual' => [
                'ads' => $report?->ad_count ?? 0,
                'calls' => $report?->calls_count ?? 0,
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->authUser();

        $validated = $this->validatedPayload($request, true);
        $reportDate = $validated['report_date'] ?? $this->dailyReports->defaultReportDate($user);
        $this->ensureCanEditByPeriodRules($user, $user, $reportDate, null);
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);
        $payload = array_merge($metrics, [
            'role_slug' => $user->role?->slug,
            'ad_count' => $validated['ads'] ?? $validated['ads_count'] ?? $validated['ad_count'] ?? 0,
            'calls_count' => $validated['calls'] ?? $validated['calls_count'] ?? 0,
            'meetings_count' => $validated['meetings_count'] ?? 0,
            'comment' => $validated['comment'] ?? null,
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? null,
            'submitted_at' => now(),
        ]);
        if (\Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) {
            $payload['sales_count'] = $metrics['sales_count'] ?? 0;
        } else {
            unset($payload['sales_count']);
        }

        $report = DailyReport::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'report_date' => $reportDate,
            ],
            $payload
        );

        return response()->json($report->fresh('user.role'), 201);
    }

    public function update(Request $request, DailyReport $dailyReport)
    {
        $authUser = $this->authUser();
        $targetUser = $dailyReport->user()->with('role')->first();
        abort_unless($targetUser, 422, 'Daily report user not found.');

        $this->authorizeUpdateOrDeny($authUser, $targetUser);

        $validated = $this->validatedPayload($request, false);
        $reportDate = $dailyReport->report_date->toDateString();
        $this->ensureCanEditByPeriodRules($authUser, $targetUser, $reportDate, $dailyReport);
        $metrics = $this->dailyReports->autoMetrics($targetUser, $reportDate);
        $payload = array_merge($metrics, [
            'role_slug' => $targetUser->role?->slug,
            'ad_count' => $validated['ads'] ?? $validated['ads_count'] ?? $validated['ad_count'] ?? $dailyReport->ad_count,
            'calls_count' => $validated['calls'] ?? $validated['calls_count'] ?? $dailyReport->calls_count,
            'meetings_count' => $validated['meetings_count'] ?? $dailyReport->meetings_count,
            'comment' => $validated['comment'] ?? $dailyReport->comment,
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? $dailyReport->plans_for_tomorrow,
            'submitted_at' => $dailyReport->submitted_at ?? now(),
        ]);
        if (\Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) {
            $payload['sales_count'] = $metrics['sales_count'] ?? $dailyReport->sales_count;
        } else {
            unset($payload['sales_count']);
        }

        $dailyReport->update($payload);

        return response()->json($dailyReport->fresh('user.role'));
    }

    private function validatedPayload(Request $request, bool $allowReportDate): array
    {
        return $request->validate([
            'report_date' => [$allowReportDate ? 'nullable' : 'prohibited', 'date'],
            'comment' => 'nullable|string',
            'plans_for_tomorrow' => 'nullable|string',
            'ads' => 'nullable|integer|min:0',
            'ads_count' => 'nullable|integer|min:0',
            'calls' => 'nullable|integer|min:0',
            'ad_count' => 'nullable|integer|min:0',
            'calls_count' => 'nullable|integer|min:0',
            'meetings_count' => 'nullable|integer|min:0',
            'shows_count' => 'nullable|integer|min:0',
            'new_clients_count' => 'nullable|integer|min:0',
            'new_properties_count' => 'nullable|integer|min:0',
            'deposits_count' => 'nullable|integer|min:0',
            'deals_count' => 'nullable|integer|min:0',
        ]);
    }

    private function applyVisibilityScope(Builder $query, User $authUser): void
    {
        $authUser->loadMissing('role');

        match ($authUser->role?->slug) {
            'admin', 'superadmin', 'owner' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function validateScopeFilters(array $validated, User $authUser): void
    {
        if ($this->branchScope->isBranchScopedManager($authUser)) {
            if (array_key_exists('branch_id', $validated) && $validated['branch_id'] !== null) {
                $this->branchScope->ensureSameBranchOrDeny((int) $validated['branch_id'], $authUser);
            }

            if (array_key_exists('branch_group_id', $validated) && $validated['branch_group_id'] !== null) {
                $this->branchScope->ensureBranchGroupInUserBranchOrDeny((int) $validated['branch_group_id'], $authUser);
            }

            if (array_key_exists('user_id', $validated) && $validated['user_id'] !== null) {
                $this->branchScope->ensureUserInUserBranchOrDeny((int) $validated['user_id'], $authUser);
            }

            return;
        }

        if ($this->branchScope->isMop($authUser)) {
            if (array_key_exists('branch_group_id', $validated) && $validated['branch_group_id'] !== null) {
                $this->branchScope->ensureSameBranchGroupOrDeny((int) $validated['branch_group_id'], $authUser);
            }

            if (array_key_exists('user_id', $validated) && $validated['user_id'] !== null) {
                $this->branchScope->ensureUserInUserBranchGroupOrDeny((int) $validated['user_id'], $authUser);
            }

            if (array_key_exists('branch_id', $validated) && $validated['branch_id'] !== null) {
                $this->branchScope->ensureSameBranchOrDeny((int) $validated['branch_id'], $authUser);
            }
        }
    }

    private function authorizeUpdateOrDeny(User $authUser, User $targetUser): void
    {
        $role = $authUser->role?->slug;

        if ((int) $authUser->id === (int) $targetUser->id) {
            return;
        }

        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            return;
        }

        if (in_array($role, ['rop', 'branch_director'], true) && (int) $authUser->branch_id === (int) $targetUser->branch_id) {
            return;
        }

        if ($role === 'mop' && (int) $authUser->branch_group_id === (int) $targetUser->branch_group_id) {
            return;
        }

        $this->branchScope->denyWithCode(RbacBranchScope::DAILY_REPORT_EDIT_FORBIDDEN);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function isDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function isDateLocked(User $user, string $date): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('kpi_period_locks')) {
            return false;
        }

        $dayKey = Carbon::parse($date)->toDateString();
        $weekKey = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
        $monthKey = Carbon::parse($date)->format('Y-m');

        return KpiPeriodLock::query()
            ->where(function ($query) use ($dayKey, $weekKey, $monthKey) {
                $query->where(function ($q) use ($dayKey) {
                    $q->where('period_type', 'day')->where('period_key', $dayKey);
                })->orWhere(function ($q) use ($weekKey) {
                    $q->where('period_type', 'week')->where('period_key', $weekKey);
                })->orWhere(function ($q) use ($monthKey) {
                    $q->where('period_type', 'month')->where('period_key', $monthKey);
                });
            })
            ->where(function ($query) use ($user) {
                $query->whereNull('branch_id')->orWhere('branch_id', $user->branch_id);
            })
            ->where(function ($query) use ($user) {
                $query->whereNull('branch_group_id')->orWhere('branch_group_id', $user->branch_group_id);
            })
            ->exists();
    }

    private function ensureCanEditByPeriodRules(
        User $actor,
        User $targetUser,
        string $reportDate,
        ?DailyReport $report
    ): void {
        $role = $actor->role?->slug;
        $isPrivileged = in_array($role, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true);
        $isRestricted = in_array($role, ['agent', 'intern', 'mop'], true);

        if ($report && (bool) ($report->is_finalized ?? false) && !$isPrivileged) {
            $this->denyKpi('KPI_FINALIZED_EDIT_FORBIDDEN', 'Finalized KPI report cannot be edited.');
        }

        if (!$isRestricted) {
            return;
        }

        if ($this->isDateLocked($targetUser, $reportDate)) {
            $this->denyKpi('KPI_FORBIDDEN_LOCKED_PERIOD', 'Period is locked for your role.');
        }

        $deadline = Carbon::parse($reportDate, 'Asia/Dushanbe')->endOfDay();
        if (Carbon::now('Asia/Dushanbe')->greaterThan($deadline)) {
            $this->denyKpi('KPI_FORBIDDEN_DEADLINE_PASSED', 'Daily report deadline has passed for your role.');
        }
    }

    private function denyKpi(string $code, string $message, int $status = 403, array $details = []): void
    {
        abort(response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'trace_id' => request()->attributes->get('trace_id'),
        ], $status));
    }
}
