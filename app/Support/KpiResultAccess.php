<?php

namespace App\Support;

use App\Models\User;

final class KpiResultAccess
{
    public function visible(User $actor, object|array $record, string $prefix = 'kpi.reports'): bool
    {
        $row = (array) $record;
        // An agent can read the group template applied to their own personal goal.
        if ($prefix === 'kpi.plans' && ! $actor->usesAssignedGroupScope() && empty($row['user_id'])
            && $actor->hasPermission('kpi.plans.user.view') && $actor->hasPermission('kpi.plans.scope.own')
            && $actor->branch_group_id && (int) $actor->branch_group_id === (int) ($row['branch_group_id'] ?? 0)) {
            return true;
        }
        $view = $prefix === 'kpi.plans' ? (! empty($row['user_id']) ? 'kpi.plans.user.view' : 'kpi.plans.common.view') : $prefix.'.view';
        if (! $actor->hasPermission($view)) {
            return false;
        }
        if ($actor->usesAssignedGroupScope() && ! in_array((int) ($row['branch_group_id'] ?? 0), app(RopGroupAccess::class)->groupIds($actor), true)) {
            return false;
        }
        if ($actor->hasPermission($prefix.'.scope.all')) {
            return true;
        }
        if ($prefix === 'motivation.achievements'
            && (($actor->hasPermission($prefix.'.scope.branch') && ! $actor->branch_id)
                || ($actor->hasPermission($prefix.'.scope.group') && ! $actor->branch_group_id))) {
            return (int) $actor->id === (int) ($row['user_id'] ?? 0);
        }
        if ($actor->hasPermission($prefix.'.scope.branch')) {
            return $actor->branch_id && (int) $actor->branch_id === (int) ($row['branch_id'] ?? 0);
        }
        if ($actor->hasPermission($prefix.'.scope.group')) {
            return $actor->branch_group_id && (int) $actor->branch_group_id === (int) ($row['branch_group_id'] ?? 0);
        }

        return $actor->hasPermission($prefix.'.scope.own') && (int) $actor->id === (int) ($row['user_id'] ?? 0);
    }
}
