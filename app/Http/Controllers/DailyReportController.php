<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\KpiPeriodLock;
use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Services\DailyReportService;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class DailyReportController extends Controller
{
    private const STRICT_KPI_KEYS = ['objects', 'shows', 'ads', 'calls', 'sales'];

    public function __construct(
        private readonly DailyReportService $dailyReports,
        private readonly RbacBranchScope $branchScope
    )
    {
    }

    public function status(Request $request)
    {
        $user = $this->authUser();
        $payload = $this->dailyReports->reportStatusPayload($user, $request->input('report_date'));
        $reportDate = (string) ($payload['report_date'] ?? $this->dailyReports->defaultReportDate($user));
        $report = $payload['report'] ?? null;
        $payload['can_edit_submitted'] = $this->canEditSubmittedDailyReport($user, $user, $reportDate, $report);

        return response()->json($payload);
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
            'can_edit_submitted' => $this->canEditSubmittedDailyReport($user, $user, $date, $report),
        ]);
    }

    public function myReport(Request $request)
    {
        $user = $this->authUser();
        $this->ensureDailyMyReportRole($user);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $date = (string) $validated['date'];
        $report = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->first();

        $auto = $this->dailyReports->autoMetrics($user, $date);

        return response()->json([
            'report_date' => $date,
            'metrics' => [
                'objects' => $this->metricPayload((int) ($auto['new_properties_count'] ?? 0), 'system', 'objects'),
                'shows' => $this->metricPayload((int) ($auto['shows_count'] ?? 0), 'system', 'shows'),
                'ads' => $this->metricPayload((int) ($report?->ad_count ?? 0), 'manual', 'ads'),
                'calls' => $this->metricPayload((int) ($report?->calls_count ?? 0), 'manual', 'calls'),
                'sales' => $this->metricPayload((float) ($auto['sales_count'] ?? 0), 'system', 'sales'),
            ],
            'manual' => [
                'ads' => (int) ($report?->ad_count ?? 0),
                'calls' => (int) ($report?->calls_count ?? 0),
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'submitted' => $report?->submitted_at !== null,
            'submitted_at' => $report?->submitted_at,
            'meta' => [
                'locked' => $this->isDateLocked($user, $date),
            ],
        ]);
    }

    public function submitMyReport(Request $request)
    {
        $user = $this->authUser();
        $this->ensureDailyMyReportRole($user);

        $this->validateStrictMetricKeys($request);

        $validated = $request->validate([
            'report_date' => 'required|date_format:Y-m-d',
            'ads' => 'required|integer|min:0',
            'calls' => 'required|integer|min:0',
            'comment' => 'nullable|string|max:2000',
            'plans_for_tomorrow' => 'nullable|string|max:2000',
        ]);

        $reportDate = (string) $validated['report_date'];
        $this->ensureCanEditByPeriodRules($user, $user, $reportDate, null, true);
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);

        $payload = [
            'role_slug' => $user->role?->slug,
            'ad_count' => (int) $validated['ads'],
            'calls_count' => (int) $validated['calls'],
            'meetings_count' => (int) ($metrics['meetings_count'] ?? 0),
            'shows_count' => (int) ($metrics['shows_count'] ?? 0),
            'new_clients_count' => (int) ($metrics['new_clients_count'] ?? 0),
            'new_properties_count' => (int) ($metrics['new_properties_count'] ?? 0),
            'deals_count' => (int) ($metrics['deals_count'] ?? 0),
            'comment' => $validated['comment'] ?? '',
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? '',
            'submitted_at' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) {
            $payload['sales_count'] = (float) ($metrics['sales_count'] ?? 0);
        }

        $existing = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $reportDate)
            ->first();

        if ($existing) {
            $existing->update($payload);
        } else {
            DailyReport::query()->create(array_merge($payload, [
                'user_id' => $user->id,
                'report_date' => $reportDate,
            ]));
        }

        return $this->myReport(new Request(['date' => $reportDate]));
    }

    public function scopeReport(Request $request)
    {
        $actor = $this->authUser();
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'employee_id' => 'required|integer|exists:users,id',
        ]);

        $targetUser = User::query()->with('role')->findOrFail((int) $validated['employee_id']);
        $this->ensureCanReadScopedReport($actor, $targetUser);

        $date = (string) $validated['date'];
        $report = DailyReport::query()
            ->where('user_id', $targetUser->id)
            ->whereDate('report_date', $date)
            ->first();

        $auto = $this->dailyReports->autoMetrics($targetUser, $date);

        return response()->json([
            'report_date' => $date,
            'employee_id' => $targetUser->id,
            'employee_name' => $targetUser->name,
            'employee_role' => (string) ($targetUser->role?->slug ?? ''),
            'editable' => $this->canActorEditTargetInScope($actor, $targetUser)
                && $this->canEditSubmittedDailyReport($actor, $targetUser, $date, $report),
            'metrics' => [
                'objects' => $this->metricPayload((int) ($auto['new_properties_count'] ?? 0), 'system', 'objects'),
                'shows' => $this->metricPayload((int) ($auto['shows_count'] ?? 0), 'system', 'shows'),
                'ads' => $this->metricPayload((int) ($report?->ad_count ?? 0), 'manual', 'ads'),
                'calls' => $this->metricPayload((int) ($report?->calls_count ?? 0), 'manual', 'calls'),
                'sales' => $this->metricPayload((float) ($auto['sales_count'] ?? 0), 'system', 'sales'),
            ],
            'manual' => [
                'ads' => (int) ($report?->ad_count ?? 0),
                'calls' => (int) ($report?->calls_count ?? 0),
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'submitted' => $report?->submitted_at !== null,
            'submitted_at' => $report?->submitted_at,
            'meta' => [
                'locked' => $this->isDateLocked($targetUser, $date),
                'scope' => [
                    'actor_role' => $actor->role?->slug,
                    'actor_branch_id' => $actor->branch_id,
                    'actor_branch_group_id' => $actor->branch_group_id,
                ],
            ],
        ]);
    }

    public function updateScopeReport(Request $request)
    {
        $actor = $this->authUser();
        $this->validateStrictMetricKeys($request, [
            'report_date',
            'employee_id',
            'ads',
            'calls',
            'comment',
            'plans_for_tomorrow',
            'updated_reason',
            'edit_source',
        ]);

        $validated = $request->validate([
            'report_date' => 'required|date_format:Y-m-d',
            'employee_id' => 'required|integer|exists:users,id',
            'ads' => 'required|integer|min:0',
            'calls' => 'required|integer|min:0',
            'comment' => 'nullable|string|max:2000',
            'plans_for_tomorrow' => 'nullable|string|max:2000',
            'updated_reason' => 'nullable|string|max:500',
            'edit_source' => 'nullable|string|max:64',
        ]);

        $targetUser = User::query()->with('role')->findOrFail((int) $validated['employee_id']);
        $this->ensureCanEditScopedReport($actor, $targetUser);

        $reportDate = (string) $validated['report_date'];
        $report = DailyReport::query()
            ->where('user_id', $targetUser->id)
            ->whereDate('report_date', $reportDate)
            ->first();

        $this->ensureCanEditByPeriodRules($actor, $targetUser, $reportDate, $report, true);
        if (! $this->canEditSubmittedDailyReport($actor, $targetUser, $reportDate, $report)) {
            $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Submitted daily report cannot be edited by current settings.');
        }

        $metrics = $this->dailyReports->autoMetrics($targetUser, $reportDate);
        $payload = [
            'role_slug' => $targetUser->role?->slug,
            'ad_count' => (int) $validated['ads'],
            'calls_count' => (int) $validated['calls'],
            'meetings_count' => (int) ($metrics['meetings_count'] ?? 0),
            'shows_count' => (int) ($metrics['shows_count'] ?? 0),
            'new_clients_count' => (int) ($metrics['new_clients_count'] ?? 0),
            'new_properties_count' => (int) ($metrics['new_properties_count'] ?? 0),
            'deals_count' => (int) ($metrics['deals_count'] ?? 0),
            'comment' => $validated['comment'] ?? '',
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? '',
            'submitted_at' => $report?->submitted_at ?? now(),
            'updated_by' => $actor->id,
            'updated_by_role' => (string) ($actor->role?->slug ?? ''),
            'updated_reason' => $validated['updated_reason'] ?? null,
            'edit_source' => $validated['edit_source'] ?? 'supervisor',
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) {
            $payload['sales_count'] = (float) ($metrics['sales_count'] ?? 0);
        }

        if ($report) {
            $report->update($payload);
        } else {
            DailyReport::query()->create(array_merge($payload, [
                'user_id' => $targetUser->id,
                'report_date' => $reportDate,
            ]));
        }

        return $this->scopeReport(new Request([
            'date' => $reportDate,
            'employee_id' => $targetUser->id,
        ]));
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
        if (! $this->canEditSubmittedDailyReport($authUser, $targetUser, $reportDate, $dailyReport)) {
            $this->denyKpi('KPI_SUBMITTED_EDIT_FORBIDDEN', 'Submitted daily report cannot be edited by current settings.');
        }
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
        ?DailyReport $report,
        bool $enforceDeadline = false
    ): void {
        $role = $actor->role?->slug;
        $isPrivileged = in_array($role, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true);
        $isRestricted = in_array($role, ['agent', 'intern', 'mop'], true);

        if ($report && (bool) ($report->is_finalized ?? false) && !$isPrivileged) {
            $this->denyKpi('KPI_FINALIZED_EDIT_FORBIDDEN', 'Finalized KPI report cannot be edited.');
        }

        if (!$isRestricted && !$enforceDeadline) {
            return;
        }

        if ($this->isDateLocked($targetUser, $reportDate)) {
            $this->denyKpi('KPI_FORBIDDEN_LOCKED_PERIOD', 'Period is locked for your role.');
        }

        if ($enforceDeadline) {
            $reportDeadline = Carbon::parse($reportDate, $this->timezone())->endOfDay();
            if (Carbon::now($this->timezone())->gt($reportDeadline)) {
                $this->denyKpi('KPI_FORBIDDEN_DEADLINE_PASSED', 'Deadline for this report date has passed.');
            }
        }

    }

    private function ensureDailyMyReportRole(User $user): void
    {
        if (! in_array($user->role?->slug, ['agent', 'mop'], true)) {
            $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
        }
    }

    private function ensureCanReadScopedReport(User $actor, User $targetUser): void
    {
        $actorRole = (string) ($actor->role?->slug ?? '');
        $targetRole = (string) ($targetUser->role?->slug ?? '');

        if ($actorRole === 'agent') {
            if ((int) $actor->id !== (int) $targetUser->id) {
                $this->denyKpi('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
            }

            return;
        }

        if ($actorRole === 'mop') {
            if ($targetRole !== 'agent') {
                $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
            }

            if ((int) $actor->branch_group_id !== (int) $targetUser->branch_group_id) {
                $this->denyKpi('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
            }

            return;
        }

        if (in_array($actorRole, ['rop', 'branch_director', 'admin', 'superadmin', 'owner'], true)) {
            if (! in_array($targetRole, ['agent', 'mop'], true)) {
                $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Target role is not supported for this action.');
            }

            if (in_array($actorRole, ['rop', 'branch_director'], true)
                && (int) $actor->branch_id !== (int) $targetUser->branch_id) {
                $this->denyKpi('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
            }

            return;
        }

        $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
    }

    private function ensureCanEditScopedReport(User $actor, User $targetUser): void
    {
        $this->ensureCanReadScopedReport($actor, $targetUser);
        if (! $this->canActorEditTargetInScope($actor, $targetUser)) {
            $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
        }
    }

    private function canActorEditTargetInScope(User $actor, User $targetUser): bool
    {
        $actorRole = (string) ($actor->role?->slug ?? '');
        $targetRole = (string) ($targetUser->role?->slug ?? '');

        if ($actorRole === 'mop') {
            return $targetRole === 'agent' && (int) $actor->branch_group_id === (int) $targetUser->branch_group_id;
        }

        if (in_array($actorRole, ['rop', 'branch_director'], true)) {
            return in_array($targetRole, ['agent', 'mop'], true) && (int) $actor->branch_id === (int) $targetUser->branch_id;
        }

        if (in_array($actorRole, ['admin', 'superadmin', 'owner'], true)) {
            return in_array($targetRole, ['agent', 'mop'], true);
        }

        return (int) $actor->id === (int) $targetUser->id;
    }

    private function validateStrictMetricKeys(Request $request, array $knownPayloadFields = ['report_date', 'ads', 'calls', 'comment', 'plans_for_tomorrow']): void
    {
        $legacyKpiLike = ['objects_count', 'shows_count', 'sales_count', 'deals_count', 'deals', 'call', 'show', 'deal'];

        $errors = [];
        foreach (array_keys($request->all()) as $field) {
            if (in_array($field, $knownPayloadFields, true)) {
                continue;
            }

            if (in_array($field, self::STRICT_KPI_KEYS, true) || in_array($field, $legacyKpiLike, true)) {
                $errors[$field] = ['This metric is not writable for this endpoint.'];
                continue;
            }

            if (str_contains($field, 'count') || str_contains($field, 'metric') || str_contains($field, 'kpi')) {
                $errors[$field] = ['Unknown KPI key.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function metricPayload(int|float $fact, string $source, string $metricKey): array
    {
        $payload = [
            'fact' => $fact,
            'source' => $source,
        ];

        $target = config('kpi.v2.targets.'.$metricKey);
        if (is_int($target) || is_float($target)) {
            $payload['target'] = $target;
        }

        return $payload;
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Dushanbe');
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

    private function canEditSubmittedDailyReport(
        User $actor,
        User $targetUser,
        string $reportDate,
        mixed $report
    ): bool {
        $isSubmitted = $report instanceof DailyReport && $report->submitted_at !== null;
        if (! $isSubmitted) {
            return true;
        }

        $role = $actor->role?->slug;
        if (in_array($role, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true)) {
            return true;
        }

        if ((int) $actor->id !== (int) $targetUser->id) {
            return true;
        }

        if (in_array($role, ['agent', 'intern', 'mop'], true) && $this->isDateLocked($targetUser, $reportDate)) {
            return false;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('user_daily_report_reminder_settings')) {
            return false;
        }

        $setting = UserDailyReportReminderSetting::query()
            ->where('user_id', $targetUser->id)
            ->first();

        return (bool) ($setting?->allow_edit_submitted_daily_report ?? false);
    }
}
