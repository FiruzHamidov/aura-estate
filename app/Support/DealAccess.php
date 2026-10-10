<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DealAccess
{
    public function __construct(
        private readonly DealPipelineAccess $pipelineAccess,
        private readonly ClientAccess $clientAccess,
        private readonly LeadAccess $leadAccess,
        private readonly RbacBranchScope $rbacBranchScope
    ) {}

    public function roleSlug(?User $user): ?string
    {
        return $this->pipelineAccess->roleSlug($user);
    }

    public function scopeType(?User $user): string
    {
        foreach (['all', 'hr', 'control', 'branch', 'own'] as $scope) {
            if ($user?->hasPermission('deals.scope.'.$scope)) {
                return $scope;
            }
        }

        return 'none';
    }

    public function canReadAll(?User $user): bool
    {
        return $this->scopeType($user) === 'all';
    }

    private function isBranchScoped(User $user): bool
    {
        return in_array($this->scopeType($user), ['branch', 'own'], true);
    }

    public function ensureAction(User $user, string $action): void
    {
        abort_unless($user->hasPermission('deals.'.$action) && $this->scopeType($user) !== 'none', 403, 'Нет права на это действие со сделкой.');
    }

    public function visibleQuery(User $authUser): Builder
    {
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

        if ($authUser?->usesAssignedGroupScope()) {
            app(RopGroupAccess::class)->scope($query, $authUser, 'crm_deals.branch_group_id', 'crm_deals.branch_id');
        }

        if ($this->canReadAll($authUser)) {
            return $query;
        }

        if (($this->scopeType($authUser) === 'hr')) {
            return $query->whereHas('pipeline', fn (Builder $builder) => $builder->where('code', DealPipeline::CODE_HR_RECRUITMENT));
        }

        if (($this->scopeType($authUser) === 'control')) {
            return $query->whereHas(
                'pipeline',
                fn (Builder $builder) => $builder
                    ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
                    ->where('is_active', true)
            );
        }

        if (! $this->isBranchScoped($authUser) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if (($this->scopeType($authUser) === 'branch')) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($authUser) {
            $builder
                ->where('responsible_agent_id', $authUser->id)
                ->orWhere('created_by', $authUser->id);

            if ($authUser->hasPermission('deals.related_control.view')) {
                $builder->orWhere(function (Builder $propertyControl) use ($authUser) {
                    $propertyControl
                        ->whereHas('pipeline', fn (Builder $pipeline) => $pipeline->where('code', DealPipeline::CODE_PROPERTY_CONTROL))
                        ->whereHas('primaryProperty', function (Builder $property) use ($authUser) {
                            $property
                                ->where('created_by', $authUser->id)
                                ->orWhere('agent_id', $authUser->id);
                        });
                });
            }
        });
    }

    public function ensureVisible(User $authUser, Deal $deal): void
    {
        app(RopGroupAccess::class)->ensureVisible($authUser, $deal);

        $allowed = $this->visibleQuery($authUser)
            ->whereKey($deal->id)
            ->exists();

        if ($allowed) {
            return;
        }

        if ($this->rbacBranchScope->isRop($authUser)) {
            $this->rbacBranchScope->denyBranchScopeViolation('Deal does not belong to your branch.');
        }

        abort(403, 'Forbidden');
    }

    public function normalizeMutationData(array $data, User $authUser): array
    {
        if ($this->isBranchScoped($authUser)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        if (($this->scopeType($authUser) === 'hr')) {
            $data['branch_id'] = null;
        }

        $data['created_by'] ??= $authUser->id;

        if (
            $authUser->hasPermission('deals.defaults.assign_creator')
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
        }

        return $data;
    }

    public function ensureCanCreate(User $authUser): void
    {
        $this->ensureAction($authUser, 'create');
    }

    public function ensureCanUpdate(User $authUser, Deal $deal): void
    {
        $this->ensureAction($authUser, 'update');
        $deal->loadMissing('pipeline');
        abort_if($deal->isPropertyControl() && ! $authUser->hasPermission('deals.control.edit'), 403,
            'Property control cards can be changed only through CRM activities and workflow actions.');
    }

    public function ensureCanDelete(User $authUser, ?Deal $deal = null): void
    {
        $this->ensureAction($authUser, 'delete');
        abort_if($deal?->isPropertyControl(), 403, 'System property-control cards cannot be deleted.');
    }

    public function validateMutationTargets(User $authUser, array $data): void
    {
        if (! $this->isBranchScoped($authUser)) {
            if (($this->scopeType($authUser) === 'hr')) {
                $pipelineId = $data['pipeline_id'] ?? null;
                $stageId = $data['stage_id'] ?? null;

                if ($pipelineId) {
                    $pipeline = DealPipeline::query()->find($pipelineId);

                    if (! $this->pipelineAccess->isHrPipeline($pipeline)) {
                        abort(422, 'HR can work only with the HR recruitment pipeline.');
                    }
                }

                if ($stageId) {
                    $stage = DealStage::query()->with('pipeline')->find($stageId);

                    if (! $stage || ! $this->pipelineAccess->isHrPipeline($stage->pipeline)) {
                        abort(422, 'HR can work only with stages of the HR recruitment pipeline.');
                    }
                }
            }

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
