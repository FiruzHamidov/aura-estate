<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'permissions')) {
            return;
        }
        DB::transaction(function () {
            foreach (DB::table('roles')->whereIn('slug', ['admin', 'superadmin', 'rop', 'branch_director'])->lockForUpdate()->get() as $role) {
                if ($role->permissions === null) {
                    continue;
                }
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                if (! in_array('motivation.manage', $before, true)) {
                    continue;
                }
                $grants = ['motivation.verify'];
                if ($role->slug !== 'rop') {
                    $grants[] = 'motivation.approve';
                }
                if (in_array($role->slug, ['admin', 'superadmin'], true)) {
                    $grants[] = 'motivation.issue';
                }
                $after = array_values(array_unique([...$before, ...$grants]));
                if ($after === $before) {
                    continue;
                }
                $version = (int) ($role->permissions_version ?? 0) + 1;
                $update = ['permissions' => json_encode($after, JSON_THROW_ON_ERROR), 'updated_at' => now()];
                if (Schema::hasColumn('roles', 'permissions_version')) {
                    $update['permissions_version'] = $version;
                }
                DB::table('roles')->where('id', $role->id)->update($update);
                if (Schema::hasTable('role_permission_audits')) {
                    DB::table('role_permission_audits')->insert([
                        'role_id' => $role->id, 'actor_id' => null, 'role_name' => $role->name,
                        'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
                        'version' => $version, 'created_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Audited permission state must not overwrite later administrator decisions.
    }
};
