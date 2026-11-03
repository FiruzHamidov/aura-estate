<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $listScopes = [
                'properties.list.scope.all',
                'properties.list.scope.public',
                'properties.list.scope.own',
                'properties.list.scope.group',
            ];
            $assignmentWorkflows = [
                'clients.create',
                'clients.update',
                'leads.create',
                'leads.update',
                'deals.create',
                'deals.update',
                'bookings.create',
                'bookings.update.own',
                'bookings.update.all',
                'bookings.agent.assign',
                'properties.create',
                'properties.edit',
                'properties.transfer',
            ];

            $roles = DB::table('roles')->whereNotNull('permissions')->lockForUpdate()->get();
            foreach ($roles as $role) {
                $before = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
                if ($before === [] || in_array('users.agents.directory', $before, true)) {
                    continue;
                }

                $hadLegacyDirectory = in_array('properties.list.internal', $before, true)
                    && array_intersect($listScopes, $before) !== [];
                $usesAssignmentDirectory = array_intersect($assignmentWorkflows, $before) !== [];
                if (! $hadLegacyDirectory && ! $usesAssignmentDirectory) {
                    continue;
                }

                $after = [...$before, 'users.agents.directory'];
                $version = $role->permissions_version + 1;
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode($after, JSON_THROW_ON_ERROR),
                    'permissions_version' => $version,
                    'updated_at' => now(),
                ]);
                DB::table('role_permission_audits')->insert([
                    'role_id' => $role->id,
                    'actor_id' => null,
                    'role_name' => $role->name,
                    'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($after, JSON_THROW_ON_ERROR),
                    'version' => $version,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Permission changes are audited administrative state; reverting code must not rewrite later grants.
    }
};
