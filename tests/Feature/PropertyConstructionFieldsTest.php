<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('owner_name')->nullable();
            $table->string('object_key')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->text('status_comment')->nullable();
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
            'features' => [],
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
            'tags' => [],
        ]);

        $cleared->assertOk()->assertJsonCount(0, 'tags');
        $this->assertDatabaseMissing('property_tag', ['property_id' => $propertyId]);
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
            'moderation_status' => 'approved',
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
        $response->assertJsonValidationErrors(['construction_status']);
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

    public function test_rop_can_set_urgent_listing_type_without_auto_moderation(): void
    {
        $ropRole = Role::create([
            'name' => 'ROP',
            'slug' => 'rop',
        ]);
        $rop = User::create([
            'name' => 'Rop User',
            'phone' => '930000230',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
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
            'created_by' => $rop->id,
            'agent_id' => $rop->id,
        ]);

        Sanctum::actingAs($rop);

        $response = $this->patchJson("/api/properties/{$property->id}/moderation-listing", [
            'moderation_status' => 'approved',
            'listing_type' => 'urgent',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.listing_type', 'urgent');
        $response->assertJsonPath('data.moderation_status', 'approved');

        $property->refresh();
        $this->assertSame('urgent', $property->listing_type);
        $this->assertSame('approved', $property->moderation_status);
        $this->assertNull($property->status_comment);
    }
}
