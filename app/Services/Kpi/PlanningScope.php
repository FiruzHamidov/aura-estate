<?php

namespace App\Services\Kpi;

use App\Models\BranchGroup;
use App\Models\MotivationRule;
use App\Models\User;
use App\Support\BranchVisibility;
use App\Support\KpiPlanScopePolicy;
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PlanningScope
{
    public function ensureManager(User $actor, string $type): void
    {
        abort_unless($type === 'program' ? $actor->hasPermission('motivation.manage')
            : ($actor->hasPermission('kpi.plans.user.manage') || $actor->hasPermission('kpi.plans.common.manage')), 403);
    }

    public function users(User $actor, bool $company = false): Builder
    {
        $query = User::query()->select(['users.id', 'users.name', 'users.role_id', 'users.branch_id', 'users.branch_group_id', 'users.status'])
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->withPermission('kpi.subject.plan_templates'));
        $branches = DB::table('branches');
        BranchVisibility::apply($branches, 'branches.id');
        $query->whereIn('branch_id', $branches->select('id'));
        if ($company && ! $actor->usesAssignedGroupScope() && $actor->hasPermission('motivation.achievements.scope.all')) {
            return $query;
        }
        if ($actor->usesAssignedGroupScope()) {
            app(RopGroupAccess::class)->scope($query, $actor, 'users.branch_group_id', 'users.branch_id');
            $query->whereHas('role', fn ($q) => $q->withPermission('kpi.subject.assigned_groups'));
        }
        if ($actor->hasPermission('kpi.plans.scope.all')) {
            return $query;
        }
        if ($actor->hasPermission('kpi.plans.scope.branch') && $actor->branch_id) {
            return $query->where('branch_id', $actor->branch_id);
        }
        if ($actor->hasPermission('kpi.plans.scope.group') && $actor->branch_group_id) {
            return $query->where('branch_group_id', $actor->branch_group_id)
                ->whereHas('role', fn ($q) => $q->withPermission('kpi.subject.group_templates'));
        }

        return $query->whereRaw('1 = 0');
    }

    public function groups(User $actor): Collection
    {
        $branches = DB::table('branches');
        BranchVisibility::apply($branches, 'branches.id');
        $query = BranchGroup::whereIn('branch_id', $branches->select('id'));
        if ($actor->usesAssignedGroupScope()) {
            $query->whereIn('id', app(RopGroupAccess::class)->groupIds($actor));
        }
        if (! $actor->hasPermission('kpi.plans.scope.all')) {
            if ($actor->hasPermission('kpi.plans.scope.branch') && $actor->branch_id) {
                $query->where('branch_id', $actor->branch_id);
            } elseif ($actor->hasPermission('kpi.plans.scope.group') && $actor->branch_group_id) {
                $query->whereKey($actor->branch_group_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->orderBy('name')->orderBy('id')->get(['id', 'name', 'branch_id']);
    }

    /** An unassigned draft is private to its author; assigned drafts are shared with authorized managers. */
    public function authorize(User $actor, string $type, array $data, ?int $owner = null): array
    {
        $this->ensureManager($actor, $type);
        $scope = ['user_id' => null, 'branch_group_id' => null, 'branch_id' => null];
        if ($type === 'goal' && ! empty($data['user_id'])) {
            $user = $this->users($actor)->whereKey($data['user_id'])->first();
            abort_unless($user, 403);
            app(KpiPlanScopePolicy::class)->ensureCanManageUserPlan($actor, $user);

            return ['user_id' => $user->id, 'branch_group_id' => $user->branch_group_id, 'branch_id' => $user->branch_id];
        }
        if (! empty($data['branch_group_id'])) {
            $group = $this->groups($actor)->firstWhere('id', (int) $data['branch_group_id']);
            abort_unless($group, 403);
            $scope = ['user_id' => null, 'branch_group_id' => $group->id, 'branch_id' => $group->branch_id];
            app(KpiPlanScopePolicy::class)->ensureCanManageCommonPlan($actor, $scope);
        } elseif ($type === 'program' && (! empty($data['branch_id']) || ($data['audience'] ?? null) === 'company')) {
            $scope['branch_id'] = $data['branch_id'] ?? null;
            abort_unless(app(MotivationProgramService::class)->canManage($actor, new MotivationRule($scope)), 403);
            if ($scope['branch_id']) {
                $visible = DB::table('branches')->where('id', $scope['branch_id']);
                BranchVisibility::apply($visible, 'branches.id');
                abort_unless($visible->exists(), 403);
            }
        } else {
            abort_unless($owner === null || $owner === $actor->id, 403);
        }

        return $scope;
    }

    public function canAccess(User $actor, string $type, array $data, int $owner): bool
    {
        try {
            $this->authorize($actor, $type, $data, $owner);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            return false;
        }
    }

    public function recipients(User $actor, array $data, bool $lock = false): Collection
    {
        $query = $this->users($actor, ($data['audience'] ?? null) === 'company')
            ->when(! empty($data['user_id']), fn ($q) => $q->whereKey($data['user_id']))
            ->when(! empty($data['branch_group_id']), fn ($q) => $q->where('branch_group_id', $data['branch_group_id']))
            ->when(! empty($data['branch_id']), fn ($q) => $q->where('branch_id', $data['branch_id']))
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }
}
