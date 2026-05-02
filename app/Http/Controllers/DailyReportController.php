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
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
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

        if (! empty($validated['date_from'])) {
            $query->whereDate('report_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('report_date', '<=', $validated['date_to']);
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
                'ad_count' => $report?->ad_count ?? 0,
                'meetings_count' => $report?->meetings_count ?? 0,
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
        abort_if($this->isDateLocked($user, $reportDate), 422, 'Period is locked. Use KPI adjustment endpoint.');
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);

        $report = DailyReport::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'report_date' => $reportDate,
            ],
            array_merge($metrics, [
                'role_slug' => $user->role?->slug,
                'ad_count' => $validated['ad_count'] ?? 0,
                'meetings_count' => $validated['meetings_count'] ?? 0,
                'comment' => $validated['comment'] ?? null,
                'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? null,
                'submitted_at' => now(),
            ])
        );

        return response()->json($report->fresh('user.role'), 201);
    }

    public function update(Request $request, DailyReport $dailyReport)
    {
        $user = $this->authUser();

        abort_unless((int) $dailyReport->user_id === (int) $user->id, 403, 'Forbidden');

        $validated = $this->validatedPayload($request, false);
        $reportDate = $dailyReport->report_date->toDateString();
        abort_if($this->isDateLocked($user, $reportDate), 422, 'Period is locked. Use KPI adjustment endpoint.');
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);

        $dailyReport->update(array_merge($metrics, [
            'role_slug' => $user->role?->slug,
            'ad_count' => $validated['ad_count'] ?? $dailyReport->ad_count,
            'meetings_count' => $validated['meetings_count'] ?? $dailyReport->meetings_count,
            'comment' => $validated['comment'] ?? $dailyReport->comment,
            'plans_for_tomorrow' => $validated['plans_for_tomorrow'] ?? $dailyReport->plans_for_tomorrow,
            'submitted_at' => $dailyReport->submitted_at ?? now(),
        ]));

        return response()->json($dailyReport->fresh('user.role'));
    }

    private function validatedPayload(Request $request, bool $allowReportDate): array
    {
        return $request->validate([
            'report_date' => [$allowReportDate ? 'nullable' : 'prohibited', 'date'],
            'comment' => 'nullable|string',
            'plans_for_tomorrow' => 'nullable|string',
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
            'admin', 'superadmin' => null,
            'rop', 'branch_director' => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('user_id', $authUser->id),
        };
    }

    private function validateScopeFilters(array $validated, User $authUser): void
    {
        if (! $this->branchScope->isBranchScopedManager($authUser)) {
            return;
        }

        if (array_key_exists('branch_id', $validated) && $validated['branch_id'] !== null) {
            $this->branchScope->ensureSameBranchOrDeny((int) $validated['branch_id'], $authUser);
        }

        if (array_key_exists('branch_group_id', $validated) && $validated['branch_group_id'] !== null) {
            $this->branchScope->ensureBranchGroupInUserBranchOrDeny((int) $validated['branch_group_id'], $authUser);
        }

        if (array_key_exists('user_id', $validated) && $validated['user_id'] !== null) {
            $this->branchScope->ensureUserInUserBranchOrDeny((int) $validated['user_id'], $authUser);
        }
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
}
