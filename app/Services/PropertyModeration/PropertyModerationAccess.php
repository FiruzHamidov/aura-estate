<?php

namespace App\Services\PropertyModeration;

use App\Models\Property;
use App\Models\PropertyDuplicateCandidate;
use App\Models\PropertyModerationCase;
use App\Models\PropertyModerationEvent;
use App\Models\PropertyPromotion;
use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class PropertyModerationAccess
{
    public function canCreate(User $user): bool
    {
        return $user->hasPermission('properties.create');
    }

    /** Read-only access to the same closed stages exposed by the SB report. */
    public function canViewSecurityReportProperty(User $user, Property $property): bool
    {
        return $this->role($user) === 'security'
            && in_array($property->moderation_status, config('security-property-control.trigger_statuses', []), true);
    }

    public function canEdit(User $user, Property $property): bool
    {
        if ($user->hasRole('rop') && ! app(RopGroupAccess::class)->allows($user, $property)) {
            return false;
        }
        if (! $user->hasPermission('properties.edit')) {
            return false;
        }

        if ($user->hasPermission('properties.manage_all')) {
            return true;
        }

        if (in_array((int) $user->id, array_filter([
            (int) $property->created_by,
            (int) $property->agent_id,
            (int) ($property->co_owner_user_id ?? 0),
        ]), true)) {
            return true;
        }

        if ($user->hasPermission('properties.edit.group')) {
            if ($user->branch_group_id !== null
                && $this->propertyBranchGroupId($property) === (int) $user->branch_group_id) return true;
        }

        if ($user->hasPermission('properties.edit.branch')) {
            return $user->branch_id !== null
                && $this->propertyBranchId($property) === (int) $user->branch_id;
        }

        return false;
    }

    public function canViewHistory(User $user, Property $property): bool
    {
        if ($user->hasRole('rop') && ! app(RopGroupAccess::class)->allows($user, $property)) return false;

        return $user->hasPermission('properties.history.view')
            && ($this->canEdit($user, $property) || $this->canViewSecurityReportProperty($user, $property));
    }

    public function canRefreshListingDate(User $user, Property $property): bool
    {
        return $user->hasPermission('properties.listing_date.refresh') && $this->canEdit($user, $property);
    }

    public function canManageCoOwner(User $user, Property $property): bool
    {
        return $user->hasPermission('properties.co_owner.manage') && $this->canEdit($user, $property);
    }

    public function canTransfer(User $user, Property $property): bool
    {
        return $user->hasPermission('properties.transfer') && $this->canModerate($user, $property);
    }

    public function canRequestPromotion(User $user, Property $property): bool
    {
        return $user->hasPermission('properties.promotions.request') && $this->canEdit($user, $property);
    }

    public function canManagePromotion(User $user, Property $property): bool
    {
        return $user->hasPermission('properties.promotions.manage') && $this->canModerate($user, $property);
    }

    public function canManageDeal(User $user, Property $property): bool
    {
        if ($user->hasRole('rop') && ! app(RopGroupAccess::class)->allows($user, $property)) return false;

        return $user->hasPermission('properties.deal.manage')
            && ($this->canEdit($user, $property) || $user->hasPermission('properties.deal.colleague'));
    }

    public function canModerate(User $user, Property $property): bool
    {
        if ($user->hasRole('rop') && ! app(RopGroupAccess::class)->allows($user, $property)) {
            return false;
        }
        if (! $user->hasPermission('properties.moderate')) {
            return false;
        }

        if ($user->hasPermission('properties.manage_all')) {
            return true;
        }

        return $user->branch_id !== null
            && $this->propertyBranchId($property) === (int) $user->branch_id;
    }

    public function scopeModeratable(Builder $query, User $user): Builder
    {
        if (! $user->hasPermission('properties.moderate')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasRole('rop')) {
            return app(RopGroupAccess::class)->scope($query, $user, 'properties.branch_group_id', 'properties.branch_id');
        }
        if ($user->hasPermission('properties.manage_all')) {
            return $query;
        }
        if (! $user->branch_id) {
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
        return $this->decisionBlockReason($user, $case) === null;
    }

    public function decisionBlockReason(User $user, PropertyModerationCase $case): ?string
    {
        $case->loadMissing(['property', 'parentCase']);
        if (! $this->canModerate($user, $case->property)) {
            return 'outside_moderation_scope';
        }

        // ROP+ may review their own proposals, within their existing branch scope.
        // Appeals still require someone other than the original decision maker.
        if ($case->type === PropertyModerationCase::TYPE_APPEAL && (
            (int) $case->parentCase?->decided_by === (int) $user->id
            || $case->parentCase?->duplicateCandidates()->where('decided_by', $user->id)->exists()
        )) {
            return 'appeal_reviewer_conflict';
        }

        return null;
    }

    public function capabilities(?User $user, Property $property): array
    {
        if (! $user) {
            return [
                'can_edit' => false,
                'can_submit' => false,
                'can_moderate' => false,
                'can_approve' => false,
                'can_approve_all' => false,
                'can_resolve_duplicate' => false,
                'can_mark_duplicate' => false,
                'can_appeal' => false,
                'can_resolve_appeal' => false,
                'can_manage_co_owner' => false,
                'can_transfer' => false,
                'can_manage_deal' => false,
                'can_assign_deal_users' => false,
                'can_reopen_listing' => false,
                'can_withdraw_promotion_request' => false,
                'can_revoke_promotion' => false,
                'can_reject_promotion' => false,
                'can_request_promotion' => false,
                'can_approve_promotion' => false,
                'can_manage_promotion_directly' => false,
                'can_withdraw_changes' => false,
                'can_withdraw_listing' => false,
                'submit_requires_review' => false,
            ];
        }

        $canEdit = $this->canEdit($user, $property);
        $canModerate = $this->canModerate($user, $property);
        $openCases = Schema::hasTable('property_moderation_cases')
            ? $property->moderationCases()->open()->with(['parentCase', 'duplicateCandidates'])->get()
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
            'can_moderate' => $canEdit || $canModerate,
            'can_approve' => $decidableCases->isNotEmpty(),
            'can_approve_all' => $openCases->isNotEmpty()
                && $decidableCases->count() === $openCases->count()
                && ! $openCases->contains('type', PropertyModerationCase::TYPE_APPEAL)
                && ! $openCases->contains(fn (PropertyModerationCase $case) => $case->type === PropertyModerationCase::TYPE_DUPLICATE
                    && $case->duplicateCandidates->contains('decision', PropertyDuplicateCandidate::DECISION_PENDING)
                ),
            'can_mark_duplicate' => $canModerate
                && in_array($property->publication_status, ['published', 'pending'], true)
                && ! $property->needsReopening()
                && ! $openCases->contains('type', PropertyModerationCase::TYPE_APPEAL),
            'can_resolve_duplicate' => $decidableCases->contains('type', PropertyModerationCase::TYPE_DUPLICATE),
            'can_appeal' => $canEdit && Schema::hasTable('property_moderation_cases') && $property->moderationCases()
                ->whereIn('status', [PropertyModerationCase::STATUS_REJECTED, PropertyModerationCase::STATUS_MERGED])
                ->exists(),
            'can_resolve_appeal' => $decidableCases->contains('type', PropertyModerationCase::TYPE_APPEAL),
            'can_manage_co_owner' => $this->canManageCoOwner($user, $property),
            'can_transfer' => $this->canTransfer($user, $property),
            'can_manage_deal' => $this->canManageDeal($user, $property),
            'can_assign_deal_users' => $this->canManageDeal($user, $property) && $user->hasPermission('properties.deal.assign_users'),
            'can_reopen_listing' => $canModerate && $property->needsReopening(),
            'can_withdraw_promotion_request' => $this->canRequestPromotion($user, $property),
            'can_revoke_promotion' => $this->canManagePromotion($user, $property),
            'can_reject_promotion' => $this->canManagePromotion($user, $property) && $requestedPromotions->isNotEmpty(),
            'can_request_promotion' => $this->canRequestPromotion($user, $property) && in_array($property->publication_status, ['published', 'pending'], true) && $requestedPromotions->isEmpty(),
            'can_approve_promotion' => $this->canManagePromotion($user, $property) && $isPublished && $requestedPromotions->isNotEmpty(),
            'can_manage_promotion_directly' => $this->canManagePromotion($user, $property) && $this->canRequestPromotion($user, $property) && $isPublished,
            'can_withdraw_changes' => $canEdit,
            'can_withdraw_listing' => $canEdit,
            'submit_requires_review' => $canEdit && (array) $property->approved_content_snapshot !== [],
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
