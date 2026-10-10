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

    /** Central policy predicate; used by both loaded users and fresh SQL selections. */
    public function usesAssignedGroupScope(): bool
    {
        return $this->hasPermission('data.scope.assigned_groups');
    }

    public function scopeUsingAssignedGroups(\Illuminate\Database\Eloquent\Builder $query, bool $enabled = true): void
    {
        $permission = 'data.scope.assigned_groups';
        $defaults = (config('permissions', [])[$permission]['roles'] ?? []);
        // Keep the predicate in SQL, so repeated group subqueries do not fetch all roles.
        // The legacy branch also supports deployments before the RBAC migration.
        if (! \Illuminate\Support\Facades\Schema::hasColumn($query->getModel()->getTable(), 'permissions')) {
            $query->whereIn($query->qualifyColumn('slug'), $defaults, 'and', ! $enabled);
            return;
        }
        $column = $query->qualifyColumn('permissions');
        $slug = $query->qualifyColumn('slug');
        $condition = fn ($roles) => $roles
            ->where(fn ($legacy) => $legacy->whereNull($column)->whereIn($slug, $defaults))
            ->orWhere(fn ($configured) => $configured->whereNotNull($column)->whereJsonContains($column, $permission));
        $enabled ? $query->where($condition) : $query->whereNot($condition);
    }

    public function permissionKeys(): array
    {
        // An explicit empty array revokes every grant. Null preserves pre-migration behavior.
        return $this->permissions ?? app(\App\Services\Rbac\PermissionCatalog::class)->defaults($this->slug);
    }

    public function hasPermission(string $permission): bool
    {
        if (!in_array($permission, $this->permissionKeys(), true)) return false;
        if (array_key_exists($permission, config('permissions', []))) return true;
        return str_starts_with($permission, 'users.assign_role.')
            && self::query()->where('slug', substr($permission, strlen('users.assign_role.')))->exists();
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
