<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('roles')->whereNotNull('permissions')->lockForUpdate()->get() as $role) {
                $before = json_decode($role->permissions, true);
                if (! in_array('kpi.reports.view', $before, true) || ! in_array('properties.list.internal', $before, true)) {
                    continue;
                }
                $new = [];
                foreach (config('permissions') as $key => $definition) {
                    if (str_starts_with($key, 'agent_rating.') && in_array($role->slug, $definition['roles'] ?? [], true)) {
                        $new[] = $key;
                    }
                }
                $after = array_values(array_unique([...$before, ...$new]));
                if ($before === $after) {
                    continue;
                }
                $version = $role->permissions_version + 1;
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($after), 'permissions_version' => $version, 'updated_at' => now()]);
                DB::table('role_permission_audits')->insert(['role_id' => $role->id, 'actor_id' => null, 'role_name' => $role->name, 'before' => json_encode($before), 'after' => json_encode($after), 'version' => $version, 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Preserve later administrator grants and the permission audit trail.
    }
};
