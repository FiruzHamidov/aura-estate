<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LeadAccess
{
    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function isPrivilegedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['superadmin', 'admin'], true);
    }

    public function isBranchWideLeadRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'operator'], true);
    }

    public function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'agent', 'manager', 'operator'], true);
    }

    public function visibleQuery(User $authUser): Builder
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = Lead::query()->with(['branch', 'creator', 'responsibleAgent', 'client']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (! $this->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if ($this->isBranchWideLeadRole($roleSlug)) {
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
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($lead->id)
            ->exists();

        abort_unless($allowed, 403, 'Forbidden');
    }

    public function normalizeCreationData(array $data, User $authUser): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScopedRole($roleSlug)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        $data['created_by'] = $authUser->id;

        if (
            in_array($roleSlug, ['agent', 'manager', 'operator'], true)
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
        }

        return $data;
    }

    public function normalizeUpdateData(array $data, User $authUser, Lead $lead): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScopedRole($roleSlug)) {
            $data['branch_id'] = $lead->branch_id ?: $authUser->branch_id;
        }

        return $data;
    }

    public function validateMutationTargets(User $authUser, array $data): void
    {
        $roleSlug = $this->roleSlug($authUser);

        if (! $this->isBranchScopedRole($roleSlug)) {
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
