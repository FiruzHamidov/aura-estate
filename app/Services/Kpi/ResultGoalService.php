<?php

namespace App\Services\Kpi;

use App\Models\BranchGroup;
use App\Models\User;
use App\Support\KpiPlanScopePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResultGoalService
{
    public function publish(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [$data['user_id'] ?? null], [$data['branch_group_id'] ?? null]);
            $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'Asia/Dushanbe');
            if ($month->lt(CarbonImmutable::now('Asia/Dushanbe')->startOfMonth())) {
                throw ValidationException::withMessages(['month' => 'План нельзя публиковать задним числом.']);
            }
            $policy = app(KpiPlanScopePolicy::class);
            if (! empty($data['user_id'])) {
                $target = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
                $policy->ensureCanManageUserPlan($actor, $target);
                $scope = ['user_id' => $target->id, 'branch_id' => $target->branch_id, 'branch_group_id' => $target->branch_group_id];
                $key = 'user:'.$target->id;
            } else {
                $group = BranchGroup::whereKey($data['branch_group_id'])->lockForUpdate()->firstOrFail();
                $scope = ['user_id' => null, 'branch_id' => $group->branch_id, 'branch_group_id' => $group->id];
                $policy->ensureCanManageCommonPlan($actor, $scope);
                $key = 'group:'.$group->id;
            }
            $query = DB::table('kpi_result_goals')->where('scope_key', $key)->where('month', $month->toDateString())->where('kind', $data['kind']);
            $last = (clone $query)->latest('version')->first();
            abort_if((int) ($last?->version ?? 0) !== (int) $data['expected_version'], 409, 'KPI_GOAL_VERSION_CONFLICT');
            if ($last && ! $month->isFuture()) {
                throw ValidationException::withMessages(['month' => 'Опубликованный план текущего месяца зафиксирован. Создайте версию на следующий месяц.']);
            }
            $mode = $data['mode'];
            $row = $scope + [
                'scope_key' => $key, 'month' => $month->toDateString(), 'kind' => $data['kind'],
                'version' => (int) ($last?->version ?? 0) + 1, 'mode' => $mode,
                'count_target' => $mode === 'amount' ? null : $data['count_target'],
                'amount_target' => $mode === 'count' ? null : $data['amount_target'],
                'currency' => $data['currency'] ?? 'TJS',
                'rental_basis' => $data['kind'] === 'rental' ? $data['rental_basis'] : null,
                'comment' => $data['comment'] ?? null, 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $id = DB::table('kpi_result_goals')->insertGetId($row);

            return (array) DB::table('kpi_result_goals')->find($id);
        }, 3);
    }

    public function resolve(int $userId, ?int $groupId, string $month, string $kind): ?array
    {
        $rows = DB::table('kpi_result_goals')->where('month', $month.'-01')->where('kind', $kind)
            ->whereIn('scope_key', ['user:'.$userId, 'group:'.$groupId])->orderByDesc('version')->get();
        $goal = $rows->firstWhere('scope_key', 'user:'.$userId) ?? $rows->firstWhere('scope_key', 'group:'.$groupId);

        return $goal ? (array) $goal : null;
    }
}
