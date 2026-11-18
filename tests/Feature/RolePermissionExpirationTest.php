<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolePermissionExpirationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-18T10:00:00Z'));
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('description')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('role_id');
            $table->string('status')->default('active'); $table->timestamps();
        });
        Role::upsertSystemRoles();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        (require database_path('migrations/2026_11_18_130000_add_role_permission_expirations.php'))->up();
        $this->actor('admin');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function actor(string $slug): User
    {
        $user = User::create(['name' => $slug, 'role_id' => Role::where('slug', $slug)->sole()->id]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_deadline_is_audited_and_enforced_on_loaded_models_sql_and_serialization(): void
    {
        $role = Role::where('slug', 'security')->sole();
        $permission = 'properties.history.published';
        $this->putJson('/api/rbac/roles/'.$role->id, [
            'version' => 1, 'permissions' => [$permission, 'properties.history.view'],
            'permission_expirations' => [$permission => '2026-12-18T15:00:00+05:00'],
        ])->assertOk()->assertJsonFragment(['permission_expirations' => [$permission => '2026-12-18T10:00:00Z']])
            ->assertJsonPath('permissions_version', 2);
        $loaded = $role->fresh();
        $this->assertTrue($loaded->hasPermission($permission));
        $this->assertTrue(Role::withPermission($permission)->whereKey($role->id)->exists());
        $this->travelTo(\Carbon\Carbon::parse('2026-12-18T10:00:00Z'));
        $this->assertFalse($loaded->hasPermission($permission));
        $this->assertNotContains($permission, $loaded->permissionKeys());
        $this->assertNotContains($permission, $loaded->toArray()['permissions']);
        $this->assertTrue($loaded->hasPermission('properties.history.view'));
        $this->assertFalse(Role::withPermission($permission)->whereKey($role->id)->exists());
        $this->assertTrue(Role::withPermission($permission, false)->whereKey($role->id)->exists());
        $catalogRole = collect($this->getJson('/api/rbac')->assertOk()->json('roles'))->firstWhere('id', $role->id);
        $this->assertContains($permission, $catalogRole['permissions']);
        $this->assertSame('2026-12-18T10:00:00Z', $catalogRole['permission_expirations'][$permission]);
        $audit = $this->getJson('/api/rbac/roles/'.$role->id.'/history')->assertOk()->json('data.0');
        $this->assertSame('2026-12-18T10:00:00Z', $audit['after_expirations'][$permission]);
    }

    public function test_expiry_only_edits_are_versioned_and_old_clients_preserve_deadlines(): void
    {
        $role = Role::where('slug', 'agent')->sole();
        $permission = 'properties.create';
        $grants = $role->permissions;
        $put = fn (array $extra) => $this->putJson('/api/rbac/roles/'.$role->id,
            ['version' => $role->fresh()->permissions_version, 'permissions' => $grants, ...$extra]);
        $put(['permission_expirations' => [$permission => '2026-12-01T10:00:00Z']])->assertOk()->assertJsonPath('permissions_version', 2);
        $put([])->assertOk()->assertJsonPath('permissions_version', 2);
        $put(['permission_expirations' => [$permission => '2026-12-02T10:00:00Z']])->assertOk()->assertJsonPath('permissions_version', 3);
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 2, 'permissions' => $grants, 'permission_expirations' => []])->assertConflict();
        $this->travelTo(\Carbon\Carbon::parse('2026-12-03T10:00:00Z'));
        $put([])->assertOk();
        $this->assertFalse($role->fresh()->hasPermission($permission));
        $put(['permission_expirations' => [$permission => '2026-12-05T10:00:00Z']])->assertOk();
        $this->assertTrue($role->fresh()->hasPermission($permission));
        $put(['permission_expirations' => []])->assertOk();
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01T00:00:00Z'));
        $this->assertTrue($role->fresh()->hasPermission($permission));
        $this->assertDatabaseCount('role_permission_audits', 4);
    }

    public function test_invalid_and_unselected_deadlines_and_self_management_expiry_are_rejected(): void
    {
        $role = Role::where('slug', 'agent')->sole();
        foreach (['yesterday', '2026-11-17T10:00:00Z', '2026-11-18T10:00:00Z', '2026-12-18T10:00:00', [], null] as $value) {
            $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['properties.create'],
                'permission_expirations' => ['properties.create' => $value]])->assertUnprocessable();
        }
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => [],
            'permission_expirations' => ['properties.create' => '2026-12-18T10:00:00Z']])->assertUnprocessable();
        $admin = Role::where('slug', 'admin')->sole();
        $this->putJson('/api/rbac/roles/'.$admin->id, ['version' => 1, 'permissions' => $admin->permissions,
            'permission_expirations' => ['roles.manage' => '2026-12-18T10:00:00Z']])->assertUnprocessable();
        $this->assertDatabaseCount('role_permission_audits', 0);
        $this->actor('agent');
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['roles.manage'],
            'permission_expirations' => ['roles.manage' => '2026-12-18T10:00:00Z']])->assertForbidden();
    }

    public function test_sql_matches_model_for_default_explicit_missing_and_expired_grants(): void
    {
        $key = 'attendance.table.view';
        $roles = Role::all();
        foreach ($roles->take(4)->values() as $i => $role) {
            $role->forceFill(['permissions' => $i < 2 ? null : [$key],
                'permission_expirations' => [$key => $i % 2 ? '2026-12-18T10:00:00Z' : '2026-11-18T10:00:00Z']])->save();
        }
        foreach (Role::all() as $role) {
            $this->assertSame($role->hasPermission($key), Role::withPermission($key)->whereKey($role->id)->exists(), $role->slug);
            $this->assertSame(! $role->hasPermission($key), Role::withPermission($key, false)->whereKey($role->id)->exists(), $role->slug);
        }
    }

    public function test_an_existing_manager_session_cannot_edit_after_its_permission_expires(): void
    {
        $admin = Role::where('slug', 'admin')->sole();
        $admin->forceFill(['permission_expirations' => ['roles.manage' => '2026-11-18T11:00:00Z']])->save();
        $this->actor('admin');
        $this->getJson('/api/rbac')->assertOk();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-18T11:00:00Z'));
        $this->getJson('/api/rbac')->assertForbidden();
        $role = Role::where('slug', 'security')->sole();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['roles.manage']])->assertForbidden();
        $this->assertFalse($role->fresh()->hasPermission('roles.manage'));
    }

    public function test_removing_a_grant_cleans_its_deadline_and_audits_the_change(): void
    {
        $role = Role::where('slug', 'agent')->sole();
        $role->forceFill(['permissions' => ['properties.create'],
            'permission_expirations' => ['properties.create' => '2026-12-18T10:00:00Z']])->save();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => []])->assertOk();
        $this->assertSame([], $role->fresh()->permission_expirations);
        $audit = DB::table('role_permission_audits')->sole();
        $this->assertSame(['properties.create' => '2026-12-18T10:00:00Z'], json_decode($audit->before_expirations, true));
        $this->assertSame([], json_decode($audit->after_expirations, true));
    }

    public function test_deleting_a_role_removes_its_assignment_deadline_and_preserves_other_deadlines(): void
    {
        $custom = Role::create(['name' => 'Temporary team', 'slug' => 'temporary_team']);
        $agent = Role::where('slug', 'agent')->sole();
        $key = 'users.assign_role.temporary_team';
        $agent->forceFill(['permissions' => [$key, 'properties.create'],
            'permission_expirations' => [$key => '2026-12-18T10:00:00Z', 'properties.create' => '2026-12-20T10:00:00Z']])->save();
        $this->deleteJson('/api/roles/'.$custom->id)->assertOk();
        $this->assertSame(['properties.create'], $agent->fresh()->permissions);
        $this->assertSame(['properties.create' => '2026-12-20T10:00:00Z'], $agent->fresh()->permission_expirations);
        $audit = DB::table('role_permission_audits')->sole();
        $this->assertArrayHasKey($key, json_decode($audit->before_expirations, true));
        $this->assertArrayNotHasKey($key, json_decode($audit->after_expirations, true));
    }

    public function test_security_migration_limits_only_the_new_grant_to_a_calendar_month_and_does_not_extend_it(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2027-01-31T10:00:00Z'));
        (require database_path('migrations/2026_11_18_120000_allow_security_published_property_history.php'))->up();
        $migration = require database_path('migrations/2026_11_18_140000_limit_security_history_to_one_month.php');
        $others = Role::where('slug', '!=', 'security')->get()->map->getAttributes()->all();
        $migration->up();
        $security = Role::where('slug', 'security')->sole();
        $this->assertSame(['properties.history.published' => '2027-02-28T10:00:00Z'], $security->permission_expirations);
        $this->assertTrue($security->hasPermission('properties.history.published'));
        $this->travelTo(\Carbon\Carbon::parse('2027-02-28T10:00:00Z'));
        $migration->up();
        $migration->down();
        $this->assertFalse($security->fresh()->hasPermission('properties.history.published'));
        $this->assertTrue($security->fresh()->hasPermission('properties.history.view'));
        $this->assertTrue($security->fresh()->hasPermission('properties.closed.view'));
        $this->assertSame($others, Role::where('slug', '!=', 'security')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('role_permission_audits', 2);
        $security->forceFill(['permissions' => [], 'permission_expirations' => []])->save();
        $migration->up();
        $this->assertSame([], $security->fresh()->permissions);
        $this->assertDatabaseCount('role_permission_audits', 2);
    }

    public function test_security_migration_supports_legacy_defaults_and_preserves_an_existing_deadline(): void
    {
        $security = Role::where('slug', 'security')->sole();
        $security->forceFill(['permissions' => null])->save();
        $migration = require database_path('migrations/2026_11_18_140000_limit_security_history_to_one_month.php');
        $migration->up();
        $this->assertTrue($security->fresh()->hasPermission('properties.history.published'));
        $this->assertSame('2026-12-18T10:00:00Z', $security->fresh()->permission_expirations['properties.history.published']);
        $security->forceFill(['permission_expirations' => ['properties.history.published' => '2026-11-20T10:00:00Z']])->save();
        $migration->up();
        $this->assertSame('2026-11-20T10:00:00Z', $security->fresh()->permission_expirations['properties.history.published']);
        $this->assertDatabaseCount('role_permission_audits', 1);
    }
}
