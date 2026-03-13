<?php

namespace App\Support;

use App\Models\DealPipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DealPipelineAccess
{
    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function isPrivilegedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['superadmin', 'admin', 'marketing'], true);
    }

    public function isBranchManager(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop'], true);
    }

    public function isManagerScopedRole(?string $roleSlug): bool
    {
        return $roleSlug === 'manager';
    }

    public function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'agent', 'manager', 'operator'], true);
    }

    public function canManage(?User $user, ?DealPipeline $pipeline = null): bool
    {
        $roleSlug = $this->roleSlug($user);

        if ($this->isPrivilegedRole($roleSlug)) {
            return true;
        }

        if (! $this->isBranchManager($roleSlug) || empty($user?->branch_id)) {
            return false;
        }

        if (! $pipeline) {
            return true;
        }

        return (int) $pipeline->branch_id === (int) $user->branch_id;
    }

    public function visibleQuery(User $authUser): Builder
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = DealPipeline::query()->with(['branch', 'stages']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (! $this->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $builder) use ($authUser) {
            $builder
                ->whereNull('branch_id')
                ->orWhere('branch_id', $authUser->branch_id);
        });

        if ($this->isManagerScopedRole($roleSlug)) {
            $query->where('code', DealPipeline::CODE_PROPERTY_CONTROL);
        }

        return $query;
    }

    public function ensureVisible(User $authUser, DealPipeline $pipeline): void
    {
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($pipeline->id)
            ->exists();

        abort_unless($allowed, 403, 'Forbidden');
    }

    public function ensureManageable(User $authUser, DealPipeline $pipeline): void
    {
        abort_unless($this->canManage($authUser, $pipeline), 403, 'Forbidden');
    }

    public function normalizeMutationData(array $data, User $authUser): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchManager($roleSlug)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        return $data;
    }

    public function validateMutationData(User $authUser, array $data): void
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isPrivilegedRole($roleSlug)) {
            return;
        }

        abort_unless($this->isBranchManager($roleSlug), 403, 'Forbidden');

        $branchId = $data['branch_id'] ?? null;

        if ((int) $branchId !== (int) $authUser->branch_id) {
            abort(422, 'branch_id must match your branch.');
        }
    }
}
