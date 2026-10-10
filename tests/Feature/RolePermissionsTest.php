<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\PermissionCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('description')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('role_id');
            $table->string('status')->default('active'); $table->timestamps();
        });
        foreach (Role::systemRoles() as $role) Role::create($role);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
    }

    private function actor(string $slug): User
    {
        $user = User::create(['name' => $slug, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_migration_preserves_defaults_for_every_existing_role(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(app(PermissionCatalog::class)->defaults($role->slug), $role->permissions);
        }
    }

    public function test_catalog_is_described_and_denied_to_non_managers(): void
    {
        $this->actor('agent');
        $this->getJson('/api/rbac')->assertForbidden();
        $this->actor('admin');
        $response = $this->getJson('/api/rbac')->assertOk();
        foreach ($response->json('permissions') as $permission) {
            $this->assertNotEmpty($permission['description']);
            $this->assertNotEmpty($permission['group']);
            $this->assertArrayNotHasKey('roles', $permission);
        }
    }

    public function test_edit_is_audited_and_takes_effect_for_existing_user(): void
    {
        $agent = $this->actor('agent');
        $this->assertFalse($agent->hasPermission('properties.analytics.view'));
        $this->actor('admin');
        $role = $agent->role;
        $grants = [...$role->permissionKeys(), 'properties.analytics.view'];
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => $grants])->assertOk()->assertJsonPath('permissions_version', 2);
        $this->assertTrue($agent->fresh()->hasPermission('properties.analytics.view'));
        $this->assertSame(1, DB::table('role_permission_audits')->count());
        $this->getJson('/api/rbac/roles/'.$role->id.'/history')->assertOk()->assertJsonPath('data.0.version', 2);
    }

    public function test_empty_permissions_revoke_defaults_and_seeding_does_not_reset_them(): void
    {
        $this->actor('superadmin');
        $role = Role::where('slug', 'admin')->firstOrFail();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => []])->assertOk();
        Role::upsertSystemRoles();
        $this->assertFalse($role->fresh()->hasPermission('roles.manage'));
        $this->actor('admin');
        $this->getJson('/api/rbac')->assertForbidden();
        $this->postJson('/api/roles', ['name' => 'Forbidden', 'slug' => 'forbidden'])->assertForbidden();
    }

    public function test_unknown_permissions_and_stale_versions_are_rejected(): void
    {
        $this->actor('admin');
        $role = Role::where('slug', 'agent')->firstOrFail();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['*']])->assertUnprocessable();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 2, 'permissions' => []])->assertConflict();
        $this->assertSame(0, DB::table('role_permission_audits')->count());
    }

    public function test_own_management_access_cannot_be_revoked(): void
    {
        $actor = $this->actor('admin');
        $this->putJson('/api/rbac/roles/'.$actor->role_id, ['version' => 1, 'permissions' => []])->assertUnprocessable();
        $this->assertTrue($actor->fresh()->hasPermission('roles.manage'));
    }

    public function test_custom_role_can_receive_management_without_system_slug(): void
    {
        $role = Role::create(['name' => 'Access manager', 'slug' => 'access_manager']);
        $this->assertSame([], $role->permissionKeys());
        $this->actor('admin');
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['roles.manage']])->assertOk();
        $this->actor('access_manager');
        $this->getJson('/api/rbac')->assertOk();
        $this->postJson('/api/roles', ['name' => 'Custom', 'slug' => 'custom'])->assertCreated();
    }

    public function test_system_slug_is_immutable_and_system_role_is_not_deletable(): void
    {
        $this->actor('admin');
        $role = Role::where('slug', 'agent')->firstOrFail();
        $this->putJson('/api/roles/'.$role->id, ['slug' => 'super_agent'])->assertUnprocessable();
        $this->deleteJson('/api/roles/'.$role->id)->assertConflict();
    }
    public function test_revoking_moderation_blocks_the_action_without_changing_branch_scope(): void
    {
        $actor = $this->actor('branch_director');
        $actor->branch_id = 10;
        $local = new \App\Models\Property(['branch_id' => 10, 'created_by' => 999]);
        $foreign = new \App\Models\Property(['branch_id' => 20, 'created_by' => 999]);
        $access = app(\App\Services\PropertyModeration\PropertyModerationAccess::class);
        $this->assertTrue($access->canModerate($actor, $local));
        $this->assertFalse($access->canModerate($actor, $foreign));
        $role = $actor->role;
        $role->permissions = array_values(array_diff($role->permissionKeys(), ['properties.moderate']));
        $role->save();
        $actor->unsetRelation('role');
        $this->assertFalse($access->canModerate($actor, $local));
        $this->assertFalse($access->canModerate($actor, $foreign));
    }

    public function test_new_role_can_create_properties_and_is_not_implicitly_global(): void
    {
        $role = Role::create(['name' => 'Assistant', 'slug' => 'assistant']);
        $this->actor('admin');
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => ['properties.create', 'properties.edit']])->assertOk();
        $actor = $this->actor('assistant');
        $access = app(\App\Services\PropertyModeration\PropertyModerationAccess::class);
        $this->assertTrue($access->canCreate($actor));
        $this->assertTrue($access->canEdit($actor, new \App\Models\Property(['created_by' => $actor->id])));
        $this->assertFalse($access->canEdit($actor, new \App\Models\Property(['created_by' => 999])));
        $this->assertFalse($access->canModerate($actor, new \App\Models\Property(['created_by' => $actor->id])));
    }

    public function test_legacy_user_managers_cannot_assign_or_modify_newly_elevated_roles(): void
    {
        $actor = $this->actor('hr');
        $agentRole = Role::where('slug', 'agent')->firstOrFail();
        $guard = app(\App\Services\Rbac\RoleAssignmentGuard::class);
        $target = new User(['name' => 'Agent', 'role_id' => $agentRole->id]);
        $guard->ensureCanSave($actor, $target); // Original HR behavior remains valid.
        $agentRole->permissions = [...$agentRole->permissionKeys(), 'roles.manage'];
        $agentRole->save();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $target->save();
    }

    public function test_explicit_management_grant_is_not_blocked_by_legacy_rop_middleware(): void
    {
        $this->actor('admin');
        $role = Role::where('slug', 'rop')->firstOrFail();
        $this->putJson('/api/rbac/roles/'.$role->id, ['version' => 1, 'permissions' => [...$role->permissionKeys(), 'roles.manage']])->assertOk();
        $this->actor('rop');
        $this->postJson('/api/roles', ['name' => 'Assistant', 'slug' => 'assistant'])->assertCreated();
    }

}
