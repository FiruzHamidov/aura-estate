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
        $rules = MotivationRule::query()
            ->where('is_active', true)
            ->when(isset($filters['rule_id']), fn ($q) => $q->where('id', (int) $filters['rule_id']))
            ->orderBy('id')
            ->get();

        $created = 0;

        foreach ($rules as $rule) {
            $from = Carbon::parse($rule->date_from, self::TZ)->startOfDay();
            $to = Carbon::parse($rule->date_to, self::TZ)->endOfDay();

            if ($rule->scope === 'agent') {
                $agentCredits = $this->salesCreditsByAgent($from, $to);
                if (isset($filters['user_id'])) {
                    $agentCredits = $agentCredits->only([(int) $filters['user_id']]);
                }

                foreach ($agentCredits as $userId => $credit) {
                    if ((float) $credit < (float) $rule->threshold_value) {
                        continue;
                    }

                    $achievement = MotivationAchievement::query()->firstOrCreate(
                        [
                            'rule_id' => $rule->id,
                            'user_id' => (int) $userId,
                            'period_type' => $rule->period_type,
                            'date_from' => $rule->date_from,
                            'date_to' => $rule->date_to,
                        ],
                        [
                            'company_scope' => null,
                            'won_at' => Carbon::now(self::TZ),
                            'snapshot_value' => round((float) $credit, 4),
                            'status' => 'new',
                            'meta' => [
                                'recalculate_reason' => $filters['reason'] ?? 'manual',
                                'recalculated_by' => $actor?->id,
                            ],
                        ]
                    );

                    if ($achievement->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }

            if ($rule->scope === 'company') {
                $total = round((float) $this->salesCreditsByAgent($from, $to)->sum(), 4);
                if ($total < (float) $rule->threshold_value) {
                    continue;
                }

                $achievement = MotivationAchievement::query()->firstOrCreate(
                    [
                        'rule_id' => $rule->id,
                        'company_scope' => true,
                        'period_type' => $rule->period_type,
                        'date_from' => $rule->date_from,
                        'date_to' => $rule->date_to,
                    ],
                    [
                        'user_id' => null,
                        'won_at' => Carbon::now(self::TZ),
                        'snapshot_value' => $total,
                        'status' => 'new',
                        'meta' => [
                            'recalculate_reason' => $filters['reason'] ?? 'manual',
                            'recalculated_by' => $actor?->id,
                        ],
                    ]
                );

                if ($achievement->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return [
            'rules_processed' => $rules->count(),
            'achievements_created' => $created,
        ];
    }

    public function upsertRewardIssue(int $achievementId, array $data): MotivationRewardIssue
    {
        $payload = [];
        if (array_key_exists('assignee_id', $data)) {
            $payload['assignee_id'] = $data['assignee_id'];
        }
        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }
        if (array_key_exists('comment', $data)) {
            $payload['comment'] = $data['comment'];
        }
        if (($data['status'] ?? null) === 'issued') {
            $payload['issued_at'] = Carbon::now(self::TZ);
        }

        return MotivationRewardIssue::query()->updateOrCreate(
            ['achievement_id' => $achievementId],
            $payload
        );
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
        $credits = $this->salesCreditsByAgent($from, $to);
        $actorFact = (float) ($credits[(int) $actor->id] ?? 0);
        $companyFact = (float) $credits->sum();

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

        $agentAchievements = $this->loadAchievements($agentRules, $actor->id, false, $periodType, $dateFrom, $dateTo);
        $companyAchievements = $this->loadAchievements($companyRules, null, true, $periodType, $dateFrom, $dateTo);

        $cards = $agentRules->map(function (MotivationRule $rule) use ($actorFact, $agentAchievements) {
            return $this->buildCard($rule, $actorFact, $agentAchievements->get($rule->id));
        })->values();

        $companyCard = $companyRules
            ->map(fn (MotivationRule $rule) => $this->buildCard($rule, $companyFact, $companyAchievements->get($rule->id)))
            ->sortBy('remaining')
            ->first();

        $nextRewardCard = collect($cards)
            ->where('status', 'in_progress')
            ->sortBy('remaining')
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

    private function salesCreditsByAgent(Carbon $fromTz, Carbon $toTz): Collection
    {
        $startUtc = $fromTz->copy()->setTimezone('UTC');
        $endUtc = $toTz->copy()->setTimezone('UTC');

        $soldProperties = DB::table('properties')
            ->select(['id', 'agent_id', 'sale_user_id'])
            ->whereIn('moderation_status', ['sold', 'rented', 'sold_by_owner'])
            ->whereBetween('sold_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->get();

        if ($soldProperties->isEmpty()) {
            return collect();
        }

        $propertyIds = $soldProperties->pluck('id')->all();
        $saleAgentsRows = DB::table('property_agent_sales')
            ->select(['property_id', 'agent_id'])
            ->whereIn('property_id', $propertyIds)
            ->get()
            ->groupBy('property_id');

        $credits = collect();

        foreach ($soldProperties as $property) {
            $rawParticipants = collect($saleAgentsRows->get($property->id, []))->pluck('agent_id')->values();
            $participants = $rawParticipants->filter(fn ($id) => ! is_null($id))->unique()->values();

            if ($participants->isNotEmpty()) {
                $share = 1 / max(1, $participants->count());
                foreach ($participants as $participantId) {
                    $credits[(int) $participantId] = round((float) ($credits[(int) $participantId] ?? 0) + $share, 4);
                }

                continue;
            }

            $saleUserId = (int) ($property->sale_user_id ?? 0);
            if ($saleUserId > 0) {
                $credits[$saleUserId] = round((float) ($credits[$saleUserId] ?? 0) + 1.0, 4);
                continue;
            }

            if ((int) ($property->agent_id ?? 0) > 0) {
                $agentId = (int) $property->agent_id;
                $credits[$agentId] = round((float) ($credits[$agentId] ?? 0) + 1.0, 4);
            }
        }

        return $credits;
    }

    private function buildCard(MotivationRule $rule, float $fact, ?MotivationAchievement $achievement): array
    {
        $threshold = (float) $rule->threshold_value;
        $remaining = max(0, (int) ceil($threshold - $fact));
        $status = $remaining <= 0 ? 'achieved' : 'in_progress';
        $achievementStatus = $achievement?->status;
        if ($achievementStatus === 'issued') {
            $status = 'issued';
        }

        $uiMeta = $this->applyUiMetaFallback($rule);
        $messagePreview = $this->buildMessagePreview($uiMeta, $remaining);

        return [
            'rule_id' => (int) $rule->id,
            'scope' => (string) $rule->scope,
            'reward_type' => (string) $rule->reward_type,
            'name' => (string) $rule->name,
            'fact' => round($fact, 4),
            'threshold' => round($threshold, 4),
            'remaining' => $remaining,
            'status' => $status,
            'achievement_status' => $achievementStatus,
            'ui_meta' => $uiMeta,
            'message_preview' => $messagePreview,
        ];
    }

    private function loadAchievements(
        EloquentCollection $rules,
        ?int $userId,
        bool $companyScope,
        string $periodType,
        string $dateFrom,
        string $dateTo
    ): Collection {
        if ($rules->isEmpty()) {
            return collect();
        }

        return MotivationAchievement::query()
            ->whereIn('rule_id', $rules->pluck('id')->all())
            ->where('period_type', $periodType)
            ->whereDate('date_from', $dateFrom)
            ->whereDate('date_to', $dateTo)
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
