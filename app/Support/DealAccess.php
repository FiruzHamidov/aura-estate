<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DealAccess
{
    public function __construct(
        private readonly DealPipelineAccess $pipelineAccess,
        private readonly ClientAccess $clientAccess,
        private readonly LeadAccess $leadAccess
    ) {}

    public function roleSlug(?User $user): ?string
    {
        return $this->pipelineAccess->roleSlug($user);
    }

    public function isPrivilegedRole(?string $roleSlug): bool
    {
        return $this->pipelineAccess->isPrivilegedRole($roleSlug);
    }

    public function isBranchManager(?string $roleSlug): bool
    {
        return $this->pipelineAccess->isBranchManager($roleSlug);
    }

    public function isBranchWideDealRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'manager'], true);
    }

    public function isBranchScopedRole(?string $roleSlug): bool
    {
        return $this->pipelineAccess->isBranchScopedRole($roleSlug);
    }

    public function visibleQuery(User $authUser): Builder
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = Deal::query()->with([
            'client',
            'lead',
            'branch',
            'creator',
            'responsibleAgent',
            'pipeline',
            'stage',
            'primaryProperty',
        ]);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (! $this->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if ($this->isBranchWideDealRole($roleSlug)) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($authUser) {
            $builder
                ->where('responsible_agent_id', $authUser->id)
                ->orWhere('created_by', $authUser->id);
        });
    }

    public function ensureVisible(User $authUser, Deal $deal): void
    {
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($deal->id)
            ->exists();

        abort_unless($allowed, 403, 'Forbidden');
    }

    public function normalizeMutationData(array $data, User $authUser): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScopedRole($roleSlug)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        $data['created_by'] ??= $authUser->id;

        if (
            in_array($roleSlug, ['agent', 'manager', 'operator'], true)
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
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

    public function ensurePipelineVisible(User $authUser, DealPipeline $pipeline): void
    {
        $this->pipelineAccess->ensureVisible($authUser, $pipeline);
    }

    public function ensureClientVisible(User $authUser, ?Client $client): void
    {
        if ($client) {
            $this->clientAccess->ensureVisible($authUser, $client);
        }
    }

    public function ensureLeadVisible(User $authUser, ?Lead $lead): void
    {
        if ($lead) {
            $this->leadAccess->ensureVisible($authUser, $lead);
        }
    }
}
