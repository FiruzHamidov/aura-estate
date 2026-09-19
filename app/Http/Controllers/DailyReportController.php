<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\KpiPlan;
use App\Models\KpiPeriodLock;
use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Services\DailyReportService;
use App\Services\KpiModuleService;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyReportController extends Controller
{
    private ?\Illuminate\Support\Collection $pagePlans = null;

    private const STRICT_KPI_KEYS = ['objects', 'shows', 'ads', 'calls', 'sales'];

    public function __construct(
        private readonly DailyReportService $dailyReports,
        private readonly KpiModuleService $kpiModuleService,
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
        if ($report instanceof DailyReport) {
            app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $report);
            if ($this->preserveReportSnapshot($user, $report, $reportDate)) {
                $payload['auto'] = $report->only(['ad_count', 'calls_count', 'meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count']);
            }
        }
        $context = $this->reportContextUser($user, $report);
        $workflow = $this->myReportWorkflow($context, $reportDate, $report instanceof DailyReport ? $report : null);
        $payload['can_edit_submitted'] = $this->canEditSubmittedDailyReport($user, $context, $reportDate, $report);
        $payload['report_state'] = $workflow['state'];
        $payload['can_save_draft'] = $workflow['can_save_draft'];
        $payload['can_submit'] = $workflow['can_submit'];
        $payload['submit_available_at'] = $workflow['submit_available_at'];
        $payload['auto_metrics_live_until'] = $workflow['auto_metrics_live_until'];

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
            ->with(['user.role', 'user.branch', 'user.branchGroup', 'branchGroup', 'historicalRole']);

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

            if ($authUser->hasRole('rop')) {
                $query->whereIn('daily_reports.branch_group_id', \App\Models\BranchGroup::query()
                    ->where('branch_id', $effectiveBranchId)->select('id'));
            } else {
                $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $effectiveBranchId));
            }
        }

        if (! empty($validated['branch_group_id'])) {
            if ($authUser->hasRole('rop')) {
                $query->where('daily_reports.branch_group_id', $validated['branch_group_id']);
            } else {
                $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_group_id', $validated['branch_group_id']));
            }
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        $this->serializeReportPage($paginator);

        return response()->json($paginator);
    }

    private function serializeReportPage(LengthAwarePaginator $paginator): void
    {
        $contexts = $paginator->getCollection()->filter(fn (DailyReport $report) => $report->user)
            ->map(fn (DailyReport $report) => [$this->reportContextUser($report->user, $report), $report->report_date->toDateString()])->all();
        $this->pagePlans = $this->loadReportPlans($contexts);
        try {
            $paginator->setCollection(
                $paginator->getCollection()->map(function (DailyReport $report) {
                    return $this->serializeTeamReportRow($report);
                })
            );
        } finally {
            $this->pagePlans = null;
        }

    }

    public function my(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $actor = $this->authUser();
        $query = DailyReport::query()->with(['user.role', 'user.branch', 'user.branchGroup', 'branchGroup', 'historicalRole'])
            ->where('user_id', $actor->id)
            ->orderByDesc('report_date');

        if ($actor->hasRole('rop')) app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'daily_reports.branch_group_id');

        if (! empty($validated['date_from'])) {
            $query->whereDate('report_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('report_date', '<=', $validated['date_to']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15))->withQueryString();
        $this->serializeReportPage($paginator);

        return response()->json($paginator);
    }

    public function showMine(string $date)
    {
        abort_unless($this->isDate($date), 422, 'Invalid report date.');

        $user = $this->authUser();
        $report = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->first();

        if ($report) app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $report);
        $context = $this->reportContextUser($user, $report);
        $auto = $this->preserveReportSnapshot($user, $report, $date)
            ? $report->only(['ad_count', 'calls_count', 'meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
            : $this->dailyReports->autoMetrics($context, $date);

        return response()->json([
            'report_date' => $date,
            'auto' => $auto,
            'report' => $report,
            'manual' => [
                'ads' => $report?->ad_count ?? 0,
                'calls' => $report?->calls_count ?? 0,
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'can_edit_submitted' => $this->canEditSubmittedDailyReport($user, $context, $date, $report),
        ]);
    }

    public function myReport(Request $request)
    {
        $user = $this->authUser();
        $this->ensureDailyMyReportReadRole($user);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $date = (string) $validated['date'];
        $report = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->first();

        if ($report) app(\App\Support\RopGroupAccess::class)->ensureVisible($user, $report);
        $preserve = $this->preserveReportSnapshot($user, $report, $date);
        $context = $this->reportContextUser($user, $report);
        $auto = $preserve
            ? $report->only(['ad_count', 'calls_count', 'meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
            : $this->dailyReports->autoMetrics($context, $date);

        $metricsBundle = $this->buildMetricsPayloadBundle($context, $date, $report, $auto);
        $workflow = $this->myReportWorkflow($context, $date, $report);

        return response()->json([
            'report_date' => $date,
            'metrics' => $metricsBundle['metrics'],
            'auto' => $auto,
            'manual' => [
                'ads' => (int) ($report?->ad_count ?? 0),
                'calls' => (int) ($report?->calls_count ?? 0),
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'submitted' => $report?->submitted_at !== null,
            'submitted_at' => $report?->submitted_at,
            'report_state' => $workflow['state'],
            'can_save_draft' => $workflow['can_save_draft'],
            'can_submit' => $workflow['can_submit'],
            'submit_available_at' => $workflow['submit_available_at'],
            'auto_metrics_live_until' => $workflow['auto_metrics_live_until'],
            'meta' => [
                'locked' => $this->isDateLocked($context, $date),
                'debug' => [
                    'plan_resolution' => $metricsBundle['plan_resolution_debug'],
                    'auto_metrics' => $this->dailyReports->autoMetricsDebug($context, $date, $auto, $preserve),
                ],
            ],
        ]);
    }

    public function saveMyReportDraft(Request $request)
    {
        return $this->persistMyReport($request, false);
    }

    public function submitMyReport(Request $request)
    {
        return $this->persistMyReport($request, true);
    }

    private function persistMyReport(Request $request, bool $submit)
    {
        $this->validateStrictMetricKeys($request);
        $validated = $this->validateMyReportPayload($request);
        $reportDate = (string) $validated['report_date'];
        return DB::transaction(function () use ($validated, $reportDate, $submit) {
            $user = $this->authUser();
            [$user, $existing] = $this->lockOwnReport($user, $reportDate);
            $this->ensureDailyMyReportEditRole($user);
            $access = app(\App\Support\RopGroupAccess::class);
            if ($existing) $access->ensureVisible($user, $existing);
            $context = $this->reportContextUser($user, $existing);
            $this->ensureCanEditByPeriodRules($user, $context, $reportDate, $existing, false);
            if ($existing?->submitted_at !== null) {
                $this->denyKpi('KPI_SUBMITTED_EDIT_FORBIDDEN', 'Submitted daily report cannot be edited by current settings.');
            }
            $workflow = $this->myReportWorkflow($context, $reportDate, $existing);
            if ($submit && ! $workflow['can_submit']) {
                $this->denyKpi('KPI_REPORT_SUBMIT_TOO_EARLY',
                    "Today's daily report can be submitted after the configured start time.", 422,
                    ['submit_available_at' => $workflow['submit_available_at']]);
            }
            $report = $existing ?? new DailyReport(['user_id' => $user->id, 'report_date' => $reportDate]);
            $this->assignNewSelfReportGroup($user, $report, $reportDate);
            $report->fill(array_merge($this->myReportPersistencePayload($user, $reportDate, $validated, $existing),
                ['submitted_at' => $submit ? now() : null]));
            $report->save();
            return $this->myReport(new Request(['date' => $reportDate]));
        });
    }

    private function validateMyReportPayload(Request $request): array
    {
        return $request->validate([
            'report_date' => 'required|date_format:Y-m-d',
            'ads' => 'required|integer|min:0',
            'calls' => 'required|integer|min:0',
            'comment' => 'nullable|string|max:2000',
            'plans_for_tomorrow' => 'nullable|string|max:2000',
        ]);
    }

    private function myReportPersistencePayload(User $user, string $reportDate, array $validated, ?DailyReport $existing = null): array
    {
        $preserve = $this->preserveReportSnapshot($user, $existing, $reportDate);
        $metrics = $preserve
            ? $existing->only(['meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
            : $this->dailyReports->autoMetrics($user, $reportDate);
        $payload = [
            'role_slug' => $preserve ? $existing->role_slug : $user->role?->slug,
            'ad_count' => (int) $validated['ads'],
            'calls_count' => (int) $validated['calls'],
            'meetings_count' => (int) ($metrics['meetings_count'] ?? 0),
            'shows_count' => (int) ($metrics['shows_count'] ?? 0),
            'new_clients_count' => (int) ($metrics['new_clients_count'] ?? 0),
            'new_properties_count' => (int) ($metrics['new_properties_count'] ?? 0),
            'deals_count' => (int) ($metrics['deals_count'] ?? 0),
            'comment' => $validated['comment'] ?? '',
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? '',
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) {
            $payload['sales_count'] = (float) ($metrics['sales_count'] ?? 0);
        }

        return $payload;
    }

    public function scopeReport(Request $request)
    {
        $actor = $this->authUser();
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'employee_id' => 'required|integer|exists:users,id',
        ]);

        $targetUser = User::query()->with('role')->findOrFail((int) $validated['employee_id']);

        $date = (string) $validated['date'];
        $report = DailyReport::query()
            ->where('user_id', $targetUser->id)
            ->whereDate('report_date', $date)
            ->first();

        $this->ensureScopedReportAccess($actor, $targetUser, $report, $date);
        $preserveSnapshot = $this->preserveReportSnapshot($targetUser, $report, $date);
        $targetUser = $this->reportContextUser($targetUser, $report);
        $auto = $preserveSnapshot
            ? $report->only(['ad_count', 'calls_count', 'meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
            : $this->dailyReports->autoMetrics($targetUser, $date);

        $metricsBundle = $this->buildMetricsPayloadBundle($targetUser, $date, $report, $auto);

        return response()->json([
            'report_date' => $date,
            'employee_id' => $targetUser->id,
            'employee_name' => $targetUser->name,
            'employee_role' => (string) ($targetUser->role?->slug ?? ''),
            'editable' => $this->canActorEditTargetInScope($actor, $targetUser)
                && $this->canEditSubmittedDailyReport($actor, $targetUser, $date, $report),
            'metrics' => $metricsBundle['metrics'],
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
                'debug' => [
                    'plan_resolution' => $metricsBundle['plan_resolution_debug'],
                ],
            ],
        ]);
    }

    public function updateScopeReport(Request $request)
    {
        return DB::transaction(function () use ($request) {
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

            $reportDate = (string) $validated['report_date'];
            $report = DailyReport::query()
                ->where('user_id', $targetUser->id)
                ->whereDate('report_date', $reportDate)
                ->first();

            [$actor, $report] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)
                ->acquire($actor, $report, (int) $targetUser->id, null);
            $targetUser = User::query()->with('role')->lockForUpdate()->findOrFail($targetUser->id);
            // A concurrent creator may have inserted the unique employee/day row while we waited.
            $report ??= DailyReport::query()->where('user_id', $targetUser->id)
                ->whereDate('report_date', $reportDate)->lockForUpdate()->first();
            $this->ensureScopedReportAccess($actor, $targetUser, $report, $reportDate);
            $preserveSnapshot = $this->preserveReportSnapshot($targetUser, $report, $reportDate);
            $targetUser = $this->reportContextUser($targetUser, $report);
            $this->ensureCanEditScopedReport($actor, $targetUser);
            $this->ensureCanEditByPeriodRules($actor, $targetUser, $reportDate, $report, true);
            if (! $this->canEditSubmittedDailyReport($actor, $targetUser, $reportDate, $report)) {
                $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Submitted daily report cannot be edited by current settings.');
            }

            $metrics = $preserveSnapshot
                ? $report->only(['meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
                : $this->dailyReports->autoMetrics($targetUser, $reportDate);
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
        });
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request, true);
        return DB::transaction(function () use ($validated) {
            $user = $this->authUser();
            $reportDate = $validated['report_date'] ?? $this->dailyReports->defaultReportDate($user);
            [$user, $existing] = $this->lockOwnReport($user, $reportDate);
            $access = app(\App\Support\RopGroupAccess::class);
            if ($existing) $access->ensureVisible($user, $existing);
            $context = $this->reportContextUser($user, $existing);
            $this->ensureCanEditByPeriodRules($user, $context, $reportDate, $existing);
            if (! $this->canEditSubmittedDailyReport($user, $context, $reportDate, $existing)) {
                $this->denyKpi('KPI_SUBMITTED_EDIT_FORBIDDEN', 'Submitted daily report cannot be edited by current settings.');
            }
            $preserve = $this->preserveReportSnapshot($user, $existing, $reportDate);
            $metrics = $preserve ? [] : $this->dailyReports->autoMetrics($context, $reportDate);
            $payload = array_merge($metrics, [
                'role_slug' => $preserve ? $existing->role_slug : $user->role?->slug,
                'ad_count' => $validated['ads'] ?? $validated['ads_count'] ?? $validated['ad_count'] ?? 0,
                'calls_count' => $validated['calls'] ?? $validated['calls_count'] ?? 0,
                'meetings_count' => $validated['meetings_count'] ?? $existing?->meetings_count ?? 0,
                'comment' => $validated['comment'] ?? null,
                'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? null,
                'submitted_at' => $existing?->submitted_at ?? now(),
            ]);
            if (! \Illuminate\Support\Facades\Schema::hasColumn('daily_reports', 'sales_count')) unset($payload['sales_count']);
            $report = $existing ?? new DailyReport(['user_id' => $user->id, 'report_date' => $reportDate]);
            $this->assignNewSelfReportGroup($user, $report, $reportDate);
            $report->fill($payload)->save();
            return response()->json($report->fresh('user.role'), 201);
        });
    }

    /** Caller owns the transaction; all self writers serialize on the same user. */
    private function lockOwnReport(User $user, string $date): array
    {
        $report = DailyReport::query()->where('user_id', $user->id)->whereDate('report_date', $date)->first();
        [$user, $report] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($user, $report, null, null);
        $report ??= DailyReport::query()->where('user_id', $user->id)->whereDate('report_date', $date)->lockForUpdate()->first();
        return [$user, $report];
    }

    private function assignNewSelfReportGroup(User $user, DailyReport $report, string $date): void
    {
        $access = app(\App\Support\RopGroupAccess::class);
        if (! $report->exists && $access->applies($user)) {
            abort_unless($date === Carbon::now($this->timezone())->toDateString(), 422, 'HISTORICAL_GROUP_UNCLASSIFIED');
            $report->branch_group_id = $access->creationGroup($user, null);
        }
    }

    public function update(Request $request, DailyReport $dailyReport)
    {
        return DB::transaction(function () use ($request, $dailyReport) {
            [$authUser, $dailyReport] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)
                ->acquire($this->authUser(), $dailyReport, null, null);
            app(\App\Support\RopGroupAccess::class)->ensureVisible($authUser, $dailyReport);
            $targetUser = $dailyReport->user()->with('role')->first();
            abort_unless($targetUser, 422, 'Daily report user not found.');

            $this->authorizeUpdateOrDeny($authUser, $targetUser, $dailyReport);

            $validated = $this->validatedPayload($request, false);
            $reportDate = $dailyReport->report_date->toDateString();
            $this->ensureCanEditByPeriodRules($authUser, $targetUser, $reportDate, $dailyReport);
            if (! $this->canEditSubmittedDailyReport($authUser, $targetUser, $reportDate, $dailyReport)) {
                $this->denyKpi('KPI_SUBMITTED_EDIT_FORBIDDEN', 'Submitted daily report cannot be edited by current settings.');
            }
            // Historical facts keep the context in which the report was recorded.
            $preserveSnapshot = $this->preserveReportSnapshot($targetUser, $dailyReport, $reportDate);
            $metrics = $preserveSnapshot ? [] : $this->dailyReports->autoMetrics($targetUser, $reportDate);
            $payload = array_merge($metrics, [
                'role_slug' => $preserveSnapshot ? $dailyReport->role_slug : $targetUser->role?->slug,
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
        });
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
            'rop' => app(\App\Support\RopGroupAccess::class)->scope($query, $authUser, 'daily_reports.branch_group_id'),
            'branch_director' => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $authUser->branch_id)),
            'mop', 'intern' => $query->where('user_id', $authUser->id),
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
                if (! $authUser->hasRole('rop') || ! app(\App\Support\RopGroupAccess::class)
                    ->hasVisibleDailyReportHistory($authUser, (int) $validated['user_id'])) {
                    $this->branchScope->ensureUserInUserBranchOrDeny((int) $validated['user_id'], $authUser);
                }
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

    private function authorizeUpdateOrDeny(User $authUser, User $targetUser, DailyReport $report): void
    {
        $role = $authUser->role?->slug;

        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            return;
        }

        if ($role === 'rop') {
            app(\App\Support\RopGroupAccess::class)->ensureVisible($authUser, $report);
            return;
        }

        if ($role === 'branch_director' && (int) $authUser->branch_id === (int) $targetUser->branch_id) {
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

    private function ensureDailyMyReportReadRole(User $user): void
    {
        if (! in_array($user->role?->slug, ['agent', 'mop', 'intern', 'rop', 'branch_director', 'admin', 'superadmin', 'owner'], true)) {
            $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
        }
    }

    private function ensureDailyMyReportEditRole(User $user): void
    {
        if (! in_array($user->role?->slug, ['agent', 'mop', 'intern', 'rop', 'branch_director', 'admin', 'superadmin', 'owner'], true)) {
            $this->denyKpi('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed for this action.');
        }
    }

    private function ensureScopedReportAccess(User $actor, User $employee, ?DailyReport $report, string $date): void
    {
        if (! $actor->hasRole('rop')) {
            $this->ensureCanReadScopedReport($actor, $employee);
            return;
        }
        $access = app(\App\Support\RopGroupAccess::class);
        if ($report) {
            $access->ensureVisible($actor, $report);
            abort_unless(in_array($report->role_slug, ['agent', 'mop'], true), 403, 'KPI_FORBIDDEN_ROLE_ACTION');
            return;
        }
        // A missing historical snapshot cannot be assigned to today's team.
        abort_unless($date === Carbon::now($this->timezone())->toDateString(), 404, 'NOT_FOUND');
        $access->ensureEmployee($actor, (int) $employee->id);
    }

    private function preserveReportSnapshot(User $employee, ?DailyReport $report, string $date): bool
    {
        return $report && ($date !== Carbon::now($this->timezone())->toDateString()
            || (int) $report->branch_group_id !== (int) $employee->branch_group_id);
    }

    private function reportContextUser(User $employee, ?DailyReport $report): User
    {
        if (! $report || ! $report->branch_group_id) {
            return $employee;
        }
        $report->loadMissing(['branchGroup', 'historicalRole']);
        $context = clone $employee;
        $context->branch_group_id = $report->branch_group_id;
        $context->branch_id = $report->branchGroup?->branch_id;
        $role = $report->historicalRole;
        $context->role_id = $role?->id;
        $context->setRelation('role', $role);
        return $context;
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

        if (in_array($actorRole, ['mop', 'intern'], true)) {
            if ((int) $actor->id !== (int) $targetUser->id) {
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

    private function metricPayload(int|float $fact, int|float|null $target, string $source, ?string $planSource): array
    {
        return [
            'fact_value' => $fact,
            'final_value' => $fact,
            'target_value' => $target,
            'plan_source' => $planSource,
            // Backward-compatible aliases for legacy frontend consumers.
            'fact' => $fact,
            'target' => $target,
            'source' => $source,
        ];
    }

    private function buildMetricsPayload(User $user, string $reportDate, ?DailyReport $report, ?array $auto = null): array
    {
        return $this->buildMetricsPayloadBundle($user, $reportDate, $report, $auto)['metrics'];
    }

    private function buildMetricsPayloadBundle(User $user, string $reportDate, ?DailyReport $report, ?array $auto = null): array
    {
        $autoMetrics = $auto ?? $this->dailyReports->autoMetrics($user, $reportDate);
        $planBundle = $this->resolvePlanMap($user, $reportDate);
        $planMap = (array) ($planBundle['plans'] ?? []);

        return [
            'metrics' => [
                'objects' => $this->metricPayload((int) ($autoMetrics['new_properties_count'] ?? 0), $planMap['objects']['target_value'], 'system', $planMap['objects']['plan_source']),
                'shows' => $this->metricPayload((int) ($autoMetrics['shows_count'] ?? 0), $planMap['shows']['target_value'], 'system', $planMap['shows']['plan_source']),
                'ads' => $this->metricPayload((int) ($report?->ad_count ?? 0), $planMap['ads']['target_value'], 'manual', $planMap['ads']['plan_source']),
                'calls' => $this->metricPayload((int) ($report?->calls_count ?? 0), $planMap['calls']['target_value'], 'manual', $planMap['calls']['plan_source']),
                'sales' => $this->metricPayload((float) ($autoMetrics['sales_count'] ?? 0), $planMap['sales']['target_value'], 'system', $planMap['sales']['plan_source']),
            ],
            'plan_resolution_debug' => [
                'employee_id' => (int) $user->id,
                'date' => $reportDate,
                'metrics' => (array) ($planBundle['debug_metrics'] ?? []),
            ],
        ];
    }

    /** One authorized plan query for a page; each row is resolved against its own date/context below. */
    private function loadReportPlans(array $contexts): \Illuminate\Support\Collection
    {
        if ($contexts === [] || ! \Illuminate\Support\Facades\Schema::hasTable('kpi_plans')) return collect();
        $actor = $this->authUser();
        $access = app(\App\Support\RopGroupAccess::class);
        $query = KpiPlan::query()->whereIn('metric_key', self::STRICT_KPI_KEYS);
        if ($access->applies($actor)) $access->scope($query, $actor, 'kpi_plans.branch_group_id', 'kpi_plans.branch_id');
        $query->where(function (Builder $query) use ($contexts, $actor): void {
            foreach ($contexts as [$user, $date]) {
                $query->orWhere(function (Builder $context) use ($user, $date, $actor): void {
                    $context->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                        ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
                    if ($actor->hasRole('rop')) {
                        $context->where('branch_group_id', $user->branch_group_id)->where('role_slug', $user->role?->slug);
                    }
                    $context->where(function (Builder $q) use ($user): void {
                        $q->where('user_id', $user->id)->orWhere(function (Builder $shared) use ($user): void {
                            $shared->whereNull('user_id')->where('role_slug', $user->role?->slug)
                                ->where(function (Builder $scope) use ($user): void {
                                    $scope->where(fn (Builder $q) => $q->whereNull('branch_id')->whereNull('branch_group_id'));
                                    if ($user->branch_id !== null) {
                                        $scope->orWhere(fn (Builder $q) => $q->where('branch_id', $user->branch_id)->whereNull('branch_group_id'));
                                        if ($user->branch_group_id !== null) {
                                            $scope->orWhere(fn (Builder $q) => $q->where('branch_id', $user->branch_id)->where('branch_group_id', $user->branch_group_id));
                                        }
                                    }
                                });
                        });
                    });
                });
            }
        });
        return $query->orderByDesc('id')->get();
    }

    private function resolvePlanMap(User $user, string $reportDate): array
    {
        $rop = $this->authUser()->hasRole('rop');
        $plans = [
            'objects' => ['target_value' => null, 'plan_source' => null, 'source_record_id' => null],
            'shows' => ['target_value' => null, 'plan_source' => null, 'source_record_id' => null],
            'ads' => ['target_value' => null, 'plan_source' => null, 'source_record_id' => null],
            'calls' => ['target_value' => null, 'plan_source' => null, 'source_record_id' => null],
            'sales' => ['target_value' => null, 'plan_source' => null, 'source_record_id' => null],
        ];

        if (\Illuminate\Support\Facades\Schema::hasTable('kpi_plans')) {
            $user->loadMissing('role');
            $roleSlug = (string) ($user->role?->slug ?? '');
            $branchId = $user->branch_id ? (int) $user->branch_id : null;
            $branchGroupId = $user->branch_group_id ? (int) $user->branch_group_id : null;

            $rows = ($this->pagePlans ?? $this->loadReportPlans([[$user, $reportDate]]))
                ->filter(function (KpiPlan $plan) use ($user, $reportDate, $roleSlug, $branchGroupId, $rop): bool {
                    if (($plan->effective_from && $plan->effective_from->toDateString() > $reportDate)
                        || ($plan->effective_to && $plan->effective_to->toDateString() < $reportDate)) return false;
                    if ($rop && ((int) $plan->branch_group_id !== (int) $branchGroupId || $plan->role_slug !== $roleSlug)) return false;
                    return $plan->user_id !== null ? (int) $plan->user_id === (int) $user->id : $plan->role_slug === $roleSlug;
                })->groupBy('metric_key');

            foreach (array_keys($plans) as $metricKey) {
                $candidates = $rows->get($metricKey, collect());
                $chosen = $candidates->first(fn (KpiPlan $plan) => (int) $plan->user_id === (int) $user->id);
                $source = 'personal';
                if (! $chosen) {
                    $shared = $candidates->filter(fn (KpiPlan $plan) => $plan->user_id === null);
                    $chosen = $branchGroupId !== null && $branchId !== null
                        ? $shared->first(fn (KpiPlan $plan) => (int) $plan->branch_group_id === $branchGroupId && (int) $plan->branch_id === $branchId)
                        : null;
                    $chosen ??= $branchId !== null
                        ? $shared->first(fn (KpiPlan $plan) => $plan->branch_group_id === null && (int) $plan->branch_id === $branchId)
                        : null;
                    $chosen ??= $shared->first(fn (KpiPlan $plan) => $plan->branch_group_id === null && $plan->branch_id === null);
                    $source = $chosen && ($chosen->branch_id !== null || $chosen->branch_group_id !== null) ? 'rop' : 'common';
                }
                if ($chosen) {
                    $plans[$metricKey] = [
                        'target_value' => (float) $chosen->daily_plan,
                        'plan_source' => $source,
                        'source_record_id' => (int) $chosen->id,
                    ];
                }
            }
        }

        $debugMetrics = [];
        foreach ($plans as $metricKey => $metricPlan) {
            $debugMetrics[$metricKey] = [
                'resolved_from' => $metricPlan['plan_source'] ?? 'system',
                'target_value' => $metricPlan['target_value'],
                'source_record_id' => $metricPlan['source_record_id'] ?? null,
            ];
        }

        return [
            'plans' => $plans,
            'debug_metrics' => $debugMetrics,
        ];
    }

    private function serializeTeamReportRow(DailyReport $report): array
    {
        $report->loadMissing(['user.role', 'user.branch', 'user.branchGroup']);
        $user = $report->user;
        $actor = $this->authUser();
        $userPayload = $actor->hasRole('rop')
            ? (app(\App\Services\GroupAccess\GroupDataProjection::class)->relations($report, $actor)['user'] ?? null)
            : $user;
        $reportDate = $report->report_date->toDateString();

        $preserveSnapshot = ! $user || $this->preserveReportSnapshot($user, $report, $reportDate);
        $user = $user ? $this->reportContextUser($user, $report) : null;
        $autoMetrics = $preserveSnapshot
            ? $report->only(['calls_count', 'meetings_count', 'shows_count', 'new_clients_count', 'new_properties_count', 'deals_count', 'sales_count'])
            : $this->dailyReports->autoMetrics($user, $reportDate);


        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'role_slug' => $report->role_slug,
            'report_date' => $reportDate,
            'metrics' => $user ? $this->buildMetricsPayload($user, $reportDate, $report, $autoMetrics) : [
                'objects' => $this->metricPayload((int) ($autoMetrics['new_properties_count'] ?? 0), null, 'system', null),
                'shows' => $this->metricPayload((int) ($autoMetrics['shows_count'] ?? 0), null, 'system', null),
                'ads' => $this->metricPayload((int) ($report->ad_count ?? 0), null, 'manual', null),
                'calls' => $this->metricPayload((int) ($report->calls_count ?? 0), null, 'manual', null),
                'sales' => $this->metricPayload((float) ($autoMetrics['sales_count'] ?? 0), null, 'system', null),
            ],
            'manual' => [
                'ads' => (int) ($report->ad_count ?? 0),
                'calls' => (int) ($report->calls_count ?? 0),
                'comment' => (string) ($report->comment ?? ''),
                'plans_for_tomorrow' => (string) ($report->plans_for_tomorrow ?? ''),
            ],
            'ads' => (int) ($report->ad_count ?? 0),
            'calls' => (int) ($report->calls_count ?? 0),
            'calls_count' => (int) ($autoMetrics['calls_count'] ?? 0),
            'meetings_count' => (int) ($autoMetrics['meetings_count'] ?? 0),
            'shows_count' => (int) ($autoMetrics['shows_count'] ?? 0),
            'new_clients_count' => (int) ($autoMetrics['new_clients_count'] ?? 0),
            'new_properties_count' => (int) ($autoMetrics['new_properties_count'] ?? 0),
            'deals_count' => (int) ($autoMetrics['deals_count'] ?? 0),
            'sales_count' => (float) ($autoMetrics['sales_count'] ?? 0),
            'comment' => (string) ($report->comment ?? ''),
            'plans_for_tomorrow' => (string) ($report->plans_for_tomorrow ?? ''),
            'submitted' => $report->submitted_at !== null,
            'submitted_at' => $report->submitted_at,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
            'user' => $userPayload,
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Dushanbe');
    }

    /**
     * Server-authoritative workflow state for the self-service daily report UI.
     * Manual fields are fixed on submit, while system metrics remain live until
     * the selected local day ends.
     */
    private function myReportWorkflow(User $user, string $reportDate, ?DailyReport $report): array
    {
        $timezone = $this->timezone();
        $now = Carbon::now($timezone);
        $day = Carbon::parse($reportDate, $timezone)->startOfDay();
        $today = $now->copy()->startOfDay();
        [$hour, $minute] = $this->dailyReportSubmitStartTime();
        $submitAvailableAt = $day->copy()->setTime($hour, $minute);
        $liveUntil = $day->copy()->endOfDay();
        $locked = $this->isDateLocked($user, $reportDate);
        $submitted = $report?->submitted_at !== null;

        if ($submitted) {
            $state = $day->equalTo($today) && $now->lte($liveUntil)
                ? 'submitted_live'
                : 'finalized';
        } elseif ($day->lt($today) || ($day->equalTo($today) && $now->gte($submitAvailableAt))) {
            $state = 'ready_to_submit';
        } else {
            $state = 'draft';
        }

        return [
            'state' => $state,
            'can_save_draft' => ! $submitted && ! $locked && $day->lte($today),
            'can_submit' => ! $submitted
                && ! $locked
                && ($day->lt($today) || ($day->equalTo($today) && $now->gte($submitAvailableAt))),
            'submit_available_at' => $submitAvailableAt->toIso8601String(),
            'auto_metrics_live_until' => $liveUntil->toIso8601String(),
        ];
    }

    /** @return array{0:int,1:int} */
    private function dailyReportSubmitStartTime(): array
    {
        $raw = (string) config('kpi.daily_report.submit_start_time', '20:00');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $matches) !== 1) {
            return [20, 0];
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59
            ? [$hour, $minute]
            : [20, 0];
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
