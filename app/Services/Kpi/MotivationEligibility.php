<?php

namespace App\Services\Kpi;

use App\Models\MotivationRule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MotivationEligibility
{
    public function evaluate(MotivationRule $rule, Collection $facts, ?User $user = null, ?array $frozenCondition = null): array
    {
        $condition = $frozenCondition ?? ($rule->condition ?: [
            'kind' => 'sale', 'mode' => 'count', 'count_target' => (float) $rule->threshold_value, 'currency' => 'TJS',
        ]);
        if (($condition['source'] ?? null) === 'kpi_goal') {
            $goal = $user ? app(ResultGoalService::class)->resolve($user->id, $user->branch_group_id, $rule->date_from->format('Y-m'), $condition['kind']) : null;
            if (! $goal) {
                return ResultProgress::calculate(null, 0, 0, 0) + ['eligible' => false, 'condition' => $condition, 'source_events' => []];
            }
            $condition = $goal;
        }
        // A linked goal references an immutable published revision, never "whatever is current".
        if (! empty($condition['goal_id'])) {
            $goal = DB::table('kpi_result_goals')->find($condition['goal_id']);
            if (! $goal || ! $user || ($goal->user_id && (int) $goal->user_id !== $user->id)
                || (! $goal->user_id && (int) $goal->branch_group_id !== (int) $user->branch_group_id)) {
                return ResultProgress::calculate(null, 0, 0, 0) + ['eligible' => false, 'condition' => $condition];
            }
            $condition = (array) $goal;
        }
        $items = $facts->where('kind', $condition['kind'] ?? 'sale');
        if ($rule->scope === 'agent') {
            $items = $items->where('user_id', $user?->id);
        }
        if ($rule->branch_id) {
            $items = $items->where('branch_id', $rule->branch_id);
        }
        if ($rule->branch_group_id) {
            $items = $items->where('branch_group_id', $rule->branch_group_id);
        }
        $amounts = $items->map(fn ($fact) => app(ResultFactService::class)->amount($fact, $condition['currency'] ?? 'TJS', $condition['rental_basis'] ?? null, ResultAmount::basis($condition)));
        $progress = ResultProgress::calculate($condition, $items->count(), $amounts->filter(fn ($n) => $n !== null)->sum(), $amounts->filter(fn ($n) => $n === null)->count());

        return $progress + [
            'eligible' => $progress['achieved'], 'condition' => $condition,
            'source_events' => $items->pluck('source_event')->sort()->values()->all(),
        ];
    }
}
