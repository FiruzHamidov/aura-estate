<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceCatalogMergeFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->createSchema();
    }

    public function test_usage_returns_unique_objects_breakdown_and_replacement_options(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $response = $this->getJson(
            "/api/admin/catalogs/property-types/{$context['source']->id}/usage"
        )->assertOk();

        $response
            ->assertJsonPath('data.catalog', 'property-types')
            ->assertJsonPath('data.item.name', 'Старый тип')
            ->assertJsonPath('data.usage.total', 4)
            ->assertJsonPath('data.replacement_required', true)
            ->assertJsonPath('data.can_delete_directly', false)
            ->assertJsonPath('data.merge_allowed', true);

        $breakdown = collect($response->json('data.usage.breakdown'))->keyBy('entity');
        $this->assertSame(2, $breakdown['properties']['count']);
        $this->assertSame(1, $breakdown['external_property_requests']['count']);
        $this->assertSame(1, $breakdown['client_needs']['count']);
        $this->assertSame(
            [$context['replacement']->id],
            collect($response->json('data.replacement_options'))->pluck('id')->all()
        );
    }

    public function test_registry_contains_all_admin_reference_catalogs(): void
    {
        $this->assertSame([
            'property-types',
            'property-statuses',
            'building-types',
            'parking-types',
            'heating-types',
            'repair-types',
            'contract-types',
            'document-types',
            'locations',
            'branches',
            'branch-groups',
            'roles',
            'developers',
            'features',
            'tags',
            'materials',
            'construction-stages',
            'client-types',
            'client-sources',
            'client-need-types',
            'client-need-statuses',
        ], array_keys(config('reference_catalogs')));
    }

    public function test_merge_reassigns_direct_and_pivot_relations_deletes_source_and_writes_audit(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->postJson("/api/admin/catalogs/property-types/{$context['source']->id}/merge", [
            'replacement_id' => $context['replacement']->id,
            'expected_usage_count' => 4,
        ])->assertOk()
            ->assertJsonPath('data.reassigned.total', 4)
            ->assertJsonPath('data.source_deleted', true)
            ->assertJsonPath('data.replacement.id', $context['replacement']->id);

        $this->assertDatabaseMissing('property_types', ['id' => $context['source']->id]);
        $this->assertDatabaseCount('properties', 2);
        $this->assertDatabaseMissing('properties', ['type_id' => $context['source']->id]);
        $this->assertDatabaseMissing('external_property_requests', ['type_id' => $context['source']->id]);
        $this->assertDatabaseHas('client_needs', [
            'id' => $context['needId'],
            'property_type_id' => $context['replacement']->id,
        ]);
        $this->assertDatabaseMissing('client_need_property_type', [
            'client_need_id' => $context['needId'],
            'property_type_id' => $context['source']->id,
        ]);
        $this->assertSame(
            1,
            \DB::table('client_need_property_type')
                ->where('client_need_id', $context['needId'])
                ->where('property_type_id', $context['replacement']->id)
                ->count()
        );
        $this->assertDatabaseHas('reference_catalog_merge_audits', [
            'actor_user_id' => $context['admin']->id,
            'catalog' => 'property-types',
            'source_id' => $context['source']->id,
            'replacement_id' => $context['replacement']->id,
            'reassigned_count' => 4,
        ]);
    }

    public function test_merge_rejects_stale_usage_count_without_changing_data(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->postJson("/api/admin/catalogs/property-types/{$context['source']->id}/merge", [
            'replacement_id' => $context['replacement']->id,
            'expected_usage_count' => 3,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'REFERENCE_USAGE_CHANGED')
            ->assertJsonPath('details.actual_usage_count', 4);

        $this->assertDatabaseHas('property_types', ['id' => $context['source']->id]);
        $this->assertDatabaseHas('properties', ['type_id' => $context['source']->id]);
        $this->assertDatabaseCount('reference_catalog_merge_audits', 0);
    }

    public function test_non_admin_cannot_inspect_or_merge_catalogs(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['agent']);

        $this->getJson("/api/admin/catalogs/property-types/{$context['source']->id}/usage")
            ->assertForbidden()
            ->assertJsonPath('code', 'REFERENCE_CATALOG_FORBIDDEN');

        $this->postJson("/api/admin/catalogs/property-types/{$context['source']->id}/merge", [
            'replacement_id' => $context['replacement']->id,
            'expected_usage_count' => 4,
        ])->assertForbidden()
            ->assertJsonPath('code', 'REFERENCE_CATALOG_FORBIDDEN');
    }

    public function test_source_cannot_be_used_as_its_own_replacement(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->postJson("/api/admin/catalogs/property-types/{$context['source']->id}/merge", [
            'replacement_id' => $context['source']->id,
            'expected_usage_count' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'REFERENCE_REPLACEMENT_SAME_AS_SOURCE');
    }

    public function test_system_role_is_marked_as_protected_and_cannot_be_merged(): void
    {
        $context = $this->context();
        $customRole = Role::query()->create([
            'name' => 'Временная роль',
            'slug' => 'temporary_role',
        ]);
        Sanctum::actingAs($context['admin']);

        $this->getJson("/api/admin/catalogs/roles/{$context['adminRole']->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.merge_allowed', false)
            ->assertJsonPath('data.replacement_options', []);

        $this->postJson("/api/admin/catalogs/roles/{$context['adminRole']->id}/merge", [
            'replacement_id' => $customRole->id,
            'expected_usage_count' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'REFERENCE_CATALOG_ITEM_PROTECTED');
    }

    public function test_generic_delete_only_deletes_an_unused_catalog_item(): void
    {
        $context = $this->context();
        $unusedId = \DB::table('property_types')->insertGetId([
            'name' => 'Неиспользуемый тип',
            'slug' => 'unused-type',
        ]);
        Sanctum::actingAs($context['admin']);

        $this->deleteJson("/api/admin/catalogs/property-types/{$unusedId}")
            ->assertOk()
            ->assertJsonPath('data.source_deleted', true);

        $this->assertDatabaseMissing('property_types', ['id' => $unusedId]);
    }

    public function test_generic_delete_rechecks_usage_and_rejects_an_item_in_use(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->deleteJson("/api/admin/catalogs/property-types/{$context['source']->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REFERENCE_CATALOG_IN_USE')
            ->assertJsonPath('details.usage.total', 4);

        $this->assertDatabaseHas('property_types', ['id' => $context['source']->id]);
    }

    private function context(): array
    {
        $adminRole = Role::query()->create(['name' => 'Администратор', 'slug' => 'admin']);
        $agentRole = Role::query()->create(['name' => 'Агент', 'slug' => 'agent']);
        $admin = $this->user($adminRole, 'Admin');
        $agent = $this->user($agentRole, 'Agent');
        $source = \DB::table('property_types')->insertGetId([
            'name' => 'Старый тип',
            'slug' => 'old-type',
        ]);
        $replacement = \DB::table('property_types')->insertGetId([
            'name' => 'Квартира',
            'slug' => 'apartment',
        ]);
        \DB::table('properties')->insert([
            ['title' => 'Объект 1', 'type_id' => $source],
            ['title' => 'Объект 2', 'type_id' => $source],
        ]);
        \DB::table('external_property_requests')->insert([
            'type_id' => $source,
        ]);
        $needId = \DB::table('client_needs')->insertGetId([
            'property_type_id' => $source,
        ]);
        \DB::table('client_need_property_type')->insert([
            ['client_need_id' => $needId, 'property_type_id' => $source],
            ['client_need_id' => $needId, 'property_type_id' => $replacement],
        ]);

        return [
            'adminRole' => $adminRole,
            'admin' => $admin,
            'agent' => $agent,
            'source' => (object) ['id' => $source],
            'replacement' => (object) ['id' => $replacement],
            'needId' => $needId,
        ];
    }

    private function user(Role $role, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->foreignId('role_id');
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->timestamps();
        });
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('type_id');
        });
        Schema::create('external_property_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id')->nullable();
        });
        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_type_id')->nullable();
        });
        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_need_id');
            $table->unsignedBigInteger('property_type_id');
            $table->unique(['client_need_id', 'property_type_id']);
        });
        $auditMigration = require database_path('migrations/2026_07_29_000003_create_reference_catalog_merge_audits_table.php');
        $auditMigration->up();
    }
}
