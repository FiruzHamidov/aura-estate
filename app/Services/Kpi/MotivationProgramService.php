<?php

namespace App\Services\Kpi;

use App\Models\MotivationRule;
use App\Models\User;
use App\Services\MotivationService;
use App\Support\KpiPlanScopePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MotivationProgramService
{
    public static function rules(array $data, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'scope' => [$required, Rule::in(['agent', 'company'])],
            'metric_key' => $required.'|in:sales_count,result_goal',
            'threshold_value' => $required.'|numeric|min:0.01|max:9999',
            'reward_type' => $required.'|string|max:64',
            'name' => $required.'|string|max:255', 'description' => 'nullable|string|max:3000',
            'period_type' => [$required, Rule::in(['week', 'month', 'year'])],
            'date_from' => $required.'|date_format:Y-m-d', 'date_to' => $required.'|date_format:Y-m-d',
            'is_active' => 'sometimes|boolean', 'ui_meta' => 'sometimes|array',
            'ui_meta.reward_details' => 'nullable|array:title,included,arrangement',
            'ui_meta.reward_details.title' => 'nullable|string|max:255',
            'ui_meta.reward_details.included' => 'nullable|string|max:1500',
            'ui_meta.reward_details.arrangement' => 'nullable|string|max:1000',
            'expected_version' => ($update ? 'required' : 'sometimes').'|integer|min:1',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'condition' => 'nullable|array:source,kind,mode,count_target,amount_target,currency,rental_basis,amount_basis,goal_id',
            'condition.source' => ['nullable', Rule::in(['independent', 'kpi_goal'])],
            'condition.goal_id' => 'nullable|integer|exists:kpi_result_goals,id',
            'condition.kind' => ['required_with:condition', Rule::in(['sale', 'rental'])],
            'condition.mode' => ['required_with:condition', Rule::in(['count', 'amount', 'count_or_amount'])],
            'condition.count_target' => ['nullable', Rule::requiredIf(fn () => data_get($data, 'condition.source') !== 'kpi_goal' && in_array(data_get($data, 'condition.mode'), ['count', 'count_or_amount'])), 'integer', 'min:1', 'max:1000000'],
            'condition.amount_target' => ['nullable', Rule::requiredIf(fn () => data_get($data, 'condition.source') !== 'kpi_goal' && in_array(data_get($data, 'condition.mode'), ['amount', 'count_or_amount'])), 'numeric', 'min:0.01', 'max:999999999999.99', 'decimal:0,2'],
            'condition.currency' => ['required_with:condition', Rule::in(['TJS', 'USD'])],
            'condition.amount_basis' => ['nullable', Rule::requiredIf(fn () => data_get($data, 'condition.source') !== 'kpi_goal' && in_array(data_get($data, 'condition.mode'), ['amount', 'count_or_amount'])), Rule::in(ResultAmount::BASES)],
            'condition.rental_basis' => ['nullable', Rule::requiredIf(fn () => data_get($data, 'condition.source') !== 'kpi_goal'
                && data_get($data, 'condition.kind') === 'rental' && data_get($data, 'condition.amount_basis', 'deal_value') === 'deal_value'
                && data_get($data, 'condition.mode') !== 'count'), Rule::in(['monthly_rent', 'contract_total'])],
            'reward_amount' => 'nullable|required_if:reward_type,cash|numeric|min:0.01|max:999999999999.99|decimal:0,2',
            'reward_currency' => ['nullable', 'required_with:reward_amount', Rule::in(['TJS', 'USD'])],
        ];
    }

    public function canManage(User $actor, MotivationRule $rule): bool
    {
        if (! $actor->hasPermission('motivation.manage')) {
            return false;
        }
        if (! $rule->branch_group_id && ! $rule->branch_id) {
            return ! $actor->usesAssignedGroupScope() && $actor->hasPermission('motivation.achievements.scope.all');
        }
        try {
            app(KpiPlanScopePolicy::class)->ensureCanManageCommonPlan($actor, ['branch_id' => $rule->branch_id, 'branch_group_id' => $rule->branch_group_id]);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            return false;
        }
    }

    public function save(User $actor, array $data, ?int $ruleId = null): MotivationRule
    {
        return DB::transaction(function () use ($actor, $data, $ruleId) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [], [$data['branch_group_id'] ?? null]);
            abort_unless($actor->hasPermission('motivation.manage'), 403);
            $rule = $ruleId ? MotivationRule::whereKey($ruleId)->lockForUpdate()->firstOrFail() : new MotivationRule;
            $candidate = array_replace($rule->toArray(), $data);
            if ($ruleId) {
                abort_unless($this->canManage($actor, $rule), 403);
            }
            if (! empty($candidate['branch_group_id'])) {
                $group = \App\Models\BranchGroup::whereKey($candidate['branch_group_id'])->lockForUpdate()->firstOrFail();
                $candidate['branch_id'] = $group->branch_id;
            }
            if (! empty($candidate['branch_id'])) {
                app(KpiPlanScopePolicy::class)->ensureCanManageCommonPlan($actor, ['branch_id' => $candidate['branch_id'], 'branch_group_id' => $candidate['branch_group_id'] ?? null]);
            } else {
                abort_unless(! $actor->usesAssignedGroupScope() && $actor->hasPermission('motivation.achievements.scope.all'), 403, 'Глобальную программу может менять только управляющий всей компанией.');
                $candidate['branch_id'] = null;
            }
            DB::table('branches')->when($candidate['branch_id'], fn ($q) => $q->where('id', $candidate['branch_id']))->orderBy('id')->lockForUpdate()->get(['id']);
            if ($ruleId) {
                abort_if((int) ($rule->version ?? 1) !== (int) ($data['expected_version'] ?? 0), 409, 'MOTIVATION_PROGRAM_VERSION_CONFLICT');
                // Pausing is allowed; conditions and rewards already promised to staff are immutable.
                $changed = array_diff(array_keys($data), ['is_active', 'expected_version']);
                if ($changed && ($rule->date_from->lte(CarbonImmutable::now(MotivationService::TZ)) || $rule->achievements()->exists())) {
                    throw ValidationException::withMessages(['name' => 'Условия опубликованного периода зафиксированы. Создайте новую программу на будущий период.']);
                }
            }
            app(MotivationService::class)->validatePeriod($candidate['period_type'], substr($candidate['date_from'], 0, 10), substr($candidate['date_to'], 0, 10));
            if (! empty($candidate['condition'])) {
                if (! $ruleId && substr($candidate['date_from'], 0, 10) < CarbonImmutable::now(MotivationService::TZ)->toDateString()) {
                    throw ValidationException::withMessages(['date_from' => 'Начало новой программы не может быть в прошлом.']);
                }
                $condition = $candidate['condition'];
                if (($condition['source'] ?? null) === 'kpi_goal') {
                    $start = CarbonImmutable::parse($candidate['date_from']);
                    abort_unless($candidate['scope'] === 'agent' && $candidate['period_type'] === 'month'
                        && $start->isStartOfMonth() && substr($candidate['date_to'], 0, 10) === $start->endOfMonth()->toDateString(),
                        422, 'Связанная программа должна охватывать полный месяц личной цели.');
                }
                if (! empty($condition['goal_id'])) {
                    $goal = DB::table('kpi_result_goals')->find($condition['goal_id']);
                    abort_unless($goal, 422);
                    $target = $goal->user_id ? User::findOrFail($goal->user_id) : null;
                    $policy = app(KpiPlanScopePolicy::class);
                    $target ? $policy->ensureCanReadUserPlan($actor, $target) : $policy->ensureCanReadCommonPlan($actor, (array) $goal);
                    abort_unless($candidate['scope'] === 'agent'
                        && substr($candidate['date_from'], 0, 10) === $goal->month
                        && substr($candidate['date_to'], 0, 10) === CarbonImmutable::parse($goal->month)->endOfMonth()->toDateString(), 422, 'Связанная цель должна относиться к тому же месяцу.');
                }
            } else {
                app(MotivationService::class)->ensureNoRuleOverlap($candidate, $ruleId);
            }
            unset($data['expected_version']);
            $rule->fill($data);
            $rule->branch_id = $candidate['branch_id'];
            $rule->updated_by = $actor->id;
            if (! $ruleId) {
                $rule->created_by = $actor->id;
            }
            $rule->version = (int) ($rule->version ?? 0) + 1;
            $rule->save();

            return $rule->fresh();
        }, 3);
    }
}
