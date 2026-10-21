<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $roles = DB::table('roles')->where('slug', 'rop')->lockForUpdate()->get();
            foreach ($roles as $role) {
                // Null permissions use catalog defaults. Preserve roles whose group access was explicitly revoked.
                if ($role->permissions === null) continue;
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                if (!in_array('data.scope.assigned_groups', $before, true) || in_array('crm_reports.scope.assigned_groups', $before, true)) continue;
                $after = [...array_values(array_filter($before, fn ($key) => !str_starts_with($key, 'crm_reports.scope.'))), 'crm_reports.scope.assigned_groups'];
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
        });
    }

    public function down(): void
    {
        // Permission changes are audited administrative state; reverting code must not rewrite later grants.
    }
};
