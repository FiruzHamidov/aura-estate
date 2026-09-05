<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;

class PropertyControlWorkflow
{
    public const STAGE_NEW = 'new';
    public const STAGE_SECURITY_REVIEW = 'security_review';
    public const STAGE_BRANCH_CLARIFICATION = 'branch_clarification';
    public const STAGE_BRANCH_CORRECTION = 'branch_correction';
    public const STAGE_SECURITY_RECHECK = 'security_recheck';
    public const STAGE_SECURITY_VERIFIED = 'security_verified';
    public const STAGE_SECURITY_FLAGGED = 'security_flagged';
    public const STAGE_CANCELLED = 'cancelled';

    private const SECURITY_TRANSITIONS = [
        self::STAGE_NEW => [self::STAGE_SECURITY_REVIEW, self::STAGE_CANCELLED],
        self::STAGE_SECURITY_REVIEW => [
            self::STAGE_BRANCH_CLARIFICATION,
            self::STAGE_SECURITY_VERIFIED,
            self::STAGE_SECURITY_FLAGGED,
            self::STAGE_CANCELLED,
        ],
        self::STAGE_BRANCH_CLARIFICATION => [self::STAGE_CANCELLED],
        self::STAGE_BRANCH_CORRECTION => [self::STAGE_CANCELLED],
        self::STAGE_SECURITY_RECHECK => [
            self::STAGE_BRANCH_CLARIFICATION,
            self::STAGE_SECURITY_VERIFIED,
            self::STAGE_SECURITY_FLAGGED,
            self::STAGE_CANCELLED,
        ],
    ];

    private const BRANCH_TRANSITIONS = [
        self::STAGE_BRANCH_CLARIFICATION => [self::STAGE_BRANCH_CORRECTION],
        self::STAGE_BRANCH_CORRECTION => [self::STAGE_SECURITY_RECHECK],
    ];

    private const COMMENT_REQUIRED_STAGES = [
        self::STAGE_BRANCH_CLARIFICATION,
        self::STAGE_SECURITY_FLAGGED,
        self::STAGE_CANCELLED,
    ];

    public function isControlDeal(Deal $deal): bool
    {
        $deal->loadMissing('pipeline');

        return $deal->isPropertyControl();
    }

    public function allowedTargetSlugs(User $actor, Deal $deal): array
    {
        if (! $this->isControlDeal($deal) || ! $deal->stage) {
            return [];
        }

        $role = $actor->role?->slug;
        $current = $deal->stage->slug;

        if (in_array($role, ['admin', 'superadmin'], true)) {
            return array_values(array_unique(array_merge(
                self::SECURITY_TRANSITIONS[$current] ?? [],
                self::BRANCH_TRANSITIONS[$current] ?? []
            )));
        }

        if ($role === 'security') {
            return self::SECURITY_TRANSITIONS[$current] ?? [];
        }

        if (
            in_array($role, ['branch_director', 'rop', 'manager', 'operator', 'agent'], true)
            && ! empty($actor->branch_id)
            && (int) $actor->branch_id === (int) $deal->branch_id
        ) {
            return self::BRANCH_TRANSITIONS[$current] ?? [];
        }

        return [];
    }

    public function ensureCanMove(User $actor, Deal $deal, DealStage $target, ?string $comment): void
    {
        if (! $this->isControlDeal($deal) || (int) $target->pipeline_id !== (int) $deal->pipeline_id) {
            $this->transitionNotAllowed();
        }

        if (! in_array($target->slug, $this->allowedTargetSlugs($actor, $deal), true)) {
            $this->transitionNotAllowed();
        }

        if (
            in_array($target->slug, self::COMMENT_REQUIRED_STAGES, true)
            && mb_strlen(trim((string) $comment)) < 10
        ) {
            throw ValidationException::withMessages([
                'comment' => ['Комментарий должен содержать не менее 10 символов.'],
            ]);
        }
    }

    private function transitionNotAllowed(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Переход из текущего этапа недоступен.',
            'code' => 'CRM_STAGE_TRANSITION_NOT_ALLOWED',
            'errors' => ['stage_id' => ['CRM_STAGE_TRANSITION_NOT_ALLOWED']],
        ], 422));
    }

    public function capabilities(User $actor, Deal $deal): array
    {
        $allowedSlugs = $this->allowedTargetSlugs($actor, $deal);
        $targetStages = $deal->pipeline?->stages
            ?->whereIn('slug', $allowedSlugs)
            ->values()
            ->map(fn (DealStage $stage) => [
                'id' => $stage->id,
                'slug' => $stage->slug,
                'name' => $stage->name,
                'requires_comment' => in_array($stage->slug, self::COMMENT_REQUIRED_STAGES, true),
            ])
            ->all() ?? [];

        return [
            'can_view' => true,
            'can_comment' => true,
            'can_set_deadline' => in_array($actor->role?->slug, ['security', 'admin', 'superadmin'], true),
            'can_claim' => $actor->role?->slug === 'security'
                && $deal->stage?->slug === self::STAGE_NEW
                && empty($deal->responsible_agent_id),
            'can_edit_business_data' => false,
            'can_move' => $targetStages !== [],
            'allowed_target_stages' => $targetStages,
        ];
    }
}
