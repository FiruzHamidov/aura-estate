<?php

namespace App\Services\Rbac;

class PermissionCatalog
{
    public function definitions(): array
    {
        return config('permissions', []);
    }

    public function defaults(?string $roleSlug): array
    {
        $permissions = [];
        foreach ($this->definitions() as $key => $definition) {
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
            $defaults = [...$defaults, 'clients.shared.view', 'clients.attach', 'clients.duplicates.search', 'bookings.scope.all', 'bookings.create', 'bookings.update.own', 'bookings.reports.view'];
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
