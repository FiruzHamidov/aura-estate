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

    public function test_lead_scope_changes_are_effective_and_never_grant_mutation_implicitly(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable(); $table->softDeletes();
        });
        $actor = $this->actor('agent');
        $actor->branch_id = 10;
        DB::table('leads')->insert([
            ['id' => 1, 'branch_id' => 10, 'created_by' => $actor->id, 'responsible_agent_id' => null],
            ['id' => 2, 'branch_id' => 10, 'created_by' => 999, 'responsible_agent_id' => null],
            ['id' => 3, 'branch_id' => 20, 'created_by' => $actor->id, 'responsible_agent_id' => null],
        ]);
        $access = app(\App\Support\LeadAccess::class);
        $this->assertSame([1], $access->visibleQuery($actor)->pluck('id')->all());
        $role = $actor->role;
        $role->permissions = ['leads.scope.branch'];
        $role->save();
        $this->assertSame([1, 2], $access->visibleQuery($actor)->pluck('id')->all());
        $role->permissions = ['leads.scope.all'];
        $role->save();
        $this->assertSame([1, 2, 3], $access->visibleQuery($actor)->pluck('id')->all());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $access->ensureAction($actor, 'update');
    }

    public function test_conflicting_data_scopes_are_rejected(): void
    {
        $this->actor('admin');
        $role = Role::where('slug', 'agent')->firstOrFail();
        $this->putJson('/api/rbac/roles/'.$role->id, [
            'version' => 1, 'permissions' => ['leads.scope.all', 'leads.scope.own'],
        ])->assertUnprocessable();
    }

    public function test_deal_and_pipeline_scopes_are_independent_of_mutations(): void
    {
        Schema::create('crm_deal_pipelines', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('pipeline_id'); $table->softDeletes();
        });
        DB::table('crm_deal_pipelines')->insert([
            ['id' => 1, 'branch_id' => 10, 'code' => null, 'is_active' => true],
            ['id' => 2, 'branch_id' => 20, 'code' => null, 'is_active' => true],
            ['id' => 3, 'branch_id' => null, 'code' => 'hr_recruitment', 'is_active' => true],
            ['id' => 4, 'branch_id' => null, 'code' => 'property_control', 'is_active' => true],
            ['id' => 5, 'branch_id' => null, 'code' => 'property_control', 'is_active' => false],
        ]);
        $actor = $this->actor('operator');
        $actor->branch_id = 10;
        DB::table('crm_deals')->insert([
            ['id' => 1, 'branch_id' => 10, 'created_by' => $actor->id, 'pipeline_id' => 1],
            ['id' => 2, 'branch_id' => 10, 'created_by' => 999, 'pipeline_id' => 1],
            ['id' => 3, 'branch_id' => 20, 'created_by' => $actor->id, 'pipeline_id' => 2],
            ['id' => 4, 'branch_id' => null, 'created_by' => 999, 'pipeline_id' => 3],
            ['id' => 5, 'branch_id' => 20, 'created_by' => 999, 'pipeline_id' => 4],
            ['id' => 6, 'branch_id' => 20, 'created_by' => 999, 'pipeline_id' => 5],
        ]);
        $deals = app(\App\Support\DealAccess::class);
        $pipelines = app(\App\Support\DealPipelineAccess::class);
        foreach (['own' => [1], 'branch' => [1, 2], 'all' => [1, 2, 3, 4, 5, 6], 'hr' => [4], 'control' => [5], 'none' => []] as $scope => $ids) {
            $actor->role->permissions = $scope === 'none' ? [] : ['deals.scope.'.$scope];
            $this->assertSame($ids, $deals->visibleQuery($actor)->orderBy('id')->pluck('id')->all(), $scope);
        }
        foreach (['branch' => [1, 3, 4, 5], 'all' => [1, 2, 3, 4, 5], 'hr' => [3], 'control' => [4], 'none' => []] as $scope => $ids) {
            $actor->role->permissions = $scope === 'none' ? [] : ['pipelines.scope.'.$scope];
            $this->assertSame($ids, $pipelines->visibleQuery($actor)->orderBy('id')->pluck('id')->all(), $scope);
            $this->assertFalse($pipelines->canManage($actor));
        }
        $actor->role->permissions = ['deals.scope.all'];
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $deals->ensureCanCreate($actor);
    }

    public function test_crm_grant_works_for_formerly_excluded_roles_without_opening_other_modules(): void
    {
        $actor = $this->actor('client');
        $actor->role->permissions = ['leads.scope.own'];
        $middleware = app(\App\Http\Middleware\EnsureUserIsNotClient::class);
        foreach (['/api/leads' => 200, '/api/leads/1/convert' => 200, '/api/deals' => 403, '/api/user' => 403] as $path => $status) {
            $request = \Illuminate\Http\Request::create($path);
            $request->setUserResolver(fn () => $actor);
            $this->assertSame($status, $middleware->handle($request, fn () => response('ok'))->getStatusCode(), $path);
        }
    }

    public function test_existing_custom_role_keeps_shared_contacts_but_new_role_starts_empty(): void
    {
        $migration = require database_path('migrations/2026_10_10_000000_add_role_permissions.php');
        $migration->down();
        $legacy = Role::create(['name' => 'Legacy colleague', 'slug' => 'legacy_colleague']);
        $migration->up();
        $this->assertSame(['clients.shared.view', 'clients.attach', 'clients.duplicates.search'], $legacy->fresh()->permissionKeys());
        $new = Role::create(['name' => 'New colleague', 'slug' => 'new_colleague']);
        $this->assertSame([], $new->permissionKeys());
    }

    public function test_client_scope_and_shared_access_can_be_changed_independently(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable(); $table->softDeletes();
        });
        Schema::create('client_collaborators', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id'); $table->unsignedBigInteger('user_id');
        });
        $actor = $this->actor('intern');
        $actor->branch_id = 10;
        DB::table('clients')->insert([
            ['id' => 1, 'branch_id' => 10, 'created_by' => $actor->id],
            ['id' => 2, 'branch_id' => 10, 'created_by' => 999],
            ['id' => 3, 'branch_id' => 20, 'created_by' => 999],
            ['id' => 4, 'branch_id' => 20, 'created_by' => 999],
        ]);
        DB::table('client_collaborators')->insert(['client_id' => 3, 'user_id' => $actor->id]);
        $access = app(\App\Support\ClientAccess::class);
        foreach ([
            [['clients.scope.own'], [1]],
            [['clients.scope.branch'], [1, 2]],
            [['clients.scope.branch', 'clients.shared.view'], [1, 2, 3]],
            [['clients.shared.view'], [3]],
            [['clients.scope.all'], [1, 2, 3, 4]],
            [[], []],
        ] as [$permissions, $ids]) {
            $actor->role->permissions = $permissions;
            $this->assertSame($ids, $access->visibleQuery($actor)->orderBy('id')->pluck('id')->all());
        }
        $actor->role->permissions = ['clients.scope.all'];
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        $access->ensureCanMutateClients($actor, 'clients.update');
    }

}
