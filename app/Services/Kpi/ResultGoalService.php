<?php

namespace App\Services\Kpi;

use App\Models\BranchGroup;
use App\Models\User;
use App\Support\KpiPlanScopePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ResultGoalService
{
    public static function rules(): array
    {
        return [
            'month' => 'required|date_format:Y-m', 'kind' => ['required', Rule::in(['sale', 'rental'])],
            'user_id' => 'nullable|required_without:branch_group_id|prohibits:branch_group_id|integer|exists:users,id',
            'branch_group_id' => 'nullable|required_without:user_id|integer|exists:branch_groups,id',
            'expected_version' => 'required|integer|min:0',
            'mode' => ['required', Rule::in(['count', 'amount', 'count_or_amount'])],
            'count_target' => 'nullable|required_if:mode,count,count_or_amount|integer|min:1|max:1000000',
            'amount_target' => 'nullable|required_if:mode,amount,count_or_amount|numeric|min:0.01|max:999999999999.99|decimal:0,2',
            'currency' => ['required', Rule::in(['TJS', 'USD'])],
            'rental_basis' => ['nullable', 'required_if:kind,rental', Rule::in(['monthly_rent', 'contract_total'])],
            'comment' => 'nullable|string|max:500',
        ];
    }

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
            $inherited = ! empty($scope['user_id']) ? $this->resolve($scope['user_id'], $scope['branch_group_id'], $data['month'], $data['kind'], true) : null;
            if (($last || $inherited) && ! $month->isFuture()) {
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

    public function resolve(int $userId, ?int $groupId, string $month, string $kind, bool $lock = false): ?array
    {
        $query = DB::table('kpi_result_goals')->where('month', $month.'-01')->where('kind', $kind)
            ->whereIn('scope_key', ['user:'.$userId, 'group:'.$groupId])->orderByDesc('version');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        $goal = $rows->firstWhere('scope_key', 'user:'.$userId) ?? $rows->firstWhere('scope_key', 'group:'.$groupId);

        return $goal ? (array) $goal : null;
    }
}
