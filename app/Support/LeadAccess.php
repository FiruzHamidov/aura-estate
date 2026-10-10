<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LeadAccess
{
    public function __construct(
        private readonly RbacBranchScope $rbacBranchScope
    ) {}

    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function canReadAll(?User $user): bool
    {
        return $user?->hasPermission('leads.scope.all') ?? false;
    }

    public function canReadBranch(?User $user): bool
    {
        return $user?->hasPermission('leads.scope.branch') ?? false;
    }

    public function isBranchScoped(?User $user): bool
    {
        return ! $this->canReadAll($user)
            && ($this->canReadBranch($user) || ($user?->hasPermission('leads.scope.own') ?? false));
    }

    public function ensureAction(User $user, string $action): void
    {
        abort_unless($user->hasPermission('leads.'.$action)
            && ($this->canReadAll($user) || $this->isBranchScoped($user)), 403, 'Нет права на это действие с лидами.');
    }

    public function visibleQuery(User $authUser): Builder
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = Lead::query()->with(['branch', 'creator', 'responsibleAgent', 'client']);

        if ($authUser?->usesAssignedGroupScope()) {
            if (! $this->canReadAll($authUser) && ! $this->isBranchScoped($authUser)) {
                return $query->whereRaw('1 = 0');
            }
            app(RopGroupAccess::class)->scope($query, $authUser, 'leads.branch_group_id', 'leads.branch_id');
        }

        if ($this->canReadAll($authUser)) {
            return $query;
        }

        if (! $this->isBranchScoped($authUser) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if ($this->canReadBranch($authUser)) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($authUser) {
            $builder
                ->where('responsible_agent_id', $authUser->id)
                ->orWhere('created_by', $authUser->id);
        });
    }

    public function ensureVisible(User $authUser, Lead $lead): void
    {
        app(RopGroupAccess::class)->ensureVisible($authUser, $lead);

        $allowed = $this->visibleQuery($authUser)
            ->whereKey($lead->id)
            ->exists();

        if ($allowed) {
            return;
        }

        if ($this->rbacBranchScope->isRop($authUser)) {
            $this->rbacBranchScope->denyBranchScopeViolation('Lead does not belong to your branch.');
        }

        abort(403, 'Forbidden');
    }

    public function normalizeCreationData(array $data, User $authUser): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScoped($authUser)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        $data['created_by'] = $authUser->id;

        if (
            in_array($roleSlug, ['agent', 'mop', 'manager', 'operator'], true)
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
        }

        return $data;
    }

    public function normalizeUpdateData(array $data, User $authUser, Lead $lead): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScoped($authUser)) {
            $data['branch_id'] = $lead->branch_id ?: $authUser->branch_id;
        }

        return $data;
    }

    public function validateMutationTargets(User $authUser, array $data): void
    {
        $roleSlug = $this->roleSlug($authUser);

        if (! $this->isBranchScoped($authUser)) {
            return;
        }

        $branchId = $data['branch_id'] ?? null;

        if ((int) $branchId !== (int) $authUser->branch_id) {
            abort(422, 'branch_id must match your branch.');
        }

        foreach (['created_by', 'responsible_agent_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }

            $targetUser = User::query()->find($data[$field]);

            if (! $targetUser || (int) $targetUser->branch_id !== (int) $authUser->branch_id) {
                abort(422, sprintf('%s must belong to your branch.', $field));
            }
        }
    }
}
