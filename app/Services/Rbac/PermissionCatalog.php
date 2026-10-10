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
