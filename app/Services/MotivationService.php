<?php

namespace App\Services;

use App\Models\MotivationAchievement;
use App\Models\MotivationRule;
use App\Models\MotivationRewardIssue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MotivationService
{
    public const TZ = 'Asia/Dushanbe';

    public function validatePeriod(string $periodType, string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom, self::TZ)->startOfDay();
        $to = Carbon::parse($dateTo, self::TZ)->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['date_to' => ['date_to must be after or equal to date_from.']]);
        }

        if ($periodType === 'week' && $from->copy()->addDays(6)->toDateString() !== $to->toDateString()) {
            throw ValidationException::withMessages(['date_to' => ['For week period date range must contain exactly 7 days.']]);
        }

        if ($periodType === 'month' && $from->format('Y-m') !== $to->format('Y-m')) {
            throw ValidationException::withMessages(['date_to' => ['For month period dates must be inside the same calendar month.']]);
        }

        if ($periodType === 'year' && $from->format('Y') !== $to->format('Y')) {
            throw ValidationException::withMessages(['date_to' => ['For year period dates must be inside the same calendar year.']]);
        }

        return [$from, $to];
    }

    public function ensureNoRuleOverlap(array $data, ?int $ignoreRuleId = null): void
    {
        $query = MotivationRule::query()
            ->where('scope', $data['scope'])
            ->where('metric_key', $data['metric_key'])
            ->where('reward_type', $data['reward_type'])
            ->where('period_type', $data['period_type'])
            ->where(function ($q) use ($data) {
                $q->whereBetween('date_from', [$data['date_from'], $data['date_to']])
                    ->orWhereBetween('date_to', [$data['date_from'], $data['date_to']])
                    ->orWhere(function ($sq) use ($data) {
                        $sq->where('date_from', '<=', $data['date_from'])
                            ->where('date_to', '>=', $data['date_to']);
                    });
            });

        if ($ignoreRuleId !== null) {
            $query->where('id', '!=', $ignoreRuleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'date_from' => ['Overlapping rule for the same scope/reward/period is not allowed.'],
            ]);
        }
    }

    public function recalculate(array $filters = [], ?User $actor = null): array
    {
        return app(\App\Services\Kpi\MotivationRecalculation::class)->run($filters, $actor);
    }

    public function upsertRewardIssue(int $achievementId, array $data): MotivationRewardIssue
    {
        return DB::transaction(function () use ($achievementId, $data) {
            $achievement = MotivationAchievement::whereKey($achievementId)->lockForUpdate()->firstOrFail();
            abort_unless($achievement->status === 'approved', 409, 'Награда ещё не согласована или уже выдана.');
            abort_if(($data['status'] ?? null) === 'issued', 422, 'Используйте подтверждение выдачи с датой и подтверждающим документом.');
            return MotivationRewardIssue::updateOrCreate(['achievement_id' => $achievementId], $data);
        }, 3);
    }

    public function applyUiMetaFallback(MotivationRule $rule): array
    {
        $uiMeta = is_array($rule->ui_meta) ? $rule->ui_meta : [];

        $title = (string) ($uiMeta['title'] ?? $rule->name);
        $shortLabel = (string) ($uiMeta['short_label'] ?? $title);

        $messages = is_array($uiMeta['messages'] ?? null) ? $uiMeta['messages'] : [];
        $defaultMessages = [
            'remaining_3' => "Еще 3 {$this->pluralizeRu(3, $this->unitLabels($uiMeta))} до {$shortLabel}. Отличный темп!",
            'remaining_2' => "Еще 2 {$this->pluralizeRu(2, $this->unitLabels($uiMeta))} до {$shortLabel}. Вы близко!",
            'remaining_1' => "Еще 1 {$this->pluralizeRu(1, $this->unitLabels($uiMeta))} до {$shortLabel}!",
            'remaining_0' => "Поздравляем! Цель '{$shortLabel}' выполнена.",
            'remaining_default' => "До {$shortLabel} осталось {remaining} {unit}.",
        ];

        $statusLabels = is_array($uiMeta['status_labels'] ?? null) ? $uiMeta['status_labels'] : [];
        $defaultStatusLabels = [
            'in_progress' => 'В пути',
            'achieved' => 'Достигнуто',
            'issued' => 'Выдано',
        ];

        return array_merge($uiMeta, [
            'title' => $title,
            'short_label' => $shortLabel,
            'unit_label_one' => (string) ($uiMeta['unit_label_one'] ?? 'сделка'),
            'unit_label_few' => (string) ($uiMeta['unit_label_few'] ?? 'сделки'),
            'unit_label_many' => (string) ($uiMeta['unit_label_many'] ?? 'сделок'),
            'messages' => array_merge($defaultMessages, $messages),
            'status_labels' => array_merge($defaultStatusLabels, $statusLabels),
        ]);
    }

    public function buildMessagePreview(array $uiMeta, int $remaining): string
    {
        $key = 'remaining_'.$remaining;
        $messages = is_array($uiMeta['messages'] ?? null) ? $uiMeta['messages'] : [];
        $template = (string) ($messages[$key] ?? $messages['remaining_default'] ?? 'Осталось {remaining} {unit}.');
        $unit = $this->pluralizeRu($remaining, $this->unitLabels($uiMeta));

        return strtr($template, [
            '{remaining}' => (string) $remaining,
            '{unit}' => $unit,
        ]);
    }

    public function buildOverview(User $actor, string $periodType, string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->validatePeriod($periodType, $dateFrom, $dateTo);

        $rules = MotivationRule::query()
            ->where('is_active', true)
            ->where('period_type', $periodType)
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->orderBy('threshold_value')
            ->orderBy('id')
            ->get();

        $agentRules = $rules->where('scope', 'agent')->values();
        $companyRules = $rules->where('scope', 'company')->values();

        $agentAchievements = $this->loadAchievements($agentRules, $actor->id, false, $periodType);
        $companyAchievements = $this->loadAchievements($companyRules, null, true, $periodType);

        $cards = $agentRules->filter(fn ($rule) => (! $rule->branch_id || (int) $rule->branch_id === (int) $actor->branch_id)
            && (! $rule->branch_group_id || (int) $rule->branch_group_id === (int) $actor->branch_group_id))
            ->map(fn ($rule) => $this->buildCard($rule, $actor, $agentAchievements->get($rule->id)))->values();

        $companyCard = $companyRules
            ->map(fn (MotivationRule $rule) => $this->buildCard($rule, null, $companyAchievements->get($rule->id)))
            ->sortByDesc('progress.progress_percent')
            ->first();

        $nextRewardCard = collect($cards)
            ->where('status', 'in_progress')
            ->sortByDesc('progress.progress_percent')
            ->first();

        return [
            'period' => [
                'period_type' => $periodType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'cards' => $cards->all(),
            'next_reward' => $nextRewardCard ? [
                'rule_id' => $nextRewardCard['rule_id'],
                'message' => $nextRewardCard['message_preview'],
            ] : null,
            'company_goal' => $companyCard,
        ];
    }

    private function buildCard(MotivationRule $rule, ?User $user, ?MotivationAchievement $achievement): array
    {
        $facts = app(\App\Services\Kpi\ResultFactService::class)->forPeriod($rule->date_from->toDateString(), $rule->date_to->toDateString());
        $progress = app(\App\Services\Kpi\MotivationEligibility::class)->evaluate($rule, $facts, $user, $achievement?->meta['eligibility']['condition'] ?? null);
        $condition = $progress['condition'];
        $amountMode = ($condition['mode'] ?? 'count') === 'amount';
        $fact = $amountMode ? $progress['amount'] : $progress['count'];
        $threshold = (float) ($amountMode ? ($condition['amount_target'] ?? 0) : ($condition['count_target'] ?? $rule->threshold_value));
        $remaining = max(0, (int) ceil($threshold - $fact));
        $status = $progress['eligible'] ? 'achieved' : 'in_progress';
        if ($achievement?->status === 'issued') $status = 'issued';
        $uiMeta = $this->applyUiMetaFallback($rule);
        $message = $progress['eligible'] ? 'Условие выполнено. Награда проходит проверку.' : $this->buildMessagePreview($uiMeta, $remaining);
        if ($amountMode && ! $progress['eligible']) $message = 'До цели осталось '.number_format(max(0, $threshold - $fact), 2, '.', ' ').' '.($condition['currency'] ?? 'TJS').'.';
        if (($condition['mode'] ?? '') === 'count_or_amount' && ! $progress['eligible']) $message = 'До цели: ещё '.$progress['remaining_count'].' '.$this->pluralizeRu((int) $progress['remaining_count'], $this->unitLabels($uiMeta)).' или '.number_format($progress['remaining_amount'], 2, '.', ' ').' '.($condition['currency'] ?? 'TJS').'.';
        if ($progress['status'] === 'no_plan') $message = 'Руководитель ещё не назначил цель KPI на этот период.';
        if ($progress['progress_is_lower_bound']) $message = 'Не все суммы подтверждены. Результат может увеличиться.';
        if ($status === 'issued') $message = 'Награда выдана.';
        return [
            'rule_id' => (int) $rule->id, 'scope' => $rule->scope, 'reward_type' => $rule->reward_type, 'name' => $rule->name,
            'description' => $rule->description, 'fact' => $fact, 'threshold' => $threshold, 'remaining' => $remaining,
            'status' => $status, 'achievement_status' => $achievement?->meta['workflow'] ?? $achievement?->status,
            'achievement_id' => $achievement?->id, 'ui_meta' => $uiMeta, 'message_preview' => $message,
            'progress' => $progress, 'condition' => $condition, 'reward_amount' => $rule->reward_amount,
            'reward_currency' => $rule->reward_currency, 'date_from' => $rule->date_from->toDateString(),
            'date_to' => $rule->date_to->toDateString(), 'review_required' => $achievement?->meta['review_required'] ?? false,
        ];
    }

    private function loadAchievements(
        EloquentCollection $rules,
        ?int $userId,
        bool $companyScope,
        string $periodType
    ): Collection {
        if ($rules->isEmpty()) {
            return collect();
        }

        return MotivationAchievement::query()
            ->whereIn('rule_id', $rules->pluck('id')->all())
            ->where('period_type', $periodType)
            // An award belongs to its program's period, even when the report
            // shows a wider or narrower overlapping date range.
            ->whereHas('rule', fn ($query) => $query
                ->whereColumn('motivation_rules.date_from', 'motivation_achievements.date_from')
                ->whereColumn('motivation_rules.date_to', 'motivation_achievements.date_to'))
            ->when($companyScope, fn ($q) => $q->where('company_scope', true))
            ->when(! $companyScope, fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->keyBy('rule_id');
    }

    private function pluralizeRu(int $value, array $labels): string
    {
        $n = abs($value) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $labels['many'];
        }
        if ($n1 > 1 && $n1 < 5) {
            return $labels['few'];
        }
        if ($n1 === 1) {
            return $labels['one'];
        }

        return $labels['many'];
    }

    private function unitLabels(array $uiMeta): array
    {
        return [
            'one' => (string) ($uiMeta['unit_label_one'] ?? 'сделка'),
            'few' => (string) ($uiMeta['unit_label_few'] ?? 'сделки'),
            'many' => (string) ($uiMeta['unit_label_many'] ?? 'сделок'),
        ];
    }
}
