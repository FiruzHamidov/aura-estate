<?php

namespace App\Services\Rbac;

class PermissionCatalog
{
    public function definitions(): array
    {
        $definitions = config('permissions', []);
        if (\Illuminate\Support\Facades\Schema::hasTable('roles')) {
            foreach (\App\Models\Role::query()->get(['slug', 'name']) as $role) {
                $key = 'users.assign_role.'.$role->slug;
                $definitions[$key] ??= [
                    'group' => 'Назначение ролей', 'name' => 'Назначение роли «'.$role->name.'»',
                    'description' => 'Разрешает назначать эту роль доступным пользователям. Создание и редактирование требуют отдельных прав; защита дополнительных привилегий сохраняется.',
                    'roles' => [], 'sensitive' => true,
                ];
            }
        }
        return $definitions;
    }

    public function defaults(?string $roleSlug): array
    {
        $permissions = [];
        foreach (config('permissions', []) as $key => $definition) {
            if (in_array($roleSlug, ($definition['roles'] ?? config($definition['roles_config'], [])), true)) {
                $permissions[] = $key;
            }
        }

        return $permissions;
    }

    public function migrationDefaults(?string $roleSlug): array
    {
        $defaults = $this->defaults($roleSlug);
        // Existing custom roles could read contacts explicitly shared with their users.
        // New roles still start without grants; this exception applies only to the migration.
        if ($roleSlug !== null && ! in_array($roleSlug, [...array_column(\App\Models\Role::systemRoles(), 'slug'), 'owner'], true)) {
            $defaults = [...$defaults, 'clients.shared.view', 'clients.attach', 'clients.duplicates.search', 'bookings.scope.all', 'bookings.create', 'bookings.update.own', 'bookings.reports.view', 'tasks.scope.own', 'tasks.create', 'tasks.update', 'tasks.assign.all', 'stories.own.view', 'stories.create', 'stories.update', 'stories.delete', 'stories.status', 'messaging.groups.manage_own', 'daily_reports.locked_period.override'];
            foreach (array_keys(config('catalog_permissions', [])) as $catalog) $defaults[] = 'catalogs.entries.'.$catalog.'.manage';
        }

        return array_values(array_unique($defaults));
    }

    public function publicDefinitions(): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            unset($definition['roles'], $definition['roles_config']);
            $result[] = ['key' => $key, 'sensitive' => false, ...$definition];
        }

        return $result;
    }
}
