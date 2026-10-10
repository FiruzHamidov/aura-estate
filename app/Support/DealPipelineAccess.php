<?php

namespace App\Support;

use App\Models\DealPipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DealPipelineAccess
{
    public function __construct(
        private readonly RbacBranchScope $rbacBranchScope
    ) {}

    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function scopeType(?User $user): string
    {
        foreach (['all', 'hr', 'control', 'branch'] as $scope) {
            if ($user?->hasPermission('pipelines.scope.'.$scope)) {
                return $scope;
            }
        }

        return 'none';
    }

    public function isHrPipeline(?DealPipeline $pipeline): bool
    {
        return (bool) ($pipeline?->code === DealPipeline::CODE_HR_RECRUITMENT);
    }

    public function canManage(?User $user, ?DealPipeline $pipeline = null): bool
    {
        $scope = $this->scopeType($user);
        if (! $user?->hasPermission('pipelines.manage')) {
            return false;
        }

        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'hr') {
            return $pipeline ? $this->isHrPipeline($pipeline) : false;
        }

        if ($scope !== 'branch' || empty($user?->branch_id)) {
            return false;
        }

        if (! $pipeline) {
            return true;
        }

        return (int) $pipeline->branch_id === (int) $user->branch_id;
    }

    public function visibleQuery(User $authUser): Builder
    {
        $scope = $this->scopeType($authUser);

        $query = DealPipeline::query()->with(['branch', 'stages']);

        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'hr') {
            return $query->where('code', DealPipeline::CODE_HR_RECRUITMENT);
        }

        if ($scope === 'control') {
            return $query
                ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
                ->where('is_active', true);
        }

        if ($scope !== 'branch' || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $builder) use ($authUser) {
            $builder
                ->whereNull('branch_id')
                ->orWhere('branch_id', $authUser->branch_id);
        });

        return $query;
    }

    public function ensureVisible(User $authUser, DealPipeline $pipeline): void
    {
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($pipeline->id)
            ->exists();

        if ($allowed) {
            return;
        }

        if ($this->rbacBranchScope->isRop($authUser)) {
            $this->rbacBranchScope->denyBranchScopeViolation('Pipeline does not belong to your branch.');
        }

        abort(403, 'Forbidden');
    }

    public function ensureManageable(User $authUser, DealPipeline $pipeline): void
    {
        abort_unless($this->canManage($authUser, $pipeline), 403, 'Forbidden');
    }

    public function normalizeMutationData(array $data, User $authUser): array
    {
        $scope = $this->scopeType($authUser);

        if ($scope === 'branch') {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        if ($scope === 'hr') {
            $data['branch_id'] = null;
            $data['code'] = DealPipeline::CODE_HR_RECRUITMENT;
        }

        return $data;
    }

    public function validateMutationData(User $authUser, array $data): void
    {
        $scope = $this->scopeType($authUser);

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'hr') {
            if (($data['code'] ?? null) !== DealPipeline::CODE_HR_RECRUITMENT) {
                abort(422, 'HR can manage only the HR recruitment pipeline.');
            }

            if (! empty($data['branch_id'])) {
                abort(422, 'HR pipeline must be global.');
            }

            return;
        }

        abort_unless($scope === 'branch', 403, 'Forbidden');

        $branchId = $data['branch_id'] ?? null;

        if ((int) $branchId !== (int) $authUser->branch_id) {
            abort(422, 'branch_id must match your branch.');
        }
    }
}
