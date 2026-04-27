<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\User;
use App\Services\DailyReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyReportController extends Controller
{
    public function __construct(private readonly DailyReportService $dailyReports)
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
            $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('branch_id', $validated['branch_id']));
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
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);

        $report = DailyReport::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'report_date' => $reportDate,
            ],
            array_merge($metrics, [
                'role_slug' => $user->role?->slug,
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
        $metrics = $this->dailyReports->autoMetrics($user, $reportDate);

        $dailyReport->update(array_merge($metrics, [
            'role_slug' => $user->role?->slug,
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
            'calls_count' => 'nullable|integer|min:0',
            'meetings_count' => 'nullable|integer|min:0',
            'shows_count' => 'nullable|integer|min:0',
            'new_clients_count' => 'nullable|integer|min:0',
            'new_properties_count' => 'nullable|integer|min:0',
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
}
