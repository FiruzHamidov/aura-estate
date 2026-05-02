<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\KpiAdjustmentLog;
use App\Models\KpiPeriodLock;
use App\Models\User;
use App\Support\RbacBranchScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class KpiPeriodLockController extends Controller
{
    public function __construct(private readonly RbacBranchScope $branchScope)
    {
    }

    public function lock(Request $request)
    {
        $user = $this->authUser();
        $this->ensureCanManage($user);

        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['day', 'week', 'month'])],
            'period_key' => 'required|string|max:32',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]);

        $this->validateScopedValues($validated, $user);

        $lock = KpiPeriodLock::query()->firstOrCreate([
            'period_type' => $validated['period_type'],
            'period_key' => $validated['period_key'],
            'branch_id' => $validated['branch_id'] ?? null,
            'branch_group_id' => $validated['branch_group_id'] ?? null,
        ], [
            'locked_by' => $user->id,
            'locked_at' => now(),
        ]);

        return response()->json($lock->fresh(), 201);
    }

    public function adjustments(Request $request)
    {
        $user = $this->authUser();
        $this->ensureCanManage($user);

        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['day', 'week', 'month'])],
            'period_key' => 'required|string|max:32',
            'entity_id' => 'required|integer|exists:users,id',
            'field_name' => ['required', Rule::in(['ad_count', 'meetings_count', 'calls_count', 'shows_count', 'new_clients_count', 'deposits_count', 'deals_count'])],
            'new_value' => 'required|numeric|min:0',
            'distribution_mode' => ['nullable', Rule::in(['set_first_day', 'distribute_evenly'])],
            'reason' => 'required|string|min:3',
        ]);

        $isLocked = KpiPeriodLock::query()
            ->where('period_type', $validated['period_type'])
            ->where('period_key', $validated['period_key'])
            ->exists();

        abort_unless($isLocked, 422, 'Period is not locked.');

        [$dateFrom, $dateTo] = $this->resolveDateRange($validated['period_type'], $validated['period_key']);

        $reports = DailyReport::query()
            ->where('user_id', $validated['entity_id'])
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderBy('report_date')
            ->get();

        abort_unless($reports->isNotEmpty(), 404, 'Daily report row not found for selected period and user.');

        $field = $validated['field_name'];
        $newValue = (float) $validated['new_value'];
        $distributionMode = $validated['distribution_mode'] ?? ($validated['period_type'] === 'day' ? 'set_first_day' : 'distribute_evenly');

        $updatedRows = 0;
        $oldTotal = (float) $reports->sum($field);

        if ($distributionMode === 'set_first_day') {
            /** @var DailyReport $first */
            $first = $reports->first();
            $first->update([$field => $newValue]);
            $updatedRows = 1;
        } else {
            $count = $reports->count();
            $base = $count > 0 ? floor(($newValue / $count) * 10000) / 10000 : 0.0;
            $allocated = 0.0;

            foreach ($reports as $index => $report) {
                $value = $index === ($count - 1)
                    ? round($newValue - $allocated, 4)
                    : $base;
                $allocated += $value;
                $report->update([$field => $value]);
                $updatedRows++;
            }
        }

        $newTotal = (float) DailyReport::query()
            ->where('user_id', $validated['entity_id'])
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->sum($field);

        $log = KpiAdjustmentLog::query()->create([
            'period_type' => $validated['period_type'],
            'period_key' => $validated['period_key'],
            'entity_id' => $validated['entity_id'],
            'field_name' => $field,
            'old_value' => $oldTotal,
            'new_value' => $newTotal,
            'reason' => $validated['reason'].'; mode='.$distributionMode.'; rows='.$updatedRows,
            'changed_by' => $user->id,
            'changed_at' => now(),
        ]);

        return response()->json($log, 201);
    }

    public function adjustmentHistory(Request $request)
    {
        $user = $this->authUser();
        $this->ensureCanManage($user);

        $validated = $request->validate([
            'period_type' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'period_key' => 'nullable|string|max:32',
            'entity_id' => 'nullable|integer|exists:users,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = KpiAdjustmentLog::query()->orderByDesc('id');

        if (! empty($validated['period_type'])) {
            $query->where('period_type', $validated['period_type']);
        }

        if (! empty($validated['period_key'])) {
            $query->where('period_key', $validated['period_key']);
        }

        if (! empty($validated['entity_id'])) {
            $query->where('entity_id', (int) $validated['entity_id']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString());
    }

    private function resolveDateRange(string $periodType, string $periodKey): array
    {
        return match ($periodType) {
            'day' => [$periodKey, $periodKey],
            'week' => [$periodKey, Carbon::parse($periodKey)->addDays(6)->toDateString()],
            'month' => [Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth()->toDateString(), Carbon::createFromFormat('Y-m', $periodKey)->endOfMonth()->toDateString()],
        };
    }

    private function validateScopedValues(array $validated, User $user): void
    {
        if (! $this->branchScope->isBranchScopedManager($user)) {
            return;
        }

        if (! empty($validated['branch_id'])) {
            $this->branchScope->ensureSameBranchOrDeny((int) $validated['branch_id'], $user);
        }

        if (! empty($validated['branch_group_id'])) {
            $this->branchScope->ensureBranchGroupInUserBranchOrDeny((int) $validated['branch_group_id'], $user);
        }
    }

    private function ensureCanManage(User $user): void
    {
        abort_unless(in_array($user->role?->slug, ['admin', 'superadmin', 'rop', 'branch_director'], true), 403, 'Forbidden');
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
