<?php

namespace App\Support;

use App\Models\User;

/** Source-record exception for a reviewed dismissal; recipient scope stays separate. */
final class EmployeeDismissalAccess
{
    public function canTransferAllRecords(User $actor, User $employee): bool
    {
        if (! $actor->hasPermission('users.dismiss') || ! $actor->hasPermission('users.dismissal.employee_records')
            || $actor->id === $employee->id || $employee->status !== User::STATUS_ACTIVE || $employee->isDeletedAccount()
            || ! $actor->branch_id || (int) $actor->branch_id !== (int) $employee->branch_id) {
            return false;
        }

        if ($actor->usesAssignedGroupScope()) {
            return $employee->hasPermission('users.subject.team')
                && app(RopGroupAccess::class)->allows($actor, $employee);
        }

        return $actor->hasPermission('users.scope.branch') && $actor->hasPermission('users.manage_branch_roles')
            && $employee->hasPermission('users.subject.branch_manage');
    }
}
