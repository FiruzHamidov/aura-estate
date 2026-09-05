<?php

namespace App\Services\PropertyModeration;

use App\Models\Property;
use App\Models\PropertyModerationCase;
use App\Models\PropertyModerationEvent;
use App\Models\PropertyPromotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class PropertyModerationAccess
{
    public function canCreate(User $user): bool
    {
        return in_array($this->role($user), config('property-moderation.creator_roles', []), true);
    }

    public function canEdit(User $user, Property $property): bool
    {
        $role = $this->role($user);
        if (! in_array($role, config('property-moderation.creator_roles', []), true)) {
            return false;
        }

        if (in_array($role, config('property-moderation.global_moderator_roles', []), true)) {
            return true;
        }

        if (in_array((int) $user->id, array_filter([
            (int) $property->created_by,
            (int) $property->agent_id,
            (int) ($property->co_owner_user_id ?? 0),
        ]), true)) {
            return true;
        }

        if ($role === 'mop') {
            return $user->branch_group_id !== null
                && $this->propertyBranchGroupId($property) === (int) $user->branch_group_id;
        }

        if (in_array($role, ['rop', 'branch_director'], true)) {
            return $user->branch_id !== null
                && $this->propertyBranchId($property) === (int) $user->branch_id;
        }

        return false;
    }

    public function canManageDeal(User $user, Property $property): bool
    {
        $role = $this->role($user);

        return in_array($role, config('property-moderation.creator_roles', []), true)
            && ($this->canEdit($user, $property) || $role === 'agent');
    }

    public function canModerate(User $user, Property $property): bool
    {
        $role = $this->role($user);
        if (! in_array($role, config('property-moderation.moderator_roles', []), true)) {
            return false;
        }

        if (in_array($role, config('property-moderation.global_moderator_roles', []), true)) {
            return true;
        }

        return $user->branch_id !== null
            && $this->propertyBranchId($property) === (int) $user->branch_id;
    }

    public function scopeModeratable(Builder $query, User $user): Builder
    {
        if (in_array($this->role($user), config('property-moderation.global_moderator_roles', []), true)) {
            return $query;
        }
        if (! $user->branch_id || ! in_array($this->role($user), config('property-moderation.moderator_roles', []), true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $properties) use ($user): void {
            $properties->where('branch_id', $user->branch_id)
                ->orWhere(function (Builder $fallback) use ($user): void {
                    $fallback->whereNull('branch_id')->where(function (Builder $assigned) use ($user): void {
                        $assigned->whereHas('agent', fn (Builder $agent) => $agent->where('branch_id', $user->branch_id))
                            ->orWhere(function (Builder $unassigned) use ($user): void {
                                $unassigned->whereDoesntHave('agent', fn (Builder $agent) => $agent->whereNotNull('branch_id'))
                                    ->whereHas('creator', fn (Builder $creator) => $creator->where('branch_id', $user->branch_id));
                            });
                    });
                });
        });
    }

    public function canDecideCase(User $user, PropertyModerationCase $case): bool
    {
        $case->loadMissing(['property', 'parentCase']);
        if (! $this->canModerate($user, $case->property)) {
            return false;
        }

        if (PropertyModerationEvent::query()->where('moderation_case_id', $case->id)
            ->where('actor_id', $user->id)
            ->whereIn('event_type', ['price_review_opened', 'content_review_opened', 'duplicate_review_opened', 'property_media_changed', 'case_proposal_edited'])
            ->exists()) {
            return false;
        }

        if ($case->type === PropertyModerationCase::TYPE_APPEAL && $case->parentCase?->duplicateCandidates()->where('decided_by', $user->id)->exists()) {
            return false;
        }

        return ! in_array((int) $user->id, array_filter([
            (int) $case->submitted_by,
            (int) $case->property->created_by,
            (int) $case->property->agent_id,
            (int) ($case->property->co_owner_user_id ?? 0),
            (int) ($case->parentCase?->decided_by ?? 0),
        ]), true);
    }

    public function capabilities(?User $user, Property $property): array
    {
        if (! $user) {
            return [
                'can_edit' => false,
                'can_submit' => false,
                'can_approve' => false,
                'can_resolve_duplicate' => false,
                'can_appeal' => false,
                'can_resolve_appeal' => false,
                'can_manage_deal' => false,
                'can_request_promotion' => false,
                'can_approve_promotion' => false,
                'can_withdraw_changes' => false,
                'can_withdraw_listing' => false,
            ];
        }

        $canEdit = $this->canEdit($user, $property);
        $canModerate = $this->canModerate($user, $property);
        $openCases = Schema::hasTable('property_moderation_cases')
            ? $property->moderationCases()->open()->with('parentCase')->get()
            : collect();
        $decidableCases = $openCases->filter(fn (PropertyModerationCase $case) => $this->canDecideCase($user, $case));
        $ownsProperty = in_array((int) $user->id, array_filter([
            (int) $property->created_by,
            (int) $property->agent_id,
            (int) ($property->co_owner_user_id ?? 0),
        ]), true);
        $hasBlockingCase = $openCases->contains(fn (PropertyModerationCase $case) => $case->blocking);
        $isPublished = Schema::hasColumn('properties', 'publication_status')
            ? $property->publication_status === 'published' && ! $hasBlockingCase
            : $property->moderation_status === Property::PUBLIC_MODERATION_STATUS;
        $requestedPromotions = Schema::hasTable('property_promotions')
            ? $property->promotions()->where('status', PropertyPromotion::STATUS_REQUESTED)->get()
            : collect();

        return [
            'can_edit' => $canEdit,
            'can_submit' => $canEdit,
            'can_approve' => $decidableCases->isNotEmpty(),
            'can_resolve_duplicate' => $decidableCases->contains('type', PropertyModerationCase::TYPE_DUPLICATE),
            'can_appeal' => $canEdit && Schema::hasTable('property_moderation_cases') && $property->moderationCases()
                ->whereIn('status', [PropertyModerationCase::STATUS_REJECTED, PropertyModerationCase::STATUS_MERGED])
                ->exists(),
            'can_resolve_appeal' => $decidableCases->contains('type', PropertyModerationCase::TYPE_APPEAL),
            'can_manage_deal' => $this->canManageDeal($user, $property),
            'can_request_promotion' => $canEdit && $isPublished && $requestedPromotions->isEmpty(),
            'can_approve_promotion' => $canModerate && ! $ownsProperty && $requestedPromotions
                ->contains(fn (PropertyPromotion $promotion) => (int) $promotion->requested_by !== (int) $user->id),
            'can_withdraw_changes' => $canEdit,
            'can_withdraw_listing' => $canEdit,
        ];
    }

    private function role(User $user): string
    {
        $user->loadMissing('role');

        return (string) ($user->role?->slug ?? '');
    }

    private function propertyBranchId(Property $property): int
    {
        if ($property->branch_id) {
            return (int) $property->branch_id;
        }

        $property->loadMissing(['agent', 'creator']);

        return (int) ($property->agent?->branch_id ?: $property->creator?->branch_id ?: 0);
    }

    private function propertyBranchGroupId(Property $property): int
    {
        if ($property->branch_group_id) {
            return (int) $property->branch_group_id;
        }

        $property->loadMissing(['agent', 'creator']);

        return (int) ($property->agent?->branch_group_id ?: $property->creator?->branch_group_id ?: 0);
    }
}
