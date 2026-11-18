<?php

use App\Services\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $permission = 'properties.history.published';
            foreach (DB::table('roles')->where('slug', 'security')->lockForUpdate()->get() as $role) {
                $beforePermissions = $role->permissions === null
                    ? app(PermissionCatalog::class)->defaults($role->slug)
                    : json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                $permissions = $beforePermissions;
                // Legacy roles still use catalog defaults; materialize their temporary grant once.
                if ($role->permissions === null && in_array('properties.history.view', $permissions, true)) {
                    $permissions[] = $permission;
                }
                $before = json_decode($role->permission_expirations ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                if (! in_array($permission, $permissions, true) || isset($before[$permission])) continue;

                $after = [...$before, $permission => now()->addMonthNoOverflow()->utc()->format('Y-m-d\TH:i:s\Z')];
                $version = $role->permissions_version + 1;
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    'permission_expirations' => json_encode($after, JSON_THROW_ON_ERROR),
                    'permissions_version' => $version, 'updated_at' => now(),
                ]);
                DB::table('role_permission_audits')->insert([
                    'role_id' => $role->id, 'actor_id' => null, 'role_name' => $role->name,
                    'before' => json_encode($beforePermissions, JSON_THROW_ON_ERROR),
                    'after' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    'before_expirations' => json_encode((object) $before, JSON_THROW_ON_ERROR),
                    'after_expirations' => json_encode((object) $after, JSON_THROW_ON_ERROR),
                    'version' => $version, 'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve the deadline and any subsequent administrator decisions.
    }
};
