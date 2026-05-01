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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->string('youtube_link')->nullable();
            $table->float('total_area')->nullable();
            $table->unsignedInteger('land_size')->nullable();
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

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
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
}
