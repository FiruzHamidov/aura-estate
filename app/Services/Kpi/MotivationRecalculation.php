<?php

namespace App\Services\Kpi;

use App\Models\MotivationAchievement;
use App\Models\MotivationRule;
use App\Models\User;
use App\Support\KpiResultAccess;
use Illuminate\Support\Facades\DB;

final class MotivationRecalculation
{
    public function run(array $filters, ?User $actor): array
    {
        return DB::transaction(function () use ($filters, $actor) {
            if ($actor) {
                [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, []);
                abort_unless($actor->hasPermission('motivation.manage'), 403);
            }
            // Program locks serialize duplicate calculation/creation, including company NULL keys.
            $rules = MotivationRule::where('is_active', true)
                ->when(isset($filters['rule_id']), fn ($q) => $q->whereKey($filters['rule_id']))->orderBy('id')->lockForUpdate()->get();
            $changes = [];
            foreach ($rules as $rule) {
                if ($actor && $rule->scope === 'company' && ($actor->usesAssignedGroupScope() || ! $actor->hasPermission('motivation.achievements.scope.all'))) {
                    continue;
                }
                $facts = app(ResultFactService::class)->forPeriod($rule->date_from->toDateString(), $rule->date_to->toDateString());
                $existing = MotivationAchievement::where('rule_id', $rule->id)->lockForUpdate()->get()->keyBy(fn ($a) => $a->user_id ?? 0);
                $users = $rule->scope === 'company' ? collect([null]) : User::whereIn('id', $facts->pluck('user_id')->merge($existing->keys())->unique())
                    ->when(isset($filters['user_id']), fn ($q) => $q->whereKey($filters['user_id']))->get();
                foreach ($users as $user) {
                    $achievement = $existing->get($user?->id ?? 0);
                    $scope = $achievement?->meta['scope'] ?? [
                        'user_id' => $user?->id, 'branch_id' => $rule->branch_id ?? $user?->branch_id, 'branch_group_id' => $rule->branch_group_id ?? $user?->branch_group_id,
                    ];
                    if ($user && $actor && ! app(KpiResultAccess::class)->visible($actor, $scope, 'motivation.achievements')) {
                        continue;
                    }
                    $result = app(MotivationEligibility::class)->evaluate($rule, $facts, $user, $achievement?->meta['eligibility']['condition'] ?? null);
                    $action = null;
                    if ($result['eligible'] && ! $achievement) {
                        $action = 'create';
                    } elseif (! $result['eligible'] && $achievement && $achievement->status !== 'cancelled'
                        && ! ($achievement->meta['review_required'] ?? false) && ! ($achievement->meta['hold'] ?? false)
                        && ($achievement->status !== 'issued' || ($achievement->meta['reviewed_result_hash'] ?? null) !== hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)))) {
                        $action = match ($achievement->status) {
                            'issued' => 'issued_review', 'approved' => 'approval_hold', default => 'cancel'
                        };
                    } elseif ($result['eligible'] && ($achievement?->status === 'cancelled' || ($achievement?->meta['hold'] ?? false))) {
                        $action = 'restore';
                    }
                    if ($action) {
                        $changes[] = [
                            'rule_id' => $rule->id, 'user_id' => $user?->id, 'name' => $user?->name ?? 'Компания',
                            'achievement_id' => $achievement?->id, 'action' => $action,
                            'scope' => $scope, 'progress' => $result, 'rule_snapshot' => $rule->toArray(),
                            'previous_version' => (int) ($achievement?->meta['version'] ?? 0),
                        ];
                    }
                }
            }
            $token = hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR));
            if ($filters['preview'] ?? false) {
                return [
                    'preview_token' => $token, 'changes' => $changes, 'rules_processed' => $rules->count(),
                ];
            }
            if (isset($filters['preview_token'])) {
                abort_unless(hash_equals($token, $filters['preview_token']), 409, 'Данные изменились. Обновите предварительный расчёт.');
            }
            if ($rules->contains(fn ($r) => $r->condition !== null)) {
                abort_unless(! empty($filters['preview_token']), 422, 'Сначала выполните предварительный расчёт.');
            }
            $created = 0;
            foreach ($changes as $change) {
                $rule = $rules->firstWhere('id', $change['rule_id']);
                $achievement = $change['achievement_id'] ? MotivationAchievement::findOrFail($change['achievement_id']) : new MotivationAchievement([
                    'rule_id' => $rule->id, 'user_id' => $change['user_id'], 'company_scope' => $rule->scope === 'company' ? true : null,
                    'period_type' => $rule->period_type, 'date_from' => $rule->date_from, 'date_to' => $rule->date_to,
                    'won_at' => now(), 'status' => 'new', 'snapshot_value' => $change['progress']['count'],
                ]);
                $meta = $achievement->meta ?? [];
                $meta['version'] = (int) ($meta['version'] ?? 0) + 1;
                $meta['scope'] ??= $change['scope'];
                $meta['rule_snapshot'] ??= $change['rule_snapshot'];
                $meta['eligibility'] = $change['progress'];
                $meta['recalculate_reason'] = $filters['reason'] ?? 'manual';
                $meta['recalculated_by'] = $actor?->id;
                switch ($change['action']) {
                    case 'create': $created++;
                        $meta['workflow'] = 'new';
                        break;
                    case 'restore':
                        $achievement->status = 'new';
                        $meta['workflow'] = 'new';
                        $meta['hold'] = false;
                        $achievement->cancelled_at = null;
                        $achievement->cancelled_by = null;
                        $achievement->cancel_reason = null;
                        break;
                    case 'cancel':
                        $achievement->status = 'cancelled';
                        $meta['workflow'] = 'cancelled';
                        $achievement->cancelled_at = now();
                        $achievement->cancelled_by = $actor?->id;
                        $achievement->cancel_reason = $filters['reason'] ?? 'Условие больше не выполнено.';
                        break;
                    case 'approval_hold':
                        $achievement->status = 'new';
                        $meta['workflow'] = 'returned';
                        $meta['hold'] = true;
                        $achievement->approved_by = null;
                        $achievement->approved_at = null;
                        $meta['return_reason'] = 'Зачёт изменился после согласования. Требуется повторная проверка.';
                        break;
                    case 'issued_review': $meta['review_required'] = true;
                        break;
                }
                $achievement->meta = $meta;
                $achievement->save();
                app(MotivationWorkflow::class)->audit($achievement, $actor, $change['action'], $filters['reason'] ?? null);
            }

            return ['rules_processed' => $rules->count(), 'achievements_created' => $created, 'changes' => $changes];
        }, 3);
    }
}
