<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** @use HasFactory<\Database\Factories\RoleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'slug'];

    protected $casts = ['permissions' => 'array', 'permissions_version' => 'integer'];

    public function permissionKeys(): array
    {
        // An explicit empty array revokes every grant. Null preserves pre-migration behavior.
        return $this->permissions ?? app(\App\Services\Rbac\PermissionCatalog::class)->defaults($this->slug);
    }

    public function hasPermission(string $permission): bool
    {
        return array_key_exists($permission, config('permissions', []))
            && in_array($permission, $this->permissionKeys(), true);
    }

    public static function systemRoles(): array
    {
        return config('roles.system', []);
    }

    public static function upsertSystemRoles(): void
    {
        $now = now();

        $roles = collect(static::systemRoles())
            ->map(fn (array $role) => array_merge($role, [
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->all();

        if ($roles === []) {
            return;
        }

        static::query()->upsert(
            $roles,
            ['slug'],
            ['name', 'description', 'updated_at']
        );
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
