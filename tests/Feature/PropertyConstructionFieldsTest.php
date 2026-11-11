<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyConstructionFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
            $table->string('district')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable()->index();
            $table->unsignedBigInteger('document_type_id')->nullable()->index();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->decimal('effective_price', 15, 2)
                ->storedAs('COALESCE(NULLIF(discount_price, 0), price)')
                ->index();
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->string('youtube_link')->nullable();
            $table->string('instagram_link', 2048)->nullable();
            $table->float('total_area')->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->float('living_area')->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->integer('year_built')->nullable();
            $table->string('condition')->nullable();
            $table->string('construction_status')->nullable();
            $table->string('renovation_permission_status')->nullable();
            $table->string('apartment_type')->nullable();
            $table->boolean('has_garden')->default(false);
            $table->boolean('has_parking')->default(false);
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->boolean('is_full_apartment')->default(false);
            $table->string('moderation_status')->default('pending');
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('listing_type')->default('regular');
            $table->unsignedBigInteger('views_count')->default(0);
            $table->string('owner_name')->nullable();
            $table->string('object_key')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->text('status_comment')->nullable();
            $table->timestamp('listing_updated_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('feature_property', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('feature_id');
            $table->timestamps();
            $table->unique(['property_id', 'feature_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('property_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();
            $table->unique(['property_id', 'tag_id']);
        });

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->string('type')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency', 3)->nullable();
            $table->dateTime('agent_paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_10_090000_create_property_analytics_daily.php'))->up();
        (require database_path('migrations/2026_10_19_090000_add_non_client_property_views.php'))->up();
        (require database_path('migrations/2026_10_31_100000_add_bathroom_type_to_properties_table.php'))->up();
        (require database_path('migrations/2026_11_05_100000_add_owner_price_to_properties.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_property_creation_grant_overrides_legacy_client_partner_and_accountant_gate(): void
    {
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        foreach (['client', 'external_agent', 'accountant'] as $index => $slug) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $user = User::create(['name' => $slug, 'phone' => '93000008'.$index, 'role_id' => $role->id, 'status' => 'active']);
            $payload = ['type_id' => $type->id, 'price' => 500000 + $index * 500000, 'currency' => 'TJS', 'offer_type' => 'sale'];
            Sanctum::actingAs($user->fresh());
            $this->postJson('/api/properties', $payload)->assertForbidden();
            $role->forceFill(['permissions' => ['properties.create']])->save();
            Sanctum::actingAs($user->fresh());
            $this->postJson('/api/properties', $payload)->assertOk();
            $created = \App\Models\Property::query()->where('created_by', $user->id)->sole();
            $this->assertSame($user->id, $created->agent_id);
            // Passing the outer gate never replaces the edit check or enables unrelated endpoints.
            $this->putJson('/api/properties/'.$created->id, ['title' => 'Forbidden'])->assertForbidden();
            $this->postJson('/api/roles', ['name' => 'Unexpected', 'slug' => 'unexpected'])->assertForbidden();
            $role->forceFill(['permissions' => ['properties.edit', 'properties.history.view']])->save();
            Sanctum::actingAs($user->fresh());
            $this->putJson('/api/properties/'.$created->id, ['title' => 'Allowed edit', 'version' => $created->moderation_version])->assertOk();
            $this->getJson('/api/properties/'.$created->id.'/logs')->assertOk();
            $this->getJson('/api/property-moderation-cases')->assertForbidden();
            $role->forceFill(['permissions' => ['properties.history.view', 'properties.moderate']])->save();
            Sanctum::actingAs($user->fresh());
            $this->getJson('/api/property-moderation-cases')->assertOk()->assertJsonCount(0, 'data');
            // A history grant alone cannot read this pending object without edit access.
            $this->getJson('/api/properties/'.$created->id.'/logs')->assertForbidden();

            $role->forceFill(['permissions' => []])->save();
            Sanctum::actingAs($user->fresh());
            $this->postJson('/api/properties', $payload)->assertForbidden();
        }
        $this->assertDatabaseCount('properties', 3);
    }

    private function buildingTypeFixture(): array
    {
        Schema::create('building_types', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->timestamps(); });
        \Illuminate\Support\Facades\DB::table('building_types')->insert([['id' => 2, 'name' => 'Кирпичный'], ['id' => 3, 'name' => 'Монолитный']]);
        \Illuminate\Support\Facades\DB::table('property_statuses')->insert([['id' => 2, 'name' => 'Продан', 'slug' => 'sold'], ['id' => 3, 'name' => 'Арендован', 'slug' => 'rented']]);
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        (require database_path('migrations/2026_10_31_160000_separate_property_building_type.php'))->up();
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $actor = User::create(['name' => 'Agent', 'phone' => '930009999', 'role_id' => $role->id, 'status' => 'active']);
        Sanctum::actingAs($actor);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        return ['type_id' => $type->id, 'price' => 150000, 'currency' => 'TJS', 'offer_type' => 'sale'];
    }

    public function test_building_ids_matching_closed_statuses_do_not_close_new_listings(): void
    {
        $payload = $this->buildingTypeFixture();
        foreach ([2, 3] as $buildingType) {
            $response = $this->postJson('/api/properties', [...$payload, 'building_type_id' => $buildingType])->assertOk()
                ->assertJsonPath('status_id', null)->assertJsonPath('building_type_id', $buildingType)->assertJsonPath('building_type_source', 'confirmed');
            $property = \App\Models\Property::findOrFail($response->json('id'));
            $this->assertFalse($property->requiresExplicitReopening());
            $this->assertSame($buildingType, $property->buildingType->id);
            $this->assertNotNull($property->building_type_resolved_at);
        }
        $this->postJson('/api/properties', [...$payload, 'building_type_id' => 999])->assertUnprocessable();
        $this->postJson('/api/properties', [...$payload, 'status_id' => 2])->assertUnprocessable();
        $this->postJson('/api/properties', [...$payload, 'building_type_resolved_at' => now()->toIso8601String()])->assertUnprocessable();
        \Illuminate\Support\Facades\DB::table('property_statuses')->insert(['id' => 1, 'name' => 'Доступен', 'slug' => 'available']);
        $this->postJson('/api/properties', [...$payload, 'status_id' => 1])->assertOk()
            ->assertJsonPath('status_id', 1)->assertJsonPath('building_type_id', null)->assertJsonPath('building_type_source', 'confirmed');
    }

    public function test_legacy_echo_preserves_closed_status_and_never_promotes_old_type_to_verified(): void
    {
        $payload = $this->buildingTypeFixture();
        $created = $this->postJson('/api/properties', $payload)->assertOk();
        $id = $created->json('id');
        \Illuminate\Support\Facades\DB::table('properties')->where('id', $id)->update(['status_id' => 3, 'building_type_id' => null,
            'building_type_resolved_at' => null, 'moderation_status' => 'approved', 'publication_status' => 'published']);
        $property = \App\Models\Property::findOrFail($id);
        $presented = app(\App\Services\PropertyBuildingType::class)->present($property, []);
        $this->assertSame('legacy_unverified', $presented['building_type_source']);
        $this->assertNull($presented['building_type_id']);
        $this->assertSame('Монолитный', $presented['building_type']['name']);
        $response = $this->putJson('/api/properties/'.$id, ['version' => $property->moderation_version, 'status_id' => 3, 'description' => 'Уточнено описание'])->assertOk();
        $this->assertDatabaseHas('properties', ['id' => $id, 'status_id' => 3, 'building_type_id' => null]);
        $this->putJson('/api/properties/'.$id, ['version' => $response->json('moderation_version'), 'status_id' => null])->assertUnprocessable();
        $this->assertTrue($property->fresh()->requiresExplicitReopening());
    }

    public function test_verified_building_type_can_be_cleared_without_legacy_fallback(): void
    {
        $payload = $this->buildingTypeFixture();
        $created = $this->postJson('/api/properties', [...$payload, 'building_type_id' => 2])->assertOk();
        $id = $created->json('id');
        $this->putJson('/api/properties/'.$id, ['version' => $created->json('moderation_version'), 'building_type_id' => null])->assertOk()
            ->assertJsonPath('building_type_id', null)->assertJsonPath('building_type', null)->assertJsonPath('building_type_source', 'confirmed');
        $this->assertDatabaseHas('properties', ['id' => $id, 'status_id' => null, 'building_type_id' => null]);
    }

    public static function approvedBuildingSnapshots(): array
    {
        return [['legacy', null, null], ['confirmed', 2, '2026-10-01 00:00:00'], ['cleared', null, '2026-10-01 00:00:00']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('approvedBuildingSnapshots')]
    public function test_withdraw_building_proposal_restores_approved_or_legacy_snapshot(string $kind, ?int $type, ?string $resolved): void
    {
        $payload = $this->buildingTypeFixture();
        $created = $this->postJson('/api/properties', [...$payload, 'building_type_id' => $type])->assertOk();
        $property = \App\Models\Property::findOrFail($created->json('id'));
        $snapshot = $payload;
        if ($kind !== 'legacy') $snapshot += ['building_type_id' => $type, 'building_type_resolved_at' => $resolved];
        $property->forceFill(['moderation_status' => 'approved', 'publication_status' => 'published',
            'building_type_resolved_at' => $resolved, 'approved_content_snapshot' => $snapshot])->saveQuietly();
        $property->moderationCases()->update(['status' => 'approved', 'blocking' => false]);
        $this->putJson('/api/properties/'.$property->id, ['version' => $property->moderation_version, 'building_type_id' => 3])
            ->assertOk()->assertJsonPath('publication_status', 'pending');
        $property->refresh();
        $this->assertTrue($property->moderationCases()->where('type', \App\Models\PropertyModerationCase::TYPE_CONTENT)->where('status', 'open')->exists());
        $restored = app(\App\Services\PropertyModeration\PropertyModerationService::class)
            ->withdrawChanges($property, User::findOrFail($property->created_by), (int) $property->moderation_version);
        $this->assertSame($type, $restored->building_type_id);
        $this->assertSame($resolved, $restored->building_type_resolved_at);
        $this->assertSame($snapshot, $restored->approved_content_snapshot);
        $this->assertSame('published', $restored->publication_status);
    }

    public function test_custom_writer_can_be_restricted_to_its_assigned_group(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('branch_group_id')->nullable());
        Schema::table('properties', fn (Blueprint $table) => $table->unsignedBigInteger('branch_group_id')->nullable());
        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert([['id' => 1, 'name' => 'Own group'], ['id' => 2, 'name' => 'Other group']]);
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        $role = Role::create(['name' => 'Group writer', 'slug' => 'custom_group_writer']);
        $user = User::create(['name' => 'Writer', 'phone' => '930000097', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $base = ['properties.create', 'properties.edit'];
        $role->forceFill(['permissions' => [...$base, 'properties.write.own_group']])->save();
        Sanctum::actingAs($user->fresh());
        $payload = ['type_id' => $type->id, 'price' => 991000, 'currency' => 'TJS', 'offer_type' => 'sale'];
        $this->postJson('/api/properties', $payload)->assertForbidden();
        $user->forceFill(['branch_group_id' => 1])->saveQuietly();
        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/properties', [...$payload, 'branch_group_id' => 2])->assertForbidden();
        $this->assertDatabaseCount('properties', 0);
        $this->postJson('/api/properties', $payload)->assertOk();
        $property = \App\Models\Property::query()->sole();
        $this->assertSame(1, (int) $property->getRawOriginal('branch_group_id'));

        $property->forceFill(['branch_group_id' => 2])->saveQuietly();
        $this->putJson('/api/properties/'.$property->id, ['title' => 'Own group edit', 'version' => $property->moderation_version])->assertOk();
        $this->assertSame(1, (int) $property->fresh()->getRawOriginal('branch_group_id'));

        $role->forceFill(['permissions' => $base])->save();
        Sanctum::actingAs($user->fresh());
        $property = $property->fresh();
        $property->forceFill(['branch_group_id' => 2])->saveQuietly();
        $this->putJson('/api/properties/'.$property->id, ['title' => 'Unrestricted group edit', 'version' => $property->moderation_version])->assertOk();
        $this->assertSame(2, (int) $property->fresh()->getRawOriginal('branch_group_id'));
    }

    public function test_custom_creator_needs_promotion_permission_only_for_promoted_listing(): void
    {
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        $role = Role::create(['name' => 'Custom creator', 'slug' => 'custom_creator']);
        $user = User::create(['name' => 'Creator', 'phone' => '930000098', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $base = ['properties.create', 'properties.edit'];
        $role->forceFill(['permissions' => $base])->save();
        Sanctum::actingAs($user->fresh());
        $payload = ['type_id' => $type->id, 'price' => 990000, 'currency' => 'TJS', 'offer_type' => 'sale'];
        $this->postJson('/api/properties', [...$payload, 'requested_listing_type' => 'vip'])->assertForbidden();
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('property_promotions', 0);
        $this->postJson('/api/properties', [...$payload, 'requested_listing_type' => 'regular'])->assertOk();
        $role->forceFill(['permissions' => [...$base, 'properties.promotions.request']])->save();
        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/properties', [...$payload, 'requested_listing_type' => 'urgent'])->assertOk();
        $this->assertDatabaseCount('property_promotions', 1);
        $role->forceFill(['permissions' => $base])->save();
        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/properties', [...$payload, 'requested_listing_type' => 'vip'])->assertForbidden();
        $this->assertDatabaseCount('properties', 2);
        $this->assertDatabaseCount('property_promotions', 1);
    }

    public function test_agent_can_select_vip_or_urgent_when_creating_a_listing(): void
    {
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create(['name' => 'Agent', 'phone' => '930000099', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        Sanctum::actingAs($user);
        foreach (['vip', 'urgent'] as $listingType) {
            $response = $this->postJson('/api/properties', [
                'type_id' => $type->id, 'price' => 990000, 'currency' => 'TJS', 'offer_type' => 'sale',
                'requested_listing_type' => $listingType,
            ])->assertOk()->assertJsonPath('publication_status', 'pending')->assertJsonPath('listing_type', 'regular');
            $this->assertDatabaseHas('property_promotions', ['property_id' => $response->json('id'), 'type' => $listingType, 'status' => 'requested']);
            $this->assertDatabaseHas('property_moderation_cases', ['property_id' => $response->json('id'), 'type' => 'initial_review', 'status' => 'open']);
        }
        $this->postJson('/api/properties', ['type_id' => $type->id, 'price' => 990000, 'currency' => 'TJS', 'offer_type' => 'sale', 'requested_listing_type' => 'invalid'])->assertUnprocessable();
    }

    public function test_owner_price_can_be_created_updated_preserved_and_cleared(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create(['name' => 'Agent', 'phone' => '930000096', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        Sanctum::actingAs($user);
        $payload = ['type_id' => $type->id, 'price' => 150000, 'currency' => 'TJS', 'offer_type' => 'sale'];

        $id = $this->postJson('/api/properties', $payload + ['owner_price' => '120000.50'])
            ->assertOk()->assertJsonPath('owner_price', '120000.50')->json('id');
        $this->getJson('/api/properties/'.$id)->assertOk()->assertJsonPath('owner_price', '120000.50')
            ->assertJsonPath('capabilities.can_view_owner_price', true);
        $this->putJson('/api/properties/'.$id, ['owner_price' => '125000.25'])
            ->assertOk()->assertJsonPath('owner_price', '125000.25');
        $this->putJson('/api/properties/'.$id, ['title' => 'Updated title'])
            ->assertOk()->assertJsonPath('owner_price', '125000.25');
        $this->assertDatabaseHas('properties', ['id' => $id, 'price' => 150000, 'owner_price' => 125000.25]);
        foreach ([-1, 0, 'invalid', 10000000000000, [120000]] as $invalid) {
            $this->putJson('/api/properties/'.$id, ['owner_price' => $invalid])->assertUnprocessable()
                ->assertJsonPath('details.errors.owner_price', fn ($errors) => is_array($errors) && count($errors) > 0);
        }
        $this->putJson('/api/properties/'.$id, ['owner_price' => ''])
            ->assertOk()->assertJsonPath('owner_price', null);
        $this->assertDatabaseHas('properties', ['id' => $id, 'owner_price' => null]);
        $this->postJson('/api/properties', $payload)->assertOk()->assertJsonPath('owner_price', null);
    }

    public function test_client_and_external_agent_cannot_write_or_read_owner_price_even_with_edit_grants(): void
    {
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        foreach (['client', 'external_agent', 'custom_writer'] as $index => $slug) {
            $permissions = ['properties.create', 'properties.edit', 'properties.history.view'];
            if ($slug !== 'custom_writer') $permissions = [...$permissions, 'properties.list.internal', 'properties.list.scope.all'];
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $role->forceFill(['permissions' => $permissions])->save();
            $user = User::create(['name' => $slug, 'phone' => '93000009'.$index, 'role_id' => $role->id, 'status' => 'active']);
            Sanctum::actingAs($user);
            $payload = ['type_id' => $type->id, 'price' => 150000, 'currency' => 'TJS', 'offer_type' => 'sale'];
            $this->postJson('/api/properties', $payload + ['owner_price' => 120000])->assertForbidden();
            $id = $this->postJson('/api/properties', $payload)->assertOk()->assertJsonMissingPath('owner_price')->json('id');
            \App\Models\Property::findOrFail($id)->forceFill(['owner_price' => 120000])->saveQuietly();
            $this->putJson('/api/properties/'.$id, ['owner_price' => null])->assertForbidden();
            $this->getJson('/api/properties/'.$id)->assertOk()->assertJsonMissingPath('owner_price')
                ->assertJsonPath('capabilities.can_view_owner_price', false);
            $this->assertDatabaseHas('properties', ['id' => $id, 'owner_price' => 120000]);
            $log = \App\Models\PropertyLog::create(['property_id' => $id, 'user_id' => $user->id, 'action' => 'updated',
                'changes' => ['owner_price' => ['old' => 110000, 'new' => 120000], 'title' => ['old' => 'Old', 'new' => 'New']]]);
            $this->assertArrayNotHasKey('owner_price', $log->toArray()['changes']);
            $this->getJson('/api/properties/'.$id.'/logs')->assertOk()->assertJsonMissingPath('data.0.changes.owner_price');
        }
    }

    public function test_bathroom_type_can_be_created_changed_preserved_and_cleared(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create(['name' => 'Agent', 'phone' => '930000095', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        Sanctum::actingAs($user);
        $payload = ['type_id' => $type->id, 'price' => 150000, 'currency' => 'TJS', 'offer_type' => 'sale'];

        $id = $this->postJson('/api/properties', $payload + ['bathroom_type' => 'combined'])
            ->assertOk()->assertJsonPath('bathroom_type', 'combined')->json('id');
        $this->getJson('/api/properties/'.$id)->assertOk()->assertJsonPath('bathroom_type', 'combined');
        $this->putJson('/api/properties/'.$id, ['bathroom_type' => 'separate'])
            ->assertOk()->assertJsonPath('bathroom_type', 'separate');
        $this->putJson('/api/properties/'.$id, ['title' => 'Updated title'])
            ->assertOk()->assertJsonPath('bathroom_type', 'separate');
        $this->putJson('/api/properties/'.$id, ['bathroom_type' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseHas('properties', ['id' => $id, 'bathroom_type' => 'separate']);
        $this->putJson('/api/properties/'.$id, ['bathroom_type' => ''])
            ->assertOk()->assertJsonPath('bathroom_type', null);
        $this->assertDatabaseHas('properties', ['id' => $id, 'bathroom_type' => null]);
        $this->postJson('/api/properties', $payload)->assertOk()->assertJsonPath('bathroom_type', null);
        $this->postJson('/api/properties', $payload + ['bathroom_type' => null])->assertOk()->assertJsonPath('bathroom_type', null);
        foreach (['invalid', 'Совмещённый', 1, ['combined']] as $invalid) {
            $this->postJson('/api/properties', $payload + ['bathroom_type' => $invalid])->assertUnprocessable();
        }
    }

    public function test_property_store_accepts_construction_and_renovation_statuses(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000001',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'construction_status' => 'commissioned',
            'renovation_permission_status' => 'allowed',
        ]);

        $response->assertOk();
        $response->assertJsonPath('construction_status', 'commissioned');
        $response->assertJsonPath('renovation_permission_status', 'allowed');
        $response->assertJsonPath('created_by', $user->id);
        $response->assertJsonPath('agent_id', $user->id);
    }

    public function test_property_store_accepts_missing_status_id(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status_id', null);
        $this->assertDatabaseHas('properties', [
            'id' => $response->json('id'),
            'status_id' => null,
        ]);
    }

    public function test_property_instagram_link_can_be_created_updated_and_returned(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000097',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'instagram_link' => ' https://www.instagram.com/reel/example/ ',
        ]);

        $created->assertOk()
            ->assertJsonPath('instagram_link', 'https://www.instagram.com/reel/example/');

        $propertyId = $created->json('id');

        $this->getJson('/api/properties/'.$propertyId)
            ->assertOk()
            ->assertJsonPath('instagram_link', 'https://www.instagram.com/reel/example/');

        $updated = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'instagram_link' => 'https://instagram.com/p/updated/',
        ]);

        $updated->assertOk()
            ->assertJsonPath('instagram_link', 'https://instagram.com/p/updated/');

        $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'instagram_link' => 'https://example.com/instagram',
        ])->assertUnprocessable()->assertJsonPath(
            'details.errors.instagram_link.0',
            'Поле Инстаграм должно содержать HTTPS-ссылку на instagram.com.'
        );
    }

    public function test_property_keeps_contract_type_and_document_type_separate(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000096',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $contractType = \App\Models\ContractType::create([
            'name' => 'Эксклюзивный договор',
            'slug' => 'exclusive',
        ]);
        $technicalPassport = \App\Models\DocumentType::create([
            'name' => 'Техпаспорт',
            'slug' => 'technical-passport',
        ]);
        $certificate = \App\Models\DocumentType::create([
            'name' => 'Свидетельство',
            'slug' => 'certificate',
        ]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'contract_type_id' => $contractType->id,
            'document_type_id' => $technicalPassport->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ]);

        $created->assertOk()
            ->assertJsonPath('contract_type_id', $contractType->id)
            ->assertJsonPath('contract_type.id', $contractType->id)
            ->assertJsonPath('document_type_id', $technicalPassport->id)
            ->assertJsonPath('document_type.id', $technicalPassport->id);

        $updated = $this->putJson('/api/properties/'.$created->json('id'), [
            'type_id' => $type->id,
            'contract_type_id' => $contractType->id,
            'document_type_id' => $certificate->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ]);

        $updated->assertOk()
            ->assertJsonPath('contract_type_id', $contractType->id)
            ->assertJsonPath('document_type_id', $certificate->id)
            ->assertJsonPath('document_type.id', $certificate->id);
    }

    public function test_property_features_can_be_selected_replaced_and_cleared(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000099',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $parking = \App\Models\Feature::create([
            'name' => 'Паркинг',
            'slug' => 'parking',
            'icon' => 'car',
        ]);
        $security = \App\Models\Feature::create([
            'name' => 'Охрана',
            'slug' => 'security',
            'icon' => 'shield-check',
        ]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => [$parking->id, $security->id],
        ]);

        $created->assertOk()
            ->assertJsonCount(2, 'features')
            ->assertJsonPath('features.0.icon', 'car')
            ->assertJsonPath('features.1.icon', 'shield-check');

        $propertyId = $created->json('id');
        $this->assertDatabaseHas('feature_property', [
            'property_id' => $propertyId,
            'feature_id' => $parking->id,
        ]);

        $replaced = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => [$security->id],
        ]);

        $replaced->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $security->id);

        $cleared = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => null,
        ]);

        $cleared->assertOk()->assertJsonCount(0, 'features');
        $this->assertDatabaseMissing('feature_property', [
            'property_id' => $propertyId,
        ]);
    }

    public function test_property_tags_can_be_selected_and_cleared(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000098',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $tag = \App\Models\Tag::create([
            'name' => 'Срочная продажа',
            'slug' => 'urgent-sale',
            'color' => '#DC2626',
        ]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'tags' => [$tag->id],
        ]);

        $created->assertOk()
            ->assertJsonCount(1, 'tags')
            ->assertJsonPath('tags.0.slug', 'urgent-sale');

        $propertyId = $created->json('id');

        $cleared = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'tags' => null,
        ]);

        $cleared->assertOk()->assertJsonCount(0, 'tags');
        $this->assertDatabaseMissing('property_tag', ['property_id' => $propertyId]);
    }

    public function test_property_can_be_created_without_features_and_tags(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000097',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        Sanctum::actingAs($user);

        $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => null,
            'tags' => null,
        ])
            ->assertOk()
            ->assertJsonCount(0, 'features')
            ->assertJsonCount(0, 'tags');
    }

    public function test_listing_update_date_changes_only_after_real_content_change(): void
    {
        Carbon::setTestNow('2026-08-14 09:00:00');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000095',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'title' => 'Первоначальное название',
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk();

        $propertyId = $created->json('id');
        $createdAt = $created->json('created_at');
        $initialListingUpdatedAt = $created->json('listing_updated_at');
        $this->assertSame($createdAt, $initialListingUpdatedAt);

        Carbon::setTestNow('2026-08-14 10:00:00');
        $changed = $this->putJson('/api/properties/'.$propertyId, [
            'title' => 'Обновлённое название',
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk();

        $this->assertSame($createdAt, $changed->json('created_at'));
        $this->assertNotSame($initialListingUpdatedAt, $changed->json('listing_updated_at'));
        $changedListingUpdatedAt = $changed->json('listing_updated_at');

        Carbon::setTestNow('2026-08-14 11:00:00');
        $unchanged = $this->putJson('/api/properties/'.$propertyId, [
            'title' => 'Обновлённое название',
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'created_at' => '2000-01-01T00:00:00Z',
            'listing_updated_at' => '2000-01-01T00:00:00Z',
        ])->assertOk();

        $this->assertSame($createdAt, $unchanged->json('created_at'));
        $this->assertSame($changedListingUpdatedAt, $unchanged->json('listing_updated_at'));

        Carbon::setTestNow('2026-08-14 12:00:00');
        $moderated = $this->putJson('/api/properties/'.$propertyId, [
            'title' => 'Обновлённое название',
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
        ])->assertUnprocessable();

        Carbon::setTestNow('2026-08-14 13:00:00');
        $this->postJson('/api/properties/'.$propertyId.'/view')->assertNoContent();
        $property = \App\Models\Property::findOrFail($propertyId);

        $this->assertSame($createdAt, $property->created_at?->toJSON());
        $this->assertSame($changedListingUpdatedAt, $property->listing_updated_at?->toJSON());
    }

    public function test_features_tags_and_photo_order_update_listing_date_only_when_changed(): void
    {
        Carbon::setTestNow('2026-08-14 09:00:00');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000094',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $feature = \App\Models\Feature::create(['name' => 'Паркинг', 'slug' => 'parking']);
        $tag = \App\Models\Tag::create(['name' => 'Срочно', 'slug' => 'urgent']);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk();
        $propertyId = $created->json('id');

        Carbon::setTestNow('2026-08-14 10:00:00');
        $relationsChanged = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => [$feature->id],
            'tags' => [$tag->id],
        ])->assertOk();
        $relationsUpdatedAt = $relationsChanged->json('listing_updated_at');
        $this->assertNotSame($created->json('listing_updated_at'), $relationsUpdatedAt);

        Carbon::setTestNow('2026-08-14 11:00:00');
        $sameRelations = $this->putJson('/api/properties/'.$propertyId, [
            'type_id' => $type->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'features' => [$feature->id],
            'tags' => [$tag->id],
        ])->assertOk();
        $this->assertSame($relationsUpdatedAt, $sameRelations->json('listing_updated_at'));

        $property = \App\Models\Property::findOrFail($propertyId);
        $firstPhoto = $property->photos()->create(['file_path' => 'properties/first.jpg', 'position' => 0]);
        $secondPhoto = $property->photos()->create(['file_path' => 'properties/second.jpg', 'position' => 1]);

        Carbon::setTestNow('2026-08-14 12:00:00');
        $reordered = $this->putJson('/api/properties/'.$propertyId.'/photos/reorder', [
            'photo_order' => [$secondPhoto->id, $firstPhoto->id],
        ])->assertOk();
        $photoUpdatedAt = $reordered->json('listing_updated_at');
        $this->assertNotSame($relationsUpdatedAt, $photoUpdatedAt);

        Carbon::setTestNow('2026-08-14 13:00:00');
        $sameOrder = $this->putJson('/api/properties/'.$propertyId.'/photos/reorder', [
            'photo_order' => [$secondPhoto->id, $firstPhoto->id],
        ])->assertOk();
        $this->assertSame($photoUpdatedAt, $sameOrder->json('listing_updated_at'));
        $this->assertSame($created->json('created_at'), $sameOrder->json('created_at'));
    }

    public function test_properties_can_be_sorted_by_listing_update_date(): void
    {
        Carbon::setTestNow('2026-08-14 09:00:00');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000093',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        Sanctum::actingAs($user);

        $firstId = $this->postJson('/api/properties', [
            'title' => 'Первый',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk()->json('id');

        Carbon::setTestNow('2026-08-14 10:00:00');
        $secondId = $this->postJson('/api/properties', [
            'title' => 'Второй',
            'type_id' => $type->id,
            'price' => 200000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk()->json('id');

        Carbon::setTestNow('2026-08-14 11:00:00');
        $this->putJson('/api/properties/'.$firstId, [
            'title' => 'Первый обновлён',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertOk();

        $this->getJson('/api/properties?sort=listing_updated_at&dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.1.id', $secondId);

        $this->getJson('/api/properties?sort=listing_updated_at&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.1.id', $firstId);

        $this->getJson('/api/my-properties?sort=listing_updated_at&dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.1.id', $secondId);
    }

    public function test_property_store_does_not_flag_duplicate_by_phone_only(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000101',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        \App\Models\Property::query()->create([
            'title' => 'Existing property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'created_by' => $user->id,
            'agent_id' => $user->id,
            'owner_phone' => '+992 90 111 2233',
            'address' => 'улица Айни, 10',
            'floor' => 2,
            'total_area' => 60,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_phone' => '901112233',
            'address' => 'проспект Рудаки, 55',
            'floor' => 9,
            'total_area' => 120,
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('properties', 2);
    }

    public function test_property_store_does_not_flag_sale_as_duplicate_of_rent(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000103',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        \App\Models\Property::query()->create([
            'title' => 'Rental property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 5000,
            'currency' => 'TJS',
            'offer_type' => 'rent',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'agent_id' => $user->id,
            'owner_phone' => '+992 90 111 2255',
            'address' => 'улица Рудаки, 15',
            'floor' => 5,
            'total_area' => 80,
            'latitude' => 38.5598,
            'longitude' => 68.7870,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 180000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_phone' => '901112255',
            'address' => 'улица Рудаки, 15',
            'floor' => 5,
            'total_area' => 80,
            'latitude' => 38.5598,
            'longitude' => 68.7870,
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('properties', 2);
    }

    public function test_property_store_ignores_closed_or_deleted_duplicates(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000102',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        \App\Models\Property::query()->create([
            'title' => 'Sold property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'sold',
            'created_by' => $user->id,
            'agent_id' => $user->id,
            'owner_phone' => '+992 90 111 2244',
            'address' => 'улица Бохтар, 12',
            'floor' => 4,
            'total_area' => 75,
            'latitude' => 38.5598,
            'longitude' => 68.7870,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 155000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_phone' => '901112244',
            'address' => 'улица Бохтар, 12',
            'floor' => 4,
            'total_area' => 75,
            'latitude' => 38.5598,
            'longitude' => 68.7870,
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('properties', 2);
    }

    public function test_property_store_only_flags_duplicate_when_similarity_is_above_90_percent(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $firstAgent = User::create([
            'name' => 'First Agent',
            'phone' => '930000120',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $secondAgent = User::create([
            'name' => 'Second Agent',
            'phone' => '930000121',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        $existing = \App\Models\Property::query()->create([
            'title' => 'Existing apartment',
            'description' => 'Локация: Сино, кольцевой Профсоюз. Новый ремонт.',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 999000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $firstAgent->id,
            'agent_id' => $firstAgent->id,
            'owner_phone' => '116614466',
            'address' => 'Душанбе, махалла Яккачинор',
            'district' => 'Сино',
            'rooms' => 2,
            'total_area' => 65,
            'floor' => 4,
            'total_floors' => 14,
            'latitude' => 38.555846,
            'longitude' => 68.754671,
        ]);
        $existing->photos()->create(['file_path' => 'properties/existing.jpg', 'position' => 0]);

        Sanctum::actingAs($secondAgent);
        $payload = [
            'description' => 'Продаётся квартира около кольцевого Профсоюза, новый ремонт.',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 960000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_phone' => '+992 93 944 1035',
            'address' => 'Душанбе профсоюз',
            'district' => 'Сино',
            'rooms' => 2,
            'total_area' => 65,
            'floor' => 4,
            'total_floors' => 14,
            'latitude' => 38.576271,
            'longitude' => 68.779716,
        ];

        $this->assertSame(
            [],
            app(\App\Services\PropertyDuplicateService::class)->find($payload)->all(),
            'A similarity score of 90% or lower must not be treated as a duplicate.'
        );

        $highConfidencePayload = [
            ...$payload,
            'description' => $existing->description,
            'owner_phone' => $existing->owner_phone,
            'address' => $existing->address,
            'latitude' => $existing->latitude,
            'longitude' => $existing->longitude,
        ];

        $response = $this->postJson('/api/properties', $highConfidencePayload)
            ->assertOk()
            ->assertJsonPath('moderation_status', 'pending')
            ->assertJsonPath('duplicate_candidates.0.id', $existing->id)
            ->assertJsonPath('duplicate_candidates.0.photos.0.file_path', 'properties/existing.jpg')
            ->assertJsonPath('duplicate_candidates.0.score', 100)
            ->assertJsonPath('duplicate_candidates.0.summary.coordinates_conflict', false);

        $signals = collect($response->json('duplicate_candidates.0.signals'));
        $this->assertTrue($signals->contains(fn (array $signal) => $signal['code'] === 'text' && $signal['matched']));
        $this->assertTrue($signals->contains(fn (array $signal) => $signal['code'] === 'total_area' && $signal['matched']));
        $createdId = $response->json('id');
        $this->assertDatabaseCount('properties', 2);

        $this->getJson("/api/properties/{$createdId}/duplicate-candidates")
            ->assertOk()
            ->assertJsonPath('property_id', $createdId)
            ->assertJsonPath('duplicates.0.id', $existing->id)
            ->assertJsonPath('duplicates.0.photos.0.file_path', 'properties/existing.jpg');
    }

    public function test_property_store_warns_about_suspicious_price_and_phone_but_force_can_continue(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent',
            'phone' => '930000122',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);
        Sanctum::actingAs($agent);

        $payload = [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 960,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_phone' => '12345',
        ];

        $response = $this->postJson('/api/properties', $payload)
            ->assertOk()
            ->assertJsonPath('moderation_status', 'pending')
            ->assertJsonPath('duplicate_candidates', []);

        $codes = collect($response->json('quality_warnings'))->pluck('code');
        $this->assertTrue($codes->contains('suspicious_price'));
        $this->assertTrue($codes->contains('suspicious_owner_phone'));

        $this->postJson('/api/properties', [...$payload, 'force' => true])->assertUnprocessable();
        $this->assertDatabaseCount('properties', 1);
    }

    public function test_listing_map_accepts_tajikistan_and_checks_partial_edits_against_stored_coordinates(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::forceCreate(['name' => 'Agent', 'phone' => '930000127', 'role_id' => $role->id, 'status' => 'active']);
        Sanctum::actingAs($agent);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $payload = ['type_id' => $type->id, 'price' => 100000, 'currency' => 'TJS', 'offer_type' => 'sale'];
        $foreign = ['latitude' => 40.06, 'longitude' => 70.82]; // Batken lies inside the country's bounding box.
        $this->postJson('/api/properties', $payload + $foreign)
            ->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['latitude', 'longitude']]]);
        $this->postJson('/api/properties', $payload + ['latitude' => 38.56])
            ->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['longitude']]]);
        $this->assertDatabaseCount('properties', 0);

        $dushanbe = ['latitude' => 38.56, 'longitude' => 68.78];
        $id = $this->postJson('/api/properties', $payload + $dushanbe)->assertOk()->json('id');
        $url = '/api/properties/'.$id;
        $this->putJson($url, ['longitude' => 50])->assertUnprocessable();
        $this->assertDatabaseHas('properties', ['id' => $id] + $dushanbe);
        $this->putJson($url, ['longitude' => 68.79])->assertOk();
        $this->assertDatabaseHas('properties', ['id' => $id, 'latitude' => 38.56, 'longitude' => 68.79]);

        // Old listings can still be edited without touching their map position.
        \Illuminate\Support\Facades\DB::table('properties')->where('id', $id)->update($foreign);
        $this->putJson($url, ['description' => 'Новое описание'])->assertOk();
        $this->putJson($url, ['description' => 'Уточнение', 'latitude' => '40.060', 'longitude' => '70.820'])->assertOk();
        $this->putJson($url, ['latitude' => null, 'longitude' => null])->assertOk();
        $this->assertDatabaseHas('properties', ['id' => $id, 'latitude' => null, 'longitude' => null]);
    }

    public function test_property_store_warns_when_coordinates_are_far_from_selected_location(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent',
            'phone' => '930000124',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);
        $location = \App\Models\Location::query()->create([
            'city' => 'Душанбе',
            'latitude' => 38.5598,
            'longitude' => 68.7870,
        ]);
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'location_id' => $location->id,
            'price' => 900000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'latitude' => 40.2826,
            'longitude' => 69.6222,
        ])->assertOk()->assertJsonPath('moderation_status', 'pending');

        $this->assertContains(
            'coordinates_outside_location',
            collect($response->json('quality_warnings'))->pluck('code')->all()
        );
    }

    public function test_property_store_does_not_warn_for_explicitly_different_apartments_in_same_building(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent',
            'phone' => '930000123',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);
        \App\Models\Property::query()->create([
            'title' => 'Apartment 12',
            'description' => 'Дом Рудаки 10, кв. 12, новый ремонт',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 800000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'rooms' => 2,
            'total_area' => 65,
            'floor' => 4,
            'total_floors' => 14,
            'address' => 'Рудаки 10',
        ]);
        Sanctum::actingAs($agent);

        $this->postJson('/api/properties', [
            'description' => 'Дом Рудаки 10, квартира 14, новый ремонт',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 805000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'rooms' => 2,
            'total_area' => 65,
            'floor' => 4,
            'total_floors' => 14,
            'address' => 'Рудаки 10',
        ])->assertOk();

        $this->assertDatabaseCount('properties', 2);
    }

    public function test_intern_cannot_create_properties_and_sees_only_own_in_index(): void
    {
        $internRole = Role::create([
            'name' => 'Intern',
            'slug' => 'intern',
        ]);

        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $intern = User::create([
            'name' => 'Intern User',
            'phone' => '930000010',
            'password' => bcrypt('password'),
            'role_id' => $internRole->id,
            'status' => 'active',
        ]);

        $otherUser = User::create([
            'name' => 'Agent User',
            'phone' => '930000011',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        \App\Models\Property::query()->create([
            'title' => 'Intern Property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $intern->id,
            'agent_id' => $intern->id,
        ]);

        \App\Models\Property::query()->create([
            'title' => 'Foreign Property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 120000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $otherUser->id,
            'agent_id' => $otherUser->id,
        ]);

        Sanctum::actingAs($intern);

        $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 150000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertForbidden();

        $response = $this->getJson('/api/properties');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Intern Property');
        $response->assertJsonMissing(['title' => 'Foreign Property']);
    }

    public function test_properties_index_filters_by_construction_status_and_keeps_other_filters(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000210',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        \App\Models\Property::query()->create([
            'title' => 'Built Match',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 140000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'rooms' => 3,
            'construction_status' => 'built',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'agent_id' => $user->id,
        ]);

        \App\Models\Property::query()->create([
            'title' => 'Built Low Price',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 90000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'rooms' => 3,
            'construction_status' => 'built',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'agent_id' => $user->id,
        ]);

        \App\Models\Property::query()->create([
            'title' => 'Commissioned',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 160000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'rooms' => 3,
            'construction_status' => 'commissioned',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'agent_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/properties?construction_status=built&priceFrom=100000&roomsFrom=2');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Built Match');
        $response->assertJsonPath('data.0.construction_status', 'built');
    }

    public function test_compact_property_feed_omits_crm_fields_and_keeps_card_data(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Feed Agent',
            'phone' => '930000299',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment', 'slug' => 'apartments']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available', 'slug' => 'available']);
        $property = \App\Models\Property::query()->create([
            'title' => 'Compact Feed Card',
            'bathroom_type' => 'separate',
            'description' => str_repeat('Public description ', 10),
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 140000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'rooms' => 2,
            'moderation_status' => 'approved',
            'owner_phone' => 'PRIVATE-OWNER-PHONE',
            'status_comment' => 'PRIVATE-CRM-COMMENT',
            'created_by' => $user->id,
        ]);
        \App\Models\PropertyPhoto::query()->create([
            'property_id' => $property->id,
            'file_path' => 'properties/card.jpg',
            'position' => 0,
        ]);

        $response = $this->getJson('/api/properties?compact=1&per_page=4')
            ->assertOk()
            ->assertJsonPath('per_page', 4)
            ->assertJsonPath('data.0.title', 'Compact Feed Card')
            ->assertJsonPath('data.0.bathroom_type', 'separate')
            ->assertJsonPath('data.0.type.slug', 'apartments')
            ->assertJsonPath('data.0.photos.0.file_path', 'properties/card.jpg')
            ->assertJsonMissing(['owner_phone' => 'PRIVATE-OWNER-PHONE'])
            ->assertJsonMissing(['status_comment' => 'PRIVATE-CRM-COMMENT']);

        $this->assertArrayNotHasKey('phone', $response->json('data.0.creator'));
    }

    public function test_document_type_multi_filter_is_consistent_across_property_collections(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000219',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);
        $contractType = \App\Models\ContractType::create([
            'name' => 'Эксклюзивный договор',
            'slug' => 'exclusive',
        ]);
        $documents = collect([
            ['name' => 'Свидетельство', 'slug' => 'certificate'],
            ['name' => 'Техпаспорт', 'slug' => 'technical-passport'],
            ['name' => 'Договор', 'slug' => 'contract'],
        ])->map(fn (array $data) => \App\Models\DocumentType::create($data));

        $properties = $documents->values()->map(function ($document, int $index) use ($user, $type, $status, $contractType) {
            return \App\Models\Property::create([
                'title' => 'Object '.($index + 1),
                'type_id' => $type->id,
                'status_id' => $status->id,
                'contract_type_id' => $contractType->id,
                'document_type_id' => $document->id,
                'price' => 100000 + ($index * 10000),
                'currency' => 'TJS',
                'offer_type' => 'sale',
                'rooms' => $index + 1,
                'moderation_status' => 'approved',
                'created_by' => $user->id,
                'agent_id' => $user->id,
                'latitude' => 38.55 + ($index * 0.01),
                'longitude' => 68.75 + ($index * 0.01),
            ]);
        });

        Sanctum::actingAs($user);

        $query = http_build_query([
            'document_type_ids' => [$documents[0]->id, $documents[1]->id],
        ]);

        $list = $this->getJson('/api/properties?'.$query)->assertOk();
        $this->assertEqualsCanonicalizing(
            [$properties[0]->id, $properties[1]->id],
            collect($list->json('data'))->pluck('id')->all()
        );

        $this->getJson('/api/properties/count?'.$query)
            ->assertOk()
            ->assertJsonPath('count', 2);

        $map = $this->getJson('/api/properties/map?'.http_build_query([
            'bbox' => '38.4,68.6,38.8,69.1',
            'zoom' => 12,
            'document_type_ids' => [$documents[0]->id, $documents[1]->id],
        ]))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$properties[0]->id, $properties[1]->id],
            collect($map->json('features'))->pluck('properties.id')->all()
        );

        $otherUser = User::create([
            'name' => 'Other Agent',
            'phone' => '930000217',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        \App\Models\Property::create([
            'title' => 'Foreign object',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'contract_type_id' => $contractType->id,
            'document_type_id' => $documents[0]->id,
            'price' => 125000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $otherUser->id,
            'agent_id' => $otherUser->id,
        ]);

        $myProperties = $this->getJson('/api/my-properties?'.$query)->assertOk();
        $this->assertEqualsCanonicalizing(
            [$properties[0]->id, $properties[1]->id],
            collect($myProperties->json('data'))->pluck('id')->all()
        );

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_ids' => [$documents[1]->id, $documents[2]->id],
            'roomsFrom' => 3,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $properties[2]->id);

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/properties?document_type_ids=')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Singular filter remains compatible.
        $this->getJson('/api/properties?document_type_id='.$documents[2]->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $properties[2]->id);

        // Multi-value filter takes priority when both parameters are sent.
        $this->getJson('/api/properties?'.http_build_query([
            'document_type_id' => $documents[2]->id,
            'document_type_ids' => [$documents[0]->id],
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $properties[0]->id);

        // Contract type remains a separate, unchanged filter.
        $this->getJson('/api/properties?contract_type_id='.$contractType->id)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_document_type_multi_filter_validation_rejects_invalid_values(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000218',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $document = \App\Models\DocumentType::create([
            'name' => 'Свидетельство',
            'slug' => 'certificate',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_id' => 999999,
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['document_type_id']]]);

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_id' => 'not-an-id',
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['document_type_id']]]);

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_ids' => [999999],
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['document_type_ids.0']]]);

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_ids' => ['not-an-id'],
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['document_type_ids.0']]]);

        $this->getJson('/api/properties?'.http_build_query([
            'document_type_ids' => [$document->id, $document->id],
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['errors' => ['document_type_ids.1']]]);
    }

    public function test_properties_index_returns_422_for_invalid_construction_status_filter(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000220',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/properties?construction_status=test');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['construction_status'], 'details.errors');
    }

    public function test_public_renovation_permission_filter_matches_list_count_and_map(): void
    {
        \App\Models\RepairType::forceCreate(['id' => 6, 'name' => 'Новый ремонт']);
        \App\Models\RepairType::forceCreate(['id' => 7, 'name' => 'Без ремонта / коробка']);
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User', 'phone' => '930000250',
            'password' => bcrypt('password'), 'role_id' => $role->id, 'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'New building', 'slug' => 'new-buildings']);
        $otherType = \App\Models\PropertyType::create(['name' => 'House', 'slug' => 'houses']);
        $properties = collect([
            ['title' => 'Allowed shell'],
            ['title' => 'Not allowed shell', 'renovation_permission_status' => 'not_allowed'],
            ['title' => 'Unknown permission', 'renovation_permission_status' => null],
            ['title' => 'Different type', 'type_id' => $otherType->id],
            ['title' => 'Already renovated', 'repair_type_id' => 6],
            ['title' => 'Rental', 'offer_type' => 'rent'],
            ['title' => 'Promotion', 'listing_type' => 'vip'],
            ['title' => 'Unapproved', 'moderation_status' => 'pending'],
        ])->map(fn (array $attributes) => \App\Models\Property::create(array_merge([
            'type_id' => $type->id, 'repair_type_id' => 7,
            'price' => 100000, 'currency' => 'TJS', 'offer_type' => 'sale',
            'listing_type' => 'regular', 'moderation_status' => 'approved',
            'renovation_permission_status' => 'allowed',
            'created_by' => $user->id, 'latitude' => 38.55, 'longitude' => 68.75,
        ], $attributes)));

        $filters = [
            'type_id' => $type->id, 'repair_type_id' => 7,
            'listing_type' => 'regular', 'offer_type' => 'sale',
        ];
        foreach (['allowed' => $properties[0]->id, 'not_allowed' => $properties[1]->id] as $permission => $id) {
            $query = http_build_query($filters + ['renovation_permission_status' => $permission]);
            $this->getJson('/api/properties?'.$query)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
            $this->getJson('/api/properties/count?'.$query)
                ->assertOk()->assertExactJson(['count' => 1]);
            $this->getJson('/api/properties/map?bbox=38.4,68.6,38.8,69.1&zoom=12&'.$query)
                ->assertOk()->assertJsonCount(1, 'features')->assertJsonPath('features.0.properties.id', $id);
        }

        // The existing shell collection still includes unknown and disallowed permissions.
        $this->getJson('/api/properties?'.http_build_query($filters))
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_public_property_endpoints_reject_invalid_renovation_permission_filters(): void
    {
        foreach (['properties', 'properties/count', 'properties/map'] as $endpoint) {
            foreach (['invalid', ['allowed']] as $value) {
                $this->getJson('/api/'.$endpoint.'?'.http_build_query([
                    'renovation_permission_status' => $value,
                    'bbox' => '38.4,68.6,38.8,69.1', 'zoom' => 12,
                ]))->assertStatus(422)
                    ->assertJsonValidationErrors(['renovation_permission_status'], 'details.errors');
            }
        }
    }

    public function test_public_property_count_includes_only_approved_properties(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000240',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $activeStatus = \App\Models\PropertyStatus::create(['name' => 'Available', 'slug' => 'available']);
        foreach ([
            ['title' => 'Visible', 'moderation_status' => 'approved', 'status_id' => $activeStatus->id],
            ['title' => 'Pending', 'moderation_status' => 'pending', 'status_id' => $activeStatus->id],
            ['title' => 'Rejected', 'moderation_status' => 'rejected', 'status_id' => $activeStatus->id],
            ['title' => 'Deleted', 'moderation_status' => 'deleted', 'status_id' => $activeStatus->id],
        ] as $attributes) {
            \App\Models\Property::query()->create(array_merge($attributes, [
                'type_id' => $type->id,
                'price' => 100000,
                'currency' => 'TJS',
                'offer_type' => 'sale',
                'created_by' => $user->id,
            ]));
        }

        $this->getJson('/api/properties/count')
            ->assertOk()
            ->assertExactJson(['count' => 1]);
    }

    public function test_public_property_count_matches_list_for_supported_filters(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000241',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $apartment = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $house = \App\Models\PropertyType::create(['name' => 'House']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available', 'slug' => 'available']);

        foreach ([
            ['type_id' => $apartment->id, 'price' => 150000, 'rooms' => 3, 'total_area' => 80, 'floor' => 5, 'district' => 'Сино', 'offer_type' => 'sale', 'listing_type' => 'vip', 'is_full_apartment' => true, 'construction_status' => 'built', 'landmark' => 'Парк'],
            ['type_id' => $house->id, 'price' => 70000, 'rooms' => 1, 'total_area' => 40, 'floor' => 1, 'district' => 'Фирдавси', 'offer_type' => 'rent', 'listing_type' => 'regular', 'is_full_apartment' => false, 'construction_status' => 'commissioned', 'landmark' => 'Рынок'],
        ] as $attributes) {
            \App\Models\Property::query()->create(array_merge($attributes, [
                'status_id' => $status->id,
                'currency' => 'TJS',
                'moderation_status' => 'approved',
                'created_by' => $user->id,
            ]));
        }

        $filters = [
            'propertyTypes' => [$apartment->id],
            'roomsFrom' => 3,
            'roomsTo' => 3,
            'priceFrom' => 100000,
            'priceTo' => 200000,
            'areaFrom' => 70,
            'areaTo' => 90,
            'floorFrom' => 5,
            'floorTo' => 5,
            'district' => 'Сино',
            'offer_type' => 'sale',
            'listing_type' => 'vip',
            'is_full_apartment' => 'true',
            'construction_status' => 'built',
            'landmark' => 'Парк',
        ];
        $query = http_build_query($filters);

        $list = $this->getJson('/api/properties?'.$query)
            ->assertOk()
            ->json('total');

        $this->getJson('/api/properties/count?'.$query)
            ->assertOk()
            ->assertExactJson(['count' => $list]);
    }

    public function test_public_property_count_returns_zero_when_nothing_matches(): void
    {
        $this->getJson('/api/properties/count?offer_type=rent')
            ->assertOk()
            ->assertExactJson(['count' => 0]);
    }

    public function test_price_range_uses_discount_price_when_present(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000242',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'New building']);

        $discounted = \App\Models\Property::query()->create([
            'title' => 'Discounted match',
            'type_id' => $type->id,
            'price' => 1050000,
            'discount_price' => 955000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'latitude' => 38.55,
            'longitude' => 68.75,
        ]);
        $regular = \App\Models\Property::query()->create([
            'title' => 'Regular non-match',
            'type_id' => $type->id,
            'price' => 980000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
        ]);

        $response = $this->getJson('/api/properties?priceFrom=955000&priceTo=955000');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $discounted->id);

        $sortedIds = collect($this->getJson('/api/properties?'.http_build_query([
            'priceFrom' => 900000,
            'priceTo' => 1100000,
            'sort' => 'price',
            'dir' => 'asc',
        ]))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$discounted->id, $regular->id], $sortedIds);

        $map = $this->getJson('/api/properties/map?'.http_build_query([
            'bbox' => '38.4,68.6,38.8,69.1',
            'zoom' => 11,
            'priceFrom' => 955000,
            'priceTo' => 955000,
        ]))->assertOk();
        $map->assertJsonPath('features.0.properties.point_count', 1);
        $map->assertJsonPath('features.0.properties.min_price', 955000);
    }

    public function test_property_rejects_zero_discount_and_invalid_filter_ranges(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000243',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        Sanctum::actingAs($user);

        $this->postJson('/api/properties', [
            'type_id' => $type->id,
            'price' => 100000,
            'discount_price' => 0,
            'currency' => 'TJS',
            'offer_type' => 'sale',
        ])->assertUnprocessable();

        $this->getJson('/api/properties?priceFrom=200000&priceTo=100000')
            ->assertUnprocessable();

        $this->getJson('/api/properties?moderation_status=unknown')
            ->assertUnprocessable();

        $this->getJson('/api/properties?per_page=1000')
            ->assertUnprocessable();
    }

    public function test_search_area_filters_catalog_count_and_map_before_clustering(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::forceCreate(['name' => 'Agent', 'phone' => '930000248', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $base = ['type_id' => $type->id, 'price' => 125000, 'currency' => 'TJS',
            'moderation_status' => 'approved', 'created_by' => $user->id];
        $rows = [];
        foreach ([
            [38.55, 68.75, 'sale'], [38.553, 68.753, 'sale'], [38.554, 68.754, 'sale'],
            [38.551, 68.751, 'rent'], [null, null, 'sale'],
        ] as [$lat, $lng, $offer]) {
            $rows[] = \App\Models\Property::create($base + ['latitude' => $lat, 'longitude' => $lng, 'offer_type' => $offer]);
        }

        $query = 'area=38.55,68.75,38.553,68.753&offer_type=sale';
        $expected = [$rows[0]->id, $rows[1]->id];
        $catalog = $this->getJson('/api/properties?'.$query)->assertOk();
        $this->assertEqualsCanonicalizing($expected, array_column($catalog->json('data'), 'id'));
        $this->getJson('/api/properties/count?'.$query)->assertOk()->assertExactJson(['count' => 2]);
        $visibleMap = '/api/properties/map?bbox=38.5,68.7,38.6,68.8&'.$query;
        $points = $this->getJson($visibleMap.'&zoom=15')->assertOk();
        $this->assertEqualsCanonicalizing($expected, collect($points->json('features'))->pluck('properties.id')->all());
        $this->getJson($visibleMap.'&zoom=9')->assertOk()->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.properties.point_count', 2);
        $this->getJson('/api/properties/map?bbox=39,68,40,70&zoom=9&'.$query)->assertOk()->assertJsonCount(0, 'features');
        foreach (['1,2,0,3', '1,2,3,181', 'x,2,3,4', '1,2,1,2', ['wrong']] as $invalid) {
            $this->getJson('/api/properties/count?'.http_build_query(['area' => $invalid]))
                ->assertStatus(422)->assertJsonValidationErrors(['area'], 'details.errors');
        }
    }

    public function test_freehand_search_uses_the_concave_ring_for_catalog_count_and_clusters(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::forceCreate(['name' => 'Agent', 'phone' => '930000248', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $base = ['type_id' => $type->id, 'price' => 120000, 'currency' => 'TJS',
            'moderation_status' => 'approved', 'created_by' => $agent->id];

        $ring = ['38.550,68.750', '38.550,68.759', '38.559,68.759', '38.553,68.756', '38.559,68.750'];
        $expected = [];
        foreach ([
            ['38.551', '68.754', 'sale', true],       // Interior
            ['38.557', '68.7589', 'sale', true],      // Narrow right arm
            ['38.556', '68.753', 'sale', true],       // Sloping left edge
            ['38.55', '68.751', 'sale', true],        // Horizontal edge
            ['38.552', '68.75', 'sale', true],        // Vertical edge
            ['38.553', '68.756', 'sale', true],       // Concave vertex
            ['38.557', '68.755', 'sale', false],      // In the notch, inside bounding box
            ['38.556', '68.75300001', 'sale', false], // Just over the sloping edge
            ['38.549999', '68.751', 'sale', false],   // Outside bounding box
            ['38.551', '68.754', 'rent', false],
            [null, null, 'sale', false],
        ] as [$latitude, $longitude, $offer, $include]) {
            $property = \App\Models\Property::create($base + ['latitude' => $latitude, 'longitude' => $longitude, 'offer_type' => $offer]);
            if ($include) {
                $expected[] = $property->id;
            }
        }

        foreach ([$ring, array_reverse($ring)] as $vertices) {
            $query = http_build_query(['area' => 'p:'.implode(';', $vertices), 'offer_type' => 'sale']);
            $catalog = $this->getJson('/api/properties?'.$query)->assertOk();
            $this->assertEqualsCanonicalizing($expected, array_column($catalog->json('data'), 'id'));
            $this->getJson('/api/properties?per_page=2&page=3&'.$query)->assertOk()
                ->assertJsonCount(2, 'data')->assertJsonPath('total', count($expected));
            $this->getJson('/api/properties/count?'.$query)->assertOk()->assertExactJson(['count' => count($expected)]);
            $map = '/api/properties/map?bbox=38.54,68.74,38.57,68.76&'.$query;
            $points = $this->getJson($map.'&zoom=16')->assertOk();
            $this->assertEqualsCanonicalizing($expected, collect($points->json('features'))->pluck('properties.id')->all());
            $this->getJson($map.'&zoom=8')->assertOk()->assertJsonCount(1, 'features')
                ->assertJsonPath('features.0.properties.point_count', count($expected));
        }
    }

    public function test_freehand_cluster_anchor_stays_inside_a_concave_contour(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::forceCreate(['name' => 'Agent', 'phone' => '930000248', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        foreach ([68.7501, 68.7589] as $lng) {
            \App\Models\Property::create(['type_id' => $type->id, 'price' => 120000, 'currency' => 'TJS',
                'offer_type' => 'sale', 'moderation_status' => 'approved', 'created_by' => $agent->id,
                'latitude' => 38.558, 'longitude' => $lng]);
        }

        // Both arms of the shape occupy the same low-zoom cell. Their midpoint is in the notch.
        $query = http_build_query(['area' => 'p:38.55,68.75;38.55,68.759;38.559,68.759;38.553,68.756;38.559,68.75',
            'bbox' => '38.54,68.74,38.57,68.76', 'zoom' => 8]);
        $this->getJson('/api/properties/map?'.$query)->assertOk()->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.properties.point_count', 2)
            ->assertJsonPath('features.0.geometry.coordinates', [38.558, 68.7501]);
    }

    public function test_freehand_search_validates_ring_geometry_and_vertex_limit(): void
    {
        $ring = [];
        for ($i = 0; $i < 64; $i++) {
            $angle = 2 * pi() * $i / 64;
            $ring[] = sprintf('%.6F,%.6F', 38.55 + cos($angle) / 100, 68.75 + sin($angle) / 100);
        }
        $this->getJson('/api/properties/count?'.http_build_query(['area' => 'p:'.implode(';', $ring)]))
            ->assertOk()->assertJsonPath('count', 0);

        foreach ([
            '0,0;0,1', '0,0;0,1;0,2', '0,0;1,1;0,1;1,0', '0,0;1,0;0,1;0,0',
            '0,0;0,4;0,2;2,2;2,0', '0,0;0,4;4,4;0,2;4,0',
            '0,0;0,181;1,0', '-91,0;0,1;1,0', '0,0;0,NaN;1,0', '0,0;1e2,1;1,0',
            '10,179;10,-179;11,-179', '0,-100;0,0;1,100',
            implode(';', $ring).';38.55,68.75',
        ] as $invalid) {
            $this->getJson('/api/properties/count?'.http_build_query(['area' => 'p:'.$invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors(['area'], 'details.errors');
        }
    }

    public function test_public_map_exposes_the_listing_id_only_for_singleton_clusters(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::forceCreate(['name' => 'Agent', 'phone' => '930000248', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $base = ['type_id' => $type->id, 'price' => 125000, 'currency' => 'TJS', 'offer_type' => 'sale',
            'moderation_status' => 'approved', 'created_by' => $user->id];
        $single = \App\Models\Property::create($base + ['latitude' => 48.85, 'longitude' => 2.35]);
        foreach ([2.311, 2.312] as $longitude) {
            \App\Models\Property::create($base + ['latitude' => 48.85, 'longitude' => $longitude]);
        }

        $map = $this->getJson('/api/properties/map?bbox=48.8,2.3,48.9,2.4&zoom=4')
            ->assertOk()->assertJsonCount(2, 'features');
        $clusters = collect($map->json('features'))->pluck('properties')->keyBy('point_count');
        $this->assertSame([$single->id], $clusters[1]['member_ids']);
        $this->assertArrayNotHasKey('member_ids', $clusters[2]);
    }

    public function test_map_cache_and_count_respect_authenticated_property_scope(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $firstAgent = User::create([
            'name' => 'First Agent',
            'phone' => '930000244',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $secondAgent = User::create([
            'name' => 'Second Agent',
            'phone' => '930000245',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);

        $firstProperty = \App\Models\Property::query()->create([
            'title' => 'First scoped property',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $firstAgent->id,
            'latitude' => 38.55,
            'longitude' => 68.75,
        ]);
        $secondProperty = \App\Models\Property::query()->create([
            'title' => 'Second scoped property',
            'type_id' => $type->id,
            'price' => 110000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $secondAgent->id,
            'latitude' => 38.56,
            'longitude' => 68.76,
        ]);
        $mapUrl = '/api/properties/map?bbox=38.4,68.6,38.8,69.1&zoom=12';

        Sanctum::actingAs($firstAgent);
        $firstMapIds = collect($this->getJson($mapUrl)->assertOk()->json('features'))
            ->pluck('properties.id')
            ->all();
        $this->assertSame([$firstProperty->id], $firstMapIds);
        $this->getJson('/api/properties/count')->assertOk()->assertJsonPath('count', 1);

        Sanctum::actingAs($secondAgent);
        $secondMapIds = collect($this->getJson($mapUrl)->assertOk()->json('features'))
            ->pluck('properties.id')
            ->all();
        $this->assertSame([$secondProperty->id], $secondMapIds);
        $this->getJson('/api/properties/count')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_legacy_moderation_listing_endpoint_is_retired(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
        ]);
        $admin = User::create([
            'name' => 'Admin User',
            'phone' => '930000230',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        $status = \App\Models\PropertyStatus::create(['name' => 'Available']);

        $property = \App\Models\Property::query()->create([
            'title' => 'Moderation target',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 140000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'listing_type' => 'regular',
            'created_by' => $admin->id,
            'agent_id' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'approved',
            'listing_type' => 'urgent',
        ]);

        $response->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_PROTECTED_FIELD');
        $this->patchJson("/api/properties/{$property->id}/moderation-listing", [])
            ->assertStatus(410)->assertJsonPath('code', 'MODERATION_ENDPOINT_RETIRED');

        $property->refresh();
        $this->assertSame('regular', $property->listing_type);
        $this->assertSame('approved', $property->moderation_status);
        $this->assertNull($property->status_comment);
    }

    public function test_custom_role_closed_card_and_history_permissions_are_independent(): void
    {
        [$owner, $property] = $this->createListingDateRefreshFixture('sold', '930000412');
        $role = Role::create(['name' => 'Closed card reader', 'slug' => 'closed_card_reader']);
        $reader = User::create(['name' => 'Reader', 'phone' => '930000413', 'role_id' => $role->id, 'status' => 'active']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $grant = function (array $permissions) use ($role, $reader): void {
            $role->forceFill(['permissions' => $permissions])->save();
            Sanctum::actingAs($reader->fresh());
        };
        $grant(['properties.history.view']);
        $this->getJson('/api/properties/'.$property->id)->assertNotFound();
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        $grant(['properties.closed.view']);
        $this->getJson('/api/properties/'.$property->id)->assertOk()
            ->assertJsonPath('capabilities.can_edit', false)
            ->assertJsonPath('capabilities.can_view_history', false);
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        $grant(['properties.closed.view', 'properties.history.view']);
        foreach (['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'] as $status) {
            $property->forceFill(['moderation_status' => $status])->save();
            $this->getJson('/api/properties/'.$property->id)->assertOk();
            $this->getJson('/api/properties/'.$property->id.'/logs')->assertOk();
        }
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertOk();
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertForbidden();
        foreach (['draft', 'pending'] as $status) {
            $property->forceFill(['moderation_status' => $status])->save();
            $this->getJson('/api/properties/'.$property->id)->assertNotFound();
            $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        }
        $property->forceFill(['moderation_status' => 'sold'])->save();
        $grant([]);
        $this->getJson('/api/properties/'.$property->id)->assertNotFound();
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
    }

    public function test_security_history_default_preserves_closed_stage_boundary_and_can_be_revoked(): void
    {
        [$owner, $property] = $this->createListingDateRefreshFixture('sold', '930000410');
        $role = Role::create(['name' => 'Security', 'slug' => 'security']);
        $security = User::create(['name' => 'Security', 'phone' => '930000411', 'role_id' => $role->id, 'status' => 'active']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        Sanctum::actingAs($security->fresh());
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertOk();
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertForbidden();
        $property->forceFill(['moderation_status' => 'approved'])->save();
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        $property->forceFill(['moderation_status' => 'sold'])->save();
        $role->forceFill(['permissions' => []])->save();
        Sanctum::actingAs($security->fresh());
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
    }

    public function test_history_and_listing_refresh_permissions_can_be_revoked_independently(): void
    {
        [$owner, $property] = $this->createListingDateRefreshFixture('approved', '930000409');
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role = Role::create(['name' => 'Custom editor', 'slug' => 'custom_history_editor', 'permissions' => ['properties.edit']]);
        $owner->forceFill(['role_id' => $role->id])->save();
        $grant = function (array $permissions) use ($role, $owner): void {
            $role->forceFill(['permissions' => ['properties.edit', ...$permissions]])->save();
            Sanctum::actingAs($owner->fresh());
        };
        $grant([]);
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertForbidden();
        $grant(['properties.history.view']);
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertOk();
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertForbidden();
        $grant(['properties.listing_date.refresh']);
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertForbidden();
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertOk()
            ->assertJsonPath('data.capabilities.can_manage_listing_date', true)
            ->assertJsonPath('data.capabilities.can_refresh_listing_date', false);
        $grant([]);
        $this->postJson('/api/properties/'.$property->id.'/refresh-listing-date')->assertForbidden();
        $this->getJson('/api/properties/'.$property->id)->assertOk()
            ->assertJsonPath('capabilities.can_manage_listing_date', false)
            ->assertJsonPath('capabilities.can_view_history', false);
    }

    public function test_owner_can_refresh_listing_date_without_changing_created_at_and_cooldown_blocks_repeat(): void
    {
        config(['property-listing.date_refresh_cooldown_seconds' => 86_400]);
        Carbon::setTestNow('2026-08-14 10:00:00');

        [$owner, $property] = $this->createListingDateRefreshFixture('approved', '930000401');
        $createdAt = $property->getRawOriginal('created_at');
        $oldListingUpdatedAt = $property->listing_updated_at?->toJSON();

        Carbon::setTestNow('2026-08-14 12:00:00');
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/properties/{$property->id}/refresh-listing-date", [
            'listing_updated_at' => '2000-01-01T00:00:00Z',
            'created_at' => '2000-01-01T00:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Дата объявления обновлена')
            ->assertJsonPath('data.id', $property->id)
            ->assertJsonPath('data.created_at', '2026-08-14T05:00:00.000000Z')
            ->assertJsonPath('data.listing_updated_at', '2026-08-14T07:00:00.000000Z')
            ->assertJsonPath('data.capabilities.can_refresh_listing_date', false)
            ->assertJsonPath('data.listing_date_refresh.available', false)
            ->assertJsonPath('data.listing_date_refresh.next_available_at', '2026-08-15T07:00:00.000000Z');

        $property->refresh();
        $this->assertSame((string) $createdAt, (string) $property->getRawOriginal('created_at'));
        $this->assertNotSame($oldListingUpdatedAt, $property->listing_updated_at?->toJSON());
        $this->assertDatabaseHas('property_logs', [
            'property_id' => $property->id,
            'user_id' => $owner->id,
            'action' => 'listing_date_refreshed',
        ]);

        $this->postJson("/api/properties/{$property->id}/refresh-listing-date")
            ->assertStatus(409)
            ->assertJsonPath('code', 'LISTING_DATE_REFRESH_COOLDOWN')
            ->assertJsonPath('next_available_at', '2026-08-15T07:00:00.000000Z');

        $this->assertSame(1, \App\Models\PropertyLog::query()
            ->where('property_id', $property->id)
            ->where('action', 'listing_date_refreshed')
            ->count());
    }

    public function test_listing_date_refresh_rejects_disallowed_status_and_user_without_mutation_access(): void
    {
        [$owner, $pendingProperty] = $this->createListingDateRefreshFixture('pending', '930000402');
        Sanctum::actingAs($owner);

        $this->postJson("/api/properties/{$pendingProperty->id}/refresh-listing-date")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LISTING_DATE_REFRESH_STATUS_NOT_ALLOWED');

        [, $approvedProperty] = $this->createListingDateRefreshFixture('approved', '930000403');
        $otherRole = Role::create(['name' => 'Other agent', 'slug' => 'other-agent']);
        $otherUser = User::create([
            'name' => 'Other User',
            'phone' => '930000404',
            'password' => bcrypt('password'),
            'role_id' => $otherRole->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);

        $this->postJson("/api/properties/{$approvedProperty->id}/refresh-listing-date")
            ->assertForbidden();
    }

    public function test_authenticated_property_show_exposes_refresh_capability_and_guest_does_not(): void
    {
        [$owner, $property] = $this->createListingDateRefreshFixture('approved', '930000405');
        $property->update(['bathroom_type' => 'combined']);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('bathroom_type', 'combined')
            ->assertJsonMissingPath('capabilities')
            ->assertJsonMissingPath('listing_date_refresh');

        Sanctum::actingAs($owner);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('capabilities.can_edit', true)
            ->assertJsonPath('capabilities.can_refresh_listing_date', true)
            ->assertJsonPath('listing_date_refresh.available', true)
            ->assertJsonPath('listing_date_refresh.next_available_at', null);
    }

    private function createListingDateRefreshFixture(string $moderationStatus, string $phone): array
    {
        $role = Role::firstOrCreate(
            ['slug' => 'agent'],
            ['name' => 'Agent']
        );
        $owner = User::create([
            'name' => 'Listing Owner',
            'phone' => $phone,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment '.$phone]);
        $property = \App\Models\Property::query()->create([
            'title' => 'Listing refresh target',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => $moderationStatus,
            'created_by' => $owner->id,
            'agent_id' => $owner->id,
        ]);

        return [$owner, $property];
    }
    public function test_moderation_workflow_is_enforced_through_http_crud_and_actions(): void
    {
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::forceCreate(['name' => 'Agent', 'phone' => '930000009', 'role_id' => $role->id, 'status' => 'active']);
        $type = \App\Models\PropertyType::create(['name' => 'Apartment']);
        Sanctum::actingAs($agent);
        $payload = ['type_id' => $type->id, 'price' => 100000, 'currency' => 'TJS', 'offer_type' => 'sale', 'bathroom_type' => 'combined'];

        $this->postJson('/api/properties', $payload + ['moderation_status' => 'approved'])
            ->assertUnprocessable()->assertJsonPath('code', 'PROTECTED_FIELD');
        $this->postJson('/api/properties', $payload + ['listing_type' => 'vip'])
            ->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_PROTECTED_FIELD');
        $created = $this->postJson('/api/properties', $payload)->assertOk()->assertJsonPath('publication_status', 'published');
        $id = $created->json('id');
        $this->assertSame('combined', \App\Models\Property::findOrFail($id)->approved_content_snapshot['bathroom_type']);
        $version = $created->json('moderation_version');
        $key = ['Idempotency-Key' => 'moderation-price-change-001'];
        $changed = $this->putJson("/api/properties/{$id}", ['price' => 100100, 'version' => $version] + $payload, $key)
            ->assertOk()->assertJsonPath('publication_status', 'pending');
        $this->putJson("/api/properties/{$id}", ['price' => 100100, 'version' => $version] + $payload, $key)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->putJson("/api/properties/{$id}", ['price' => 100101, 'version' => $version] + $payload)
            ->assertConflict()->assertJsonPath('code', 'MODERATION_VERSION_CONFLICT');
        $case = \App\Models\PropertyModerationCase::where('property_id', $id)->firstOrFail();
        $this->postJson("/api/property-moderation-cases/{$case->id}/approve", ['version' => $case->version])
            ->assertForbidden();
        $this->postJson("/api/properties/{$id}/moderation/submit", ['version' => $changed->json('moderation_version'), 'listing_type' => 'urgent'])
            ->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_PROTECTED_FIELD');
        $this->assertSame(1, \App\Models\PropertyModerationCase::where('property_id', $id)->count());
        $clientRole = Role::create(['name' => 'Client', 'slug' => 'client']);
        $client = User::forceCreate(['name' => 'Client', 'phone' => '930000010', 'role_id' => $clientRole->id, 'status' => 'active']);
        Sanctum::actingAs($client);
        $this->getJson("/api/properties/{$id}")->assertNotFound();

    }

}
