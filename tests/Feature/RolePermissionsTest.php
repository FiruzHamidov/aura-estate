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

    public function test_responsible_picker_defaults_preserve_existing_role_behavior(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role->slug, ['agent', 'intern'], true), $role->hasPermission('clients.responsible_picker.exclude_self'), $role->slug);
            $this->assertSame($role->slug === 'agent', $role->hasPermission('client_needs.responsible_picker.exclude_self'), $role->slug);
        }
        $this->actor('superadmin');
        $target = Role::create(['name' => 'Custom picker', 'slug' => 'custom_picker', 'permissions' => []]);
        $this->putJson('/api/rbac/roles/'.$target->id, ['version' => $target->fresh()->permissions_version,
            'permissions' => ['clients.responsible_picker.exclude_self']])->assertOk();
        $this->assertTrue($target->fresh()->hasPermission('clients.responsible_picker.exclude_self'));
        $this->assertFalse($target->fresh()->hasPermission('client_needs.responsible_picker.exclude_self'));
    }

    public function test_mobile_navigation_preserves_default_layouts_and_rejects_multiple_choices(): void
    {
        foreach (['agent' => 'sales', 'intern' => 'personal', 'manager' => 'deals', 'operator' => 'leads',
            'rop' => 'team', 'branch_director' => 'team', 'admin' => 'management', 'superadmin' => 'management',
            'marketing' => 'management', 'reels_manager' => 'media', 'accountant' => 'attendance'] as $slug => $layout) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $this->assertTrue($role->hasPermission('navigation.mobile.'.$layout), $slug);
        }
        $this->actor('superadmin');
        $target = Role::create(['name' => 'Custom menu', 'slug' => 'custom_mobile_menu', 'permissions' => []]);
        $this->putJson('/api/rbac/roles/'.$target->id, ['version' => $target->fresh()->permissions_version,
            'permissions' => ['navigation.mobile.sales', 'navigation.mobile.team']])->assertUnprocessable()->assertJsonPath('details.errors.permissions.0', 'Для одного раздела можно выбрать только одну область данных.');
        $this->putJson('/api/rbac/roles/'.$target->id, ['version' => $target->fresh()->permissions_version,
            'permissions' => ['navigation.mobile.team']])->assertOk();
        $this->assertTrue($target->fresh()->hasPermission('navigation.mobile.team'));
    }

    public function test_role_catalog_read_grant_works_independently_of_role_name_and_management(): void
    {
        $target = Role::where('slug', 'agent')->firstOrFail();
        Role::create(['name' => 'Custom reader', 'slug' => 'custom_reader']);
        foreach (['client', 'external_agent', 'custom_reader', 'hr'] as $slug) {
            $actor = $this->actor($slug);
            $role = $actor->role;
            $role->forceFill(['permissions' => ['roles.view']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/roles')->assertOk();
            $this->getJson('/api/roles/'.$target->id)->assertOk()->assertJsonPath('slug', 'agent');
            $this->getJson('/api/rbac')->assertForbidden();
            $this->postJson('/api/roles', ['name' => 'Forbidden', 'slug' => 'forbidden'])->assertForbidden();
            $this->putJson('/api/roles/'.$target->id, ['name' => 'Forbidden'])->assertForbidden();
            $role->forceFill(['permissions' => []])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/roles')->assertForbidden();
            $this->getJson('/api/roles/'.$target->id)->assertForbidden();
            $role->forceFill(['permissions' => ['roles.manage']])->save();
            Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/roles')->assertOk();
        }
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

    public function test_owner_search_defaults_preserve_all_existing_non_client_roles(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame($role->slug !== 'client', $role->hasPermission('properties.search.owner'), $role->slug);
        }
        $catalog = app(PermissionCatalog::class);
        $this->assertContains('properties.search.owner', $catalog->migrationDefaults('legacy_custom'));
        $this->assertNotContains('properties.search.owner', $catalog->defaults('new_custom'));
    }

    public function test_monthly_kpi_banner_defaults_preserve_agent_and_mop_visibility(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role->slug, ['agent', 'mop'], true), $role->hasPermission('kpi.monthly.site.banner'), $role->slug);
        }
        $this->assertNotContains('kpi.monthly.site.banner', app(PermissionCatalog::class)->migrationDefaults('legacy_custom'));
    }

    public function test_owner_keeps_legacy_superadmin_website_controls_without_extra_api_grants(): void
    {
        $catalog = app(PermissionCatalog::class);
        $owner = $catalog->defaults('owner');
        foreach ([
            'users.site.mutate',
            'users.site.admin_targets',
            'users.site.dismiss_clients',
            'kpi.plans.site.view',
            'kpi.plans.site.menu',
            'kpi.reports.site.view',
            'kpi.adjustments.site.view',
            'kpi.rop_plans.site.view',
            'kpi.rop_plans.site.apply',
            'kpi.operations.site.view',
            'motivation.site.view',
            'motivation.site.manage',
            'properties.site.own',
            'properties.menu.own',
            'properties.site.all',
            'properties.menu.all',
            'bookings.property.site.create',
            'properties.internal_fields.site',
            'properties.media.download.site',
            'properties.matching.site.all',
            'properties.contacts.site.all',
            'properties.reports.site',
            'liquidity.site.view',
            'liquidity.site.report',
            'property_reports.site.view',
            'property_reports.site.monthly_menu',
            'property_reports.site.branch_filter',
            'property_reports.site.agent_filter',
            'property_reports.site.economics',
            'clients.site.cash_million',
            'clients.site.cash_midrange',
] as $permission) $this->assertContains($permission, $owner);
        foreach (['roles.manage', 'users.update', 'properties.moderate', 'users.manage_all_roles'] as $permission) {
            $this->assertNotContains($permission, $owner);
        }
    }

    public function test_learning_defaults_preserve_role_materials(): void
    {
        foreach (Role::all() as $role) {
            $this->assertTrue($role->hasPermission('learning.site.view'));
            $this->assertSame($role->slug !== 'client', $role->hasPermission('learning.site.videos'), $role->slug);
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'agent', 'intern', 'manager', 'operator', 'mop', 'rop', 'branch_director', 'external_agent'], true), $role->hasPermission('learning.guides.crm-sales'), $role->slug);
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'marketing', 'reels_manager', 'hr', 'accountant'], true), $role->hasPermission('learning.guides.admin-content'), $role->slug);
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'client'], true), $role->hasPermission('learning.guides.client'), $role->slug);
        }
        $catalog = app(PermissionCatalog::class);
        foreach (['legacy_custom'] as $slug) {
            $this->assertContains('learning.site.view', $catalog->migrationDefaults($slug));
            $this->assertContains('learning.guides.client', $catalog->migrationDefaults($slug));
            $this->assertNotContains('learning.site.videos', $catalog->migrationDefaults($slug));
        }
        $this->assertNotContains('learning.site.view', $catalog->defaults('new_custom'));
        foreach (['learning.site.view', 'learning.site.videos', 'learning.guides.crm-sales', 'learning.guides.admin-content', 'learning.guides.client', 'profile.details.site', 'profile.security.site'] as $permission) {
            $this->assertContains($permission, $catalog->defaults('owner'));
        }
    }

    public function test_control_mode_is_enabled_only_for_existing_security_role(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame($role->slug === 'security', $role->hasPermission('deals.site.control'), $role->slug);
        }
        $catalog = app(PermissionCatalog::class);
        $this->assertNotContains('deals.site.control', $catalog->migrationDefaults('legacy_custom'));
    }

    public function test_client_matching_defaults_preserve_existing_access(): void
    {
        $catalog = app(PermissionCatalog::class);
        foreach ([...array_column(Role::systemRoles(), 'slug'), 'owner'] as $slug) {
            $defaults = $catalog->defaults($slug);
            $this->assertSame(!in_array($slug, ['client', 'external_agent', 'accountant'], true), in_array('clients.matching.view', $defaults, true), $slug);
            $this->assertSame($slug !== 'client', in_array('clients.matching.site', $defaults, true), $slug);
        }
        foreach (['clients.matching.view', 'clients.matching.site'] as $permission) {
            $this->assertContains($permission, $catalog->migrationDefaults('legacy_custom'));
            $this->assertNotContains($permission, $catalog->defaults('new_custom'));
        }
    }

    public function test_property_contact_defaults_preserve_separate_payload_and_website_access(): void
    {
        foreach (Role::all() as $role) {
            $this->assertTrue($role->hasPermission('properties.contacts.view'), $role->slug);
            $this->assertSame(in_array($role->slug, ['manager', 'admin', 'superadmin', 'marketing', 'rop', 'branch_director', 'security'], true), $role->hasPermission('properties.contacts.site.all'), $role->slug);
            $this->assertSame(in_array($role->slug, ['agent', 'mop'], true), $role->hasPermission('properties.contacts.site.own'), $role->slug);
        }
        $catalog = app(PermissionCatalog::class);
        $this->assertContains('properties.contacts.view', $catalog->migrationDefaults('legacy_custom'));
        $this->assertNotContains('properties.contacts.view', $catalog->defaults('new_custom'));
    }

    public function test_property_card_action_defaults_preserve_existing_website_roles(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'marketing', 'agent', 'mop'], true), $role->hasPermission('bookings.property.site.create'), $role->slug);
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'marketing', 'security'], true), $role->hasPermission('properties.internal_fields.site'), $role->slug);
            $this->assertSame(!in_array($role->slug, ['client', 'owner'], true), $role->hasPermission('properties.media.download.site'), $role->slug);
            $this->assertSame(!in_array($role->slug, ['client', 'external_agent', 'accountant'], true), $role->hasPermission('properties.matching.view'), $role->slug);
            $this->assertSame($role->slug === 'agent', $role->hasPermission('properties.matching.own_clients'), $role->slug);
            $this->assertSame(in_array($role->slug, ['manager', 'admin', 'superadmin', 'marketing', 'rop', 'branch_director'], true), $role->hasPermission('properties.matching.site.all'), $role->slug);
            $this->assertSame(in_array($role->slug, ['agent', 'mop'], true), $role->hasPermission('properties.matching.site.own'), $role->slug);
        }
        $this->assertContains('properties.matching.view', app(PermissionCatalog::class)->migrationDefaults('legacy_custom'));
    }

    public function test_internal_property_list_defaults_preserve_the_legacy_role_gate(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(!in_array($role->slug, ['client', 'external_agent'], true), $role->hasPermission('properties.list.internal'), $role->slug);
        }
        $catalog = app(PermissionCatalog::class);
        $this->assertContains('properties.list.internal', $catalog->migrationDefaults('legacy_custom'));
        $this->assertNotContains('properties.list.internal', $catalog->defaults('new_custom'));
    }

    public function test_property_write_group_restriction_defaults_only_to_mop(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame($role->slug === 'mop', $role->hasPermission('properties.write.own_group'), $role->slug);
        }
    }

    public function test_object_report_filter_defaults_preserve_existing_role_scopes(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role->slug, ['rop', 'branch_director'], true), $role->hasPermission('properties.reports.own_branch'), $role->slug);
            $this->assertSame($role->slug === 'mop', $role->hasPermission('properties.reports.own_group'), $role->slug);
        }
    }

    public function test_booking_editor_stays_disabled_after_migration_and_can_be_enabled_explicitly(): void
    {
        foreach (Role::all() as $role) {
            $this->assertFalse($role->hasPermission('bookings.site.edit'), $role->slug);
        }
        $this->actor('superadmin');
        $role = Role::where('slug', 'agent')->firstOrFail();
        $this->assertTrue($role->hasPermission('bookings.update.own'));
        $this->putJson('/api/rbac/roles/'.$role->id, [
            'version' => 1,
            'permissions' => [...$role->permissionKeys(), 'bookings.site.edit'],
        ])->assertOk();
        $this->assertTrue($role->fresh()->hasPermission('bookings.site.edit'));
    }

    public function test_user_website_defaults_preserve_restrictions_beyond_api_permissions(): void
    {
        foreach (['agent', 'mop'] as $slug) {
            $role = Role::where('slug', $slug)->firstOrFail();
            $this->assertTrue($role->hasPermission('users.update'));
            $this->assertFalse($role->hasPermission('users.site.mutate'));
        }
        $marketing = Role::where('slug', 'marketing')->firstOrFail();
        $this->assertTrue($marketing->hasPermission('users.site.mutate'));
        $this->assertFalse($marketing->hasPermission('users.site.admin_targets'));
        $this->assertTrue($marketing->hasPermission('users.site.dismiss_clients'));
        $director = Role::where('slug', 'branch_director')->firstOrFail();
        $this->assertTrue($director->hasPermission('users.site.mutate'));
        $this->assertFalse($director->hasPermission('users.site.dismiss_clients'));
    }

    public function test_daily_report_period_exceptions_preserve_historical_role_defaults(): void
    {
        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role->slug, ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], true),
                $role->hasPermission('daily_reports.finalized.edit'), $role->slug);
            $this->assertSame(! in_array($role->slug, ['agent', 'intern', 'mop'], true),
                $role->hasPermission('daily_reports.locked_period.override'), $role->slug);
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
        $this->assertSame(app(PermissionCatalog::class)->migrationDefaults('legacy_colleague'), $legacy->fresh()->permissionKeys());
        $this->assertTrue($legacy->fresh()->hasPermission('clients.shared.view'));
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

    public function test_custom_roles_get_individual_assign_permissions_and_deleted_roles_lose_them(): void
    {
        $this->actor('admin');
        $custom = Role::create(['name' => 'Coordinator', 'slug' => 'coordinator']);
        $agent = Role::where('slug', 'agent')->firstOrFail();
        $key = 'users.assign_role.coordinator';
        $keys = [$key, 'kpi.reports.dashboard.role.coordinator'];
        $this->assertContains($key, array_column($this->getJson('/api/rbac')->assertOk()->json('permissions'), 'key'));
        $this->putJson('/api/rbac/roles/'.$agent->id, ['version' => 1, 'permissions' => $keys])->assertOk();
        foreach ($keys as $permission) $this->assertTrue($agent->fresh()->hasPermission($permission));
        $this->putJson('/api/rbac/roles/'.$agent->id, ['version' => 2, 'permissions' => [...$keys, 'kpi.reports.dashboard.role.agent']])->assertUnprocessable();
        $this->assertFalse($agent->fresh()->hasPermission('users.assign_any_role'));
        $this->deleteJson('/api/roles/'.$custom->id)->assertOk();
        $this->assertFalse($agent->fresh()->hasPermission($key));
        $this->assertSame([], $agent->fresh()->permissions);
        $this->assertSame(3, $agent->fresh()->permissions_version);
        Role::create(['name' => 'New coordinator', 'slug' => 'coordinator']);
        $this->assertFalse($agent->fresh()->hasPermission($key));
        $data = collect($this->getJson('/api/rbac')->assertOk()->json('roles'))->firstWhere('id', $agent->id);
        $this->assertSame([], $data['permissions']);
    }

    public function test_user_listing_scopes_and_mutation_grants_are_separate(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable());
        $actor = $this->actor('agent');
        $actor->branch_id = 10;
        DB::table('users')->where('id', $actor->id)->update(['branch_id' => 10]);
        $agentRole = $actor->role_id;
        foreach ([10, 20, null] as $index => $branch) {
            DB::table('users')->insert(['name' => 'U'.$index, 'role_id' => $agentRole, 'branch_id' => $branch]);
        }
        $controller = app(\App\Http\Controllers\UserController::class);
        $query = new \ReflectionMethod($controller, 'visibleUsersQuery');
        foreach ([
            [['users.scope.branch'], [1, 2]],
            [['users.scope.all'], [1, 2, 3, 4]],
            [[], []],
        ] as [$permissions, $ids]) {
            $actor->role->permissions = $permissions;
            $this->assertSame($ids, $query->invoke($controller, $actor)->orderBy('id')->pluck('id')->all());
        }
        $actor->role->permissions = ['users.scope.all', 'users.manage_all_roles'];
        $authorize = new \ReflectionMethod($controller, 'authorizeUserMutation');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $authorize->invoke($controller, $actor, $actor, 'update');
    }

    public function test_employee_transfer_permissions_work_for_custom_roles_and_keep_branch_scope(): void
    {
        Role::create(['name' => 'Transfer operator', 'slug' => 'transfer_operator']);
        $actor = $this->actor('transfer_operator');
        $actor->branch_id = 10;
        $target = new User(['name' => 'Agent', 'role_id' => Role::where('slug', 'agent')->value('id')]);
        $target->branch_id = 20;
        $service = app(\App\Services\GroupAccess\EmployeeGroupTransfer::class);
        $authorize = new \ReflectionMethod($service, 'authorize');
        foreach ([[[], 403], [['users.group_transfer'], 404]] as [$permissions, $status]) {
            $actor->role->permissions = $permissions;
            try {
                $authorize->invoke($service, $actor, $target);
                $this->fail('Transfer must be denied');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
        $actor->role->permissions = ['users.group_transfer', 'users.group_transfer.all_branches'];
        $authorize->invoke($service, $actor, $target);
        $this->assertTrue(true);
        // Employee transfer does not implicitly grant independent record transfer.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\GroupAccess\GroupRecordTransfer::class)->authorize($actor, new \App\Models\Property);
    }

}
