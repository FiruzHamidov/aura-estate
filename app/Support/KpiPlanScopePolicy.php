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
        if (! $actor->hasPermission('kpi.plans.common.view')) $this->denyScope();
        $this->ensureScope($actor, $scope);
        $this->ensureGlobalTemplateAccess($actor, $scope, 'view');
    }

    public function ensureCanSelectUsers(User $actor, array $scope): void
    {
        if (! $actor->hasPermission('kpi.plans.common.view')) $this->denyScope();
        $this->ensureScope($actor, $scope);
    }

    public function ensureCanManageCommonPlan(User $actor, array $scope): void
    {
        if (! $actor->hasPermission('kpi.plans.common.manage')) {
            $this->branchScope->denyWithCode('KPI_FORBIDDEN_ROLE_ACTION', 'Role is not allowed to edit common KPI plan.');
        }
        $this->ensureScope($actor, $scope);
        $this->ensureGlobalTemplateAccess($actor, $scope, 'manage');
    }

    public function ensureCanManageBulkScope(User $actor, array $scope): void
    {
        if (! $actor->hasPermission('kpi.plans.bulk.manage')) $this->denyScope();
        $this->ensureScope($actor, $scope);
    }

    private function ensureGlobalTemplateAccess(User $actor, array $scope, string $action): void
    {
        if (! isset($scope['branch_id']) && ! isset($scope['branch_group_id'])
            && ! $actor->hasPermission('kpi.plans.scope.all')
            && ! $actor->hasPermission('kpi.plans.common.global.'.$action)) {
            $this->denyScope();
        }
    }

    private function ensureScope(User $actor, array $scope): void
    {
        if ($actor->usesAssignedGroupScope()) {
            app(RopGroupAccess::class)->ensureGroup($actor, isset($scope['branch_group_id']) ? (int) $scope['branch_group_id'] : null);
        }
        if ($actor->hasPermission('kpi.plans.scope.all')) return;
        if ($actor->hasPermission('kpi.plans.scope.branch')) {
            $this->assertBranchScope($actor, $scope);
            return;
        }
        if ($actor->hasPermission('kpi.plans.scope.group')) {
            if (isset($scope['role']) && ! app(\App\Services\KpiRoleCatalog::class)->allowsGroupTemplates((string) $scope['role'])) $this->denyScope();
            $this->assertMopScope($actor, $scope);
            return;
        }
        $this->denyScope();
    }

    public function ensureTargetMatchesBulkScope(User $actor, User $target, array $scope): void
    {
        $target->loadMissing('role');
        $this->ensureCanManageBulkScope($actor, array_merge($scope, ['role' => $target->role?->slug]));
        foreach (['branch_id', 'branch_group_id'] as $field) {
            if (isset($scope[$field]) && (int) $scope[$field] !== (int) $target->{$field}) {
                $this->denyScope();
            }
        }
        $roles = $scope['roles'] ?? (isset($scope['role']) ? [$scope['role']] : []);
        if ($roles !== [] && ! in_array($target->role?->slug, $roles, true)) {
            $this->denyScope();
        }
    }

    public function ensureCanReadUserPlan(User $actor, User $target): void
    {
        $this->ensureCanManageUserPlan($actor, $target, true);
    }

    public function ensureCanReadHistoricalUserPlan(User $actor, array $plan): void
    {
        if (! $actor->hasPermission('kpi.plans.user.view')) $this->denyScope();
        app(RopGroupAccess::class)->ensureGroup($actor, $plan['branch_group_id'] ?? null);
        $allowed = $actor->hasPermission('kpi.plans.scope.all')
            || ($actor->hasPermission('kpi.plans.scope.branch') && $actor->branch_id && (int) $actor->branch_id === (int) ($plan['branch_id'] ?? 0))
            || ($actor->hasPermission('kpi.plans.scope.group') && $actor->branch_group_id && (int) $actor->branch_group_id === (int) ($plan['branch_group_id'] ?? 0))
            || ($actor->hasPermission('kpi.plans.scope.own') && (int) $actor->id === (int) ($plan['user_id'] ?? 0));
        if (! $allowed) $this->denyScope();
    }

    public function ensureCanManageUserPlan(User $actor, User $target, bool $allowSelf = false): void
    {
        if (! $actor->hasPermission($allowSelf ? 'kpi.plans.user.view' : 'kpi.plans.user.manage')) $this->denyScope();
        if ($actor->usesAssignedGroupScope() && ! app(RopGroupAccess::class)->employees($actor, 'kpi.subject.assigned_groups')->whereKey($target->id)->exists()) $this->denyScope();
        $allowed = $actor->hasPermission('kpi.plans.scope.all')
            || ($actor->hasPermission('kpi.plans.scope.branch') && $actor->branch_id && (int) $actor->branch_id === (int) $target->branch_id)
            || ($actor->hasPermission('kpi.plans.scope.group') && $actor->branch_group_id && (int) $actor->branch_group_id === (int) $target->branch_group_id)
            || ($actor->hasPermission('kpi.plans.scope.own') && (int) $actor->id === (int) $target->id);

        if (! $allowed) {
            $this->denyScope();
        }
    }

    private function assertBranchScope(User $actor, array $scope): void
    {
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
