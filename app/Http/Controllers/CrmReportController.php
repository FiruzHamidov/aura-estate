<?php

namespace App\Http\Controllers;

use App\Models\CrmAuditLog;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CrmReportController extends Controller
{
    private const REPORT_EVENTS = [
        'comment',
        'call',
        'status_change',
        'assignment',
        'follow_up_changed',
        'tag_added',
        'tag_removed',
        'updated',
    ];

    public function performance(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'role_type' => ['required', Rule::in(['operator', 'manager'])],
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'quarter', 'year'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
        ]);

        [$from, $to] = $this->resolveDateRange($validated);
        $scope = $this->resolveScope($authUser, $validated);

        $users = User::query()
            ->with(['role', 'branch'])
            ->whereHas('role', fn ($query) => $query->where('slug', $validated['role_type']))
            ->when($scope['branch_id'], fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($scope['responsible_user_id'], fn ($query, $responsibleUserId) => $query->whereKey($responsibleUserId))
            ->orderBy('name')
            ->get();

        $rows = $users->map(function (User $user) use ($validated, $from, $to, $scope) {
            $metrics = $validated['role_type'] === 'operator'
                ? $this->operatorMetrics($user, $from, $to, $scope['branch_id'])
                : $this->managerMetrics($user, $from, $to, $scope['branch_id']);

            return [
                'user' => $user,
                'branch' => $user->branch,
                'metrics' => $metrics,
            ];
        })->values();

        return response()->json([
            'role_type' => $validated['role_type'],
            'filters' => [
                'period' => $validated['period'] ?? null,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'branch_id' => $scope['branch_id'],
                'responsible_user_id' => $scope['responsible_user_id'],
            ],
            'data' => $rows,
            'summary' => $this->summary($rows, $validated['role_type']),
        ]);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function resolveDateRange(array $validated): array
    {
        if (! empty($validated['date_from']) || ! empty($validated['date_to'])) {
            $from = ! empty($validated['date_from'])
                ? Carbon::parse($validated['date_from'])->startOfDay()
                : Carbon::now()->startOfMonth();
            $to = ! empty($validated['date_to'])
                ? Carbon::parse($validated['date_to'])->endOfDay()
                : Carbon::now()->endOfDay();

            return [$from, $to];
        }

        $now = Carbon::now();
        $period = $validated['period'] ?? 'month';

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    private function resolveScope(User $authUser, array $validated): array
    {
        $roleSlug = $authUser->role?->slug;

        if (in_array($roleSlug, ['admin', 'superadmin'], true)) {
            return [
                'branch_id' => $validated['branch_id'] ?? null,
                'responsible_user_id' => $validated['responsible_user_id'] ?? null,
            ];
        }

        if (in_array($roleSlug, ['rop', 'branch_director'], true)) {
            abort_if(
                ! empty($validated['branch_id']) && (int) $validated['branch_id'] !== (int) $authUser->branch_id,
                403,
                'Forbidden'
            );

            return [
                'branch_id' => $authUser->branch_id,
                'responsible_user_id' => $validated['responsible_user_id'] ?? null,
            ];
        }

        if (in_array($roleSlug, ['operator', 'manager'], true)) {
            abort_if($roleSlug !== $validated['role_type'], 403, 'Forbidden');
            abort_if(
                ! empty($validated['responsible_user_id']) && (int) $validated['responsible_user_id'] !== (int) $authUser->id,
                403,
                'Forbidden'
            );

            return [
                'branch_id' => $authUser->branch_id,
                'responsible_user_id' => $authUser->id,
            ];
        }

        abort(403, 'Forbidden');
    }

    private function operatorMetrics(User $user, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $logs = $this->leadLogs($user->id, $from, $to);

        return [
            'processed_leads_count' => $logs->pluck('auditable_id')->unique()->count(),
            'advanced_status_count' => $logs
                ->where('event', 'status_change')
                ->filter(fn (CrmAuditLog $log) => $this->isForwardLeadStatusMove($log))
                ->count(),
            'overdue_follow_up_count' => Lead::query()
                ->where('responsible_agent_id', $user->id)
                ->when($branchId, fn ($query, $value) => $query->where('branch_id', $value))
                ->whereNotIn('status', Lead::closedStatuses())
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now())
                ->count(),
            'avg_minutes_to_first_processing' => $this->averageLeadFirstProcessingMinutes($user, $from, $to, $branchId),
        ];
    }

    private function managerMetrics(User $user, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $logs = $this->dealLogs($user->id, $from, $to, $branchId);
        $createdDeals = $this->propertyControlDealsQuery($branchId)
            ->where('created_by', $user->id)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('id');

        $takenIds = $logs
            ->filter(fn (CrmAuditLog $log) => $log->event === 'assignment' && (int) ($log->new_values['responsible_agent_id'] ?? 0) === (int) $user->id)
            ->pluck('auditable_id')
            ->merge($createdDeals)
            ->unique();

        return [
            'taken_in_work_count' => $takenIds->count(),
            'checked_cards_count' => $logs->pluck('auditable_id')->unique()->count(),
            'reached_owner_count' => $logs
                ->where('event', 'call')
                ->filter(fn (CrmAuditLog $log) => $this->isSuccessfulContactResult((string) ($log->new_values['result'] ?? '')))
                ->count(),
            'reactivated_count' => $logs
                ->where('event', 'status_change')
                ->filter(fn (CrmAuditLog $log) => ($log->new_values['stage_slug'] ?? null) === 'reactivated')
                ->count(),
            'owner_sold_confirmed_count' => $logs
                ->where('event', 'status_change')
                ->filter(fn (CrmAuditLog $log) => ($log->new_values['stage_slug'] ?? null) === 'owner_sold_confirmed')
                ->count(),
            'overdue_cards_count' => $this->propertyControlDealsQuery($branchId)
                ->where('responsible_agent_id', $user->id)
                ->whereNull('closed_at')
                ->whereNotNull('next_activity_at')
                ->where('next_activity_at', '<', now())
                ->count(),
            'avg_minutes_to_first_contact' => $this->averageDealFirstContactMinutes($user, $from, $to, $branchId),
        ];
    }

    private function summary(Collection $rows, string $roleType): array
    {
        if ($roleType === 'operator') {
            return [
                'processed_leads_count' => $rows->sum('metrics.processed_leads_count'),
                'advanced_status_count' => $rows->sum('metrics.advanced_status_count'),
                'overdue_follow_up_count' => $rows->sum('metrics.overdue_follow_up_count'),
                'avg_minutes_to_first_processing' => $this->averageNullable($rows->pluck('metrics.avg_minutes_to_first_processing')),
            ];
        }

        return [
            'taken_in_work_count' => $rows->sum('metrics.taken_in_work_count'),
            'checked_cards_count' => $rows->sum('metrics.checked_cards_count'),
            'reached_owner_count' => $rows->sum('metrics.reached_owner_count'),
            'reactivated_count' => $rows->sum('metrics.reactivated_count'),
            'owner_sold_confirmed_count' => $rows->sum('metrics.owner_sold_confirmed_count'),
            'overdue_cards_count' => $rows->sum('metrics.overdue_cards_count'),
            'avg_minutes_to_first_contact' => $this->averageNullable($rows->pluck('metrics.avg_minutes_to_first_contact')),
        ];
    }

    private function averageNullable(Collection $values): ?float
    {
        $filtered = $values
            ->filter(fn ($value) => $value !== null)
            ->values();

        if ($filtered->isEmpty()) {
            return null;
        }

        return round((float) $filtered->avg(), 2);
    }

    private function averageLeadFirstProcessingMinutes(User $user, Carbon $from, Carbon $to, ?int $branchId): ?float
    {
        $leads = Lead::query()
            ->where('responsible_agent_id', $user->id)
            ->when($branchId, fn ($query, $value) => $query->where('branch_id', $value))
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'created_at', 'first_contacted_at']);

        if ($leads->isEmpty()) {
            return null;
        }

        $firstEvents = CrmAuditLog::query()
            ->selectRaw('auditable_id, MIN(created_at) as first_activity_at')
            ->where('auditable_type', Lead::class)
            ->whereIn('auditable_id', $leads->pluck('id'))
            ->whereIn('event', self::REPORT_EVENTS)
            ->groupBy('auditable_id')
            ->pluck('first_activity_at', 'auditable_id');

        $durations = $leads->map(function (Lead $lead) use ($firstEvents) {
            $firstActivityAt = $lead->first_contacted_at
                ?: (! empty($firstEvents[$lead->id]) ? Carbon::parse($firstEvents[$lead->id]) : null);

            return $firstActivityAt
                ? round($lead->created_at->diffInSeconds($firstActivityAt) / 60, 2)
                : null;
        })->filter(fn ($value) => $value !== null);

        return $durations->isEmpty() ? null : round((float) $durations->avg(), 2);
    }

    private function averageDealFirstContactMinutes(User $user, Carbon $from, Carbon $to, ?int $branchId): ?float
    {
        $deals = $this->propertyControlDealsQuery($branchId)
            ->where('responsible_agent_id', $user->id)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'created_at']);

        if ($deals->isEmpty()) {
            return null;
        }

        $firstEvents = CrmAuditLog::query()
            ->selectRaw('auditable_id, MIN(created_at) as first_activity_at')
            ->where('auditable_type', Deal::class)
            ->whereIn('auditable_id', $deals->pluck('id'))
            ->whereIn('event', ['comment', 'call', 'status_change', 'follow_up_changed', 'assignment'])
            ->groupBy('auditable_id')
            ->pluck('first_activity_at', 'auditable_id');

        $durations = $deals->map(function (Deal $deal) use ($firstEvents) {
            if (empty($firstEvents[$deal->id])) {
                return null;
            }

            return round($deal->created_at->diffInSeconds(Carbon::parse($firstEvents[$deal->id])) / 60, 2);
        })->filter(fn ($value) => $value !== null);

        return $durations->isEmpty() ? null : round((float) $durations->avg(), 2);
    }

    private function leadLogs(int $userId, Carbon $from, Carbon $to): Collection
    {
        return CrmAuditLog::query()
            ->where('auditable_type', Lead::class)
            ->where('actor_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('event', self::REPORT_EVENTS)
            ->get();
    }

    private function dealLogs(int $userId, Carbon $from, Carbon $to, ?int $branchId): Collection
    {
        $dealIds = $this->propertyControlDealsQuery($branchId)->pluck('id');

        if ($dealIds->isEmpty()) {
            return collect();
        }

        return CrmAuditLog::query()
            ->where('auditable_type', Deal::class)
            ->whereIn('auditable_id', $dealIds)
            ->where('actor_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('event', self::REPORT_EVENTS)
            ->get();
    }

    private function propertyControlDealsQuery(?int $branchId)
    {
        return Deal::query()
            ->whereHas('pipeline', fn ($query) => $query->where('code', DealPipeline::CODE_PROPERTY_CONTROL))
            ->when($branchId, fn ($query, $value) => $query->where('branch_id', $value));
    }

    private function isForwardLeadStatusMove(CrmAuditLog $log): bool
    {
        $rank = [
            Lead::STATUS_NEW => 10,
            Lead::STATUS_ASSIGNED => 20,
            Lead::STATUS_IN_PROGRESS => 30,
            Lead::STATUS_QUALIFIED => 40,
            Lead::STATUS_CONVERTED => 50,
            Lead::STATUS_LOST => 0,
        ];

        $oldStatus = (string) ($log->old_values['status'] ?? '');
        $newStatus = (string) ($log->new_values['status'] ?? '');

        return ($rank[$newStatus] ?? -1) > ($rank[$oldStatus] ?? -1);
    }

    private function isSuccessfulContactResult(string $result): bool
    {
        $normalized = mb_strtolower(trim($result));

        if ($normalized === '') {
            return false;
        }

        foreach (['contacted', 'answered', 'success', 'connected', 'дозвонился', 'связались'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
