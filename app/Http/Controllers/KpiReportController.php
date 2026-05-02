<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\KpiReportService;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class KpiReportController extends Controller
{
    public function __construct(
        private readonly KpiReportService $kpiReports,
        private readonly RbacBranchScope $branchScope
    ) {
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'period_type' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $periodType = $validated['period_type'] ?? 'week';
        [$dateFrom, $dateTo] = $this->resolveRange($validated, $periodType);

        $this->validateScopeFilters($validated, $authUser);

        $payload = $this->kpiReports->build($authUser, [
            'period_type' => $periodType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $validated['branch_id'] ?? null,
            'branch_group_id' => $validated['branch_group_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return response()->json($payload);
    }

    private function resolveRange(array $validated, string $periodType): array
    {
        if (! empty($validated['date_from']) || ! empty($validated['date_to'])) {
            $from = ! empty($validated['date_from'])
                ? Carbon::parse($validated['date_from'])->toDateString()
                : Carbon::now()->startOfMonth()->toDateString();

            $to = ! empty($validated['date_to'])
                ? Carbon::parse($validated['date_to'])->toDateString()
                : Carbon::now()->toDateString();

            return [$from, $to];
        }

        $now = Carbon::now();

        return match ($periodType) {
            'day' => [$now->toDateString(), $now->toDateString()],
            'month' => [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            default => [$now->startOfWeek(Carbon::MONDAY)->toDateString(), $now->endOfWeek(Carbon::SUNDAY)->toDateString()],
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
}
