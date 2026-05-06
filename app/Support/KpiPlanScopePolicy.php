<?php

namespace App\Support;

use App\Models\BranchGroup;
use App\Models\User;

class KpiPlanScopePolicy
{
    public function __construct(private readonly RbacBranchScope $branchScope)
    {
    }

    public function ensureCanReadCommonPlan(User $actor, array $scope): void
    {
        $actor->loadMissing('role');
        $role = (string) ($actor->role?->slug ?? '');

        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            return;
        }

        if (in_array($role, ['rop', 'branch_director'], true)) {
            $this->assertBranchScope($actor, $scope);
            return;
        }

        if ($role === 'mop') {
            if (((string) ($scope['role'] ?? '')) === 'rop') {
                $this->denyScope();
            }

            $this->assertMopScope($actor, $scope);
            return;
        }

        $this->denyScope();
    }

    public function ensureCanManageCommonPlan(User $actor, array $scope): void
    {
        $actor->loadMissing('role');
        $role = (string) ($actor->role?->slug ?? '');

        if (in_array($role, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true)) {
            $this->assertBranchScope($actor, $scope);
            return;
        }

        if ($role === 'mop') {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed to edit common KPI plan.');
        }

        $this->denyScope();
    }

    public function ensureCanManageBulkScope(User $actor, array $scope): void
    {
        $actor->loadMissing('role');
        $role = (string) ($actor->role?->slug ?? '');

        if (in_array($role, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true)) {
            $this->assertBranchScope($actor, $scope);
            return;
        }

        if ($role === 'mop') {
            if (((string) ($scope['role'] ?? '')) === 'rop') {
                $this->denyScope();
            }
            $this->assertMopScope($actor, $scope);
            return;
        }

        $this->denyScope();
    }

    public function ensureCanReadUserPlan(User $actor, User $target): void
    {
        $this->ensureCanManageUserPlan($actor, $target, true);
    }

    public function ensureCanManageUserPlan(User $actor, User $target, bool $allowSelf = false): void
    {
        $actor->loadMissing('role');
        $target->loadMissing('role');

        $role = (string) ($actor->role?->slug ?? '');

        $allowed = match ($role) {
            'admin', 'superadmin', 'owner' => true,
            'rop', 'branch_director' => (int) $actor->branch_id === (int) $target->branch_id,
            'mop' => (int) $actor->branch_group_id === (int) $target->branch_group_id,
            default => $allowSelf && (int) $actor->id === (int) $target->id,
        };

        if (! $allowed) {
            $this->denyScope();
        }
    }

    private function assertBranchScope(User $actor, array $scope): void
    {
        $role = (string) ($actor->role?->slug ?? '');
        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            return;
        }

        $branchId = isset($scope['branch_id']) ? (int) $scope['branch_id'] : null;
        $branchGroupId = isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null;

        if ($branchId !== null && (int) $actor->branch_id !== $branchId) {
            $this->denyScope();
        }

        if ($branchGroupId !== null) {
            $belongs = BranchGroup::query()
                ->whereKey($branchGroupId)
                ->where('branch_id', $actor->branch_id)
                ->exists();

            if (! $belongs) {
                $this->denyScope();
            }
        }
    }

    private function assertMopScope(User $actor, array $scope): void
    {
        $branchId = isset($scope['branch_id']) ? (int) $scope['branch_id'] : null;
        $branchGroupId = isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null;

        if ($branchId !== null && (int) $actor->branch_id !== $branchId) {
            $this->denyScope();
        }

        if ($branchGroupId !== null && (int) $actor->branch_group_id !== $branchGroupId) {
            $this->denyScope();
        }
    }

    private function denyScope(): void
    {
        $this->branchScope->denyWithCode('KPI_FORBIDDEN_SCOPE', 'Forbidden in current scope.');
    }
}
