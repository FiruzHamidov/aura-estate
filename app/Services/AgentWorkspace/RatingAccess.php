<?php

namespace App\Services\AgentWorkspace;

use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Support\Facades\DB;

final class RatingAccess
{
    private ?array $visibleBranchIds = null;

    private function visibleBranches(): array
    {
        if ($this->visibleBranchIds === null) {
            $query = DB::table('branches');
            \App\Support\BranchVisibility::apply($query, 'branches.id');
            $this->visibleBranchIds = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->visibleBranchIds;
    }

    public function scopes(User $actor): array
    {
        abort_unless($actor->hasPermission('agent_rating.view'), 403);
        $scopes = [];
        $rop = app(RopGroupAccess::class);
        $groups = $actor->usesAssignedGroupScope() ? $rop->groupIds($actor) : null;
        if ($actor->hasPermission('agent_rating.scope.all') && $groups === null) {
            $scopes[] = ['key' => 'all', 'label' => 'Вся компания'];
            foreach (DB::table('branches')->whereIn('id', $this->visibleBranches())->orderBy('name')->get() as $branch) {
                $scopes[] = ['key' => 'branch:'.$branch->id, 'label' => $branch->name];
            }
        } elseif ($actor->hasPermission('agent_rating.scope.branch') && $actor->branch_id && $groups === null) {
            $scopes[] = ['key' => 'branch:'.$actor->branch_id, 'label' => 'Мой филиал'];
        }
        if ($groups !== null && ($actor->hasPermission('agent_rating.scope.all') || $actor->hasPermission('agent_rating.scope.branch') || $actor->hasPermission('agent_rating.scope.group'))) {
            foreach (DB::table('branch_groups')->whereIn('id', $groups)->orderBy('name')->get() as $group) {
                $scopes[] = ['key' => 'group:'.$group->id, 'label' => $group->name];
            }
        } elseif ($actor->hasPermission('agent_rating.scope.group') && $actor->branch_group_id) {
            $scopes[] = ['key' => 'group:'.$actor->branch_group_id, 'label' => 'Моя группа'];
        }
        if ($actor->hasPermission('agent_rating.scope.own') || $scopes === []) {
            $scopes[] = ['key' => 'own', 'label' => 'Мой вклад'];
        }

        return $scopes;
    }

    public function resolve(User $actor, ?string $requested): string
    {
        $keys = array_column($this->scopes($actor), 'key');
        $scope = $requested ?: (in_array('branch:'.$actor->branch_id, $keys, true) ? 'branch:'.$actor->branch_id : $keys[0]);
        abort_unless(in_array($scope, $keys, true), 403, 'Область рейтинга недоступна.');

        return $scope;
    }

    public function matches(User $actor, array|object $record, string $scope): bool
    {
        $row = (array) $record;
        if (! empty($row['branch_id']) && ! in_array((int) $row['branch_id'], $this->visibleBranches(), true)) {
            return false;
        }

        return match (strtok($scope, ':')) {
            'all' => true,
            'branch' => (int) ($row['branch_id'] ?? 0) === (int) substr($scope, 7),
            'group' => (int) ($row['branch_group_id'] ?? 0) === (int) substr($scope, 6),
            'own' => (int) ($row['agent_id'] ?? $row['user_id'] ?? 0) === (int) $actor->id,
            default => false,
        };
    }

    public function ensureSource(User $actor, object $source): void
    {
        foreach ($this->scopes($actor) as $scope) {
            if ($this->matches($actor, $source, $scope['key'])) {
                return;
            }
        }
        abort(403, 'Сделка вне области доступа.');
    }

    public function fingerprint(User $actor): string
    {
        $permissions = $actor->role?->permissionKeys() ?? [];
        sort($permissions);

        return hash('sha256', json_encode([$actor->id, $permissions, $actor->branch_id, $actor->branch_group_id, $actor->access_scope_version, $this->scopes($actor), $this->visibleBranches()]));
    }
}
