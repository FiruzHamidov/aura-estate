<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;

final class RoleAssignmentGuard
{
    public function ensureCanSave(User $actor, User $target): void
    {
        if ($actor->hasPermission('roles.manage')) {
            return;
        }

        // Retain historical HR/manager workflows for unmodified system roles.
        // Additional grants must not be acquired via assignment, password reset,
        // contact changes, organization changes, or demoting an elevated account.
        $ids = array_unique(array_filter([$target->role_id, $target->getRawOriginal('role_id')]));
        if ($ids === []) return;
        foreach (Role::whereIn('id', $ids)->get() as $role) {
            $added = array_diff($role->permissionKeys(), app(PermissionCatalog::class)->defaults($role->slug));
            foreach ($added as $permission) {
                if (!$role->hasPermission($permission)) continue;
                abort_unless($actor->hasPermission($permission), 403, 'Нельзя управлять учётной записью с дополнительными правами, которых у вас нет.');
            }
        }
    }
}
