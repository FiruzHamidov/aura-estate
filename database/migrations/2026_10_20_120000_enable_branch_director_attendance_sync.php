<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $roles = DB::table('roles')->where('slug', 'branch_director')->lockForUpdate()->get();
            foreach ($roles as $role) {
                // Legacy roles use the updated defaults; explicit grants retain every other setting.
                if ($role->permissions === null) continue;
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                $after = array_values(array_unique([...$before, 'attendance.mappings.manage', 'attendance.mappings.scope.branch']));
                if ($before === $after) continue;
                $this->savePermissions($role, $before, $after);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $roles = DB::table('roles')->where('slug', 'branch_director')->lockForUpdate()->get();
            foreach ($roles as $role) {
                if ($role->permissions === null) continue;
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                if (! in_array('attendance.mappings.scope.branch', $before, true)) continue;
                // Older code cannot enforce this scope, so revoke mapping access on rollback.
                $after = array_values(array_diff($before, ['attendance.mappings.manage', 'attendance.mappings.scope.branch']));
                $this->savePermissions($role, $before, $after);
            }
        });
    }

    private function savePermissions(object $role, array $before, array $after): void
    {
        $version = $role->permissions_version + 1;
        DB::table('roles')->where('id', $role->id)->update([
            'permissions' => json_encode($after, JSON_THROW_ON_ERROR),
            'permissions_version' => $version, 'updated_at' => now(),
        ]);
        DB::table('role_permission_audits')->insert([
            'role_id' => $role->id, 'actor_id' => null, 'role_name' => $role->name,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'version' => $version, 'created_at' => now(),
        ]);
    }
};
