<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $roles = DB::table('roles')->where('slug', 'security')
                ->whereNotNull('permissions')->lockForUpdate()->get();
            foreach ($roles as $role) {
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                if (! in_array('properties.history.view', $before, true)
                    || in_array('properties.history.published', $before, true)) {
                    continue;
                }

                $after = [...$before, 'properties.history.published'];
                $version = $role->permissions_version + 1;
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode($after, JSON_THROW_ON_ERROR),
                    'permissions_version' => $version,
                    'updated_at' => now(),
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
        // Preserve audited permission changes and subsequent administrator decisions.
    }
};
