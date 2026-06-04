<?php

namespace Tests\Feature;

use App\Models\Developer;
use App\Models\Location;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertySearchFeatureTest extends TestCase
{
    private Role $agentRole;
    private User $agent;
    private PropertyType $apartmentType;
    private PropertyType $houseType;
    private PropertyStatus $activeStatus;
    private Location $dushanbe;
    private Developer $developer;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
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

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
            $table->string('district')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();
        });

        Schema::create('developers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('moderation_status')->default('approved');
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('USD');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->float('total_area')->nullable();
            $table->string('moderation_status')->default('approved');
            $table->string('landmark')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('owner_name')->nullable();
            $table->unsignedBigInteger('developer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
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

        $this->agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $this->agent = User::create([
            'name' => 'Агент',
            'phone' => '992900000001',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'status' => 'active',
        ]);

        $this->apartmentType = PropertyType::create(['name' => 'Apartment', 'slug' => 'apartment']);
        $this->houseType = PropertyType::create(['name' => 'House', 'slug' => 'house']);
        $this->activeStatus = PropertyStatus::create(['name' => 'Active', 'slug' => 'active']);
        $this->dushanbe = Location::create(['city' => 'Душанбе', 'district' => 'Сино']);
        $this->developer = Developer::create(['name' => 'Aura Development']);
    }

    public function test_short_query_returns_clear_validation_message(): void
    {
        $this->getJson('/api/properties/search?q=a')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Минимум 2 символа для поиска');
    }

    public function test_public_search_finds_by_id_and_hides_non_public_properties(): void
    {
        $visible = $this->createProperty([
            'title' => '2 room apartment',
            'district' => 'Сино',
        ]);

        $hidden = $this->createProperty([
            'title' => 'Hidden apartment',
            'moderation_status' => 'draft',
        ]);

        $response = $this->getJson('/api/properties/search?q=' . $visible->id);

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.id', $visible->id);
        $response->assertJsonMissing(['id' => $hidden->id]);
    }

    public function test_filters_work_together_with_text_query_and_pagination(): void
    {
        $match = $this->createProperty([
            'title' => 'Modern apartment near park',
            'district' => 'Сино',
            'price' => 85000,
            'rooms' => 2,
            'total_area' => 64,
            'offer_type' => 'sale',
            'type_id' => $this->apartmentType->id,
        ]);

        $this->createProperty([
            'title' => 'Modern apartment near park',
            'district' => 'Сино',
            'price' => 150000,
            'rooms' => 4,
            'total_area' => 120,
            'offer_type' => 'rent',
            'type_id' => $this->houseType->id,
        ]);

        $response = $this->getJson('/api/properties/search?' . http_build_query([
            'q' => 'modern',
            'deal_type' => 'sale',
            'property_type_id' => $this->apartmentType->id,
            'price_from' => 80000,
            'price_to' => 90000,
            'rooms_from' => 2,
            'rooms_to' => 2,
            'area_from' => 60,
            'area_to' => 70,
            'page' => 1,
            'per_page' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('per_page', 1);
        $response->assertJsonPath('data.0.id', $match->id);
        $response->assertJsonPath('data.0.location.name', 'Душанбе');
        $response->assertJsonPath('data.0.type', 'apartment');
        $response->assertJsonPath('data.0.creator.phone', '992900000001');
    }

    public function test_owner_name_search_requires_bearer_token(): void
    {
        $property = $this->createProperty([
            'title' => 'Quiet apartment',
            'owner_name' => 'CRM Owner',
        ]);

        $this->getJson('/api/properties/search?q=owner')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $token = $this->agent->createToken('api-token', ['*'], now()->addHours(24))->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/properties/search?q=owner')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $property->id);
    }

    private function createProperty(array $attributes = []): Property
    {
        $property = Property::create(array_merge([
            'title' => '2 room apartment',
            'description' => 'Good property',
            'type_id' => $this->apartmentType->id,
            'status_id' => $this->activeStatus->id,
            'location_id' => $this->dushanbe->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'rooms' => 2,
            'total_area' => 64,
            'moderation_status' => 'approved',
            'district' => 'Сино',
            'address' => 'Душанбе, Сино',
            'landmark' => 'Парк',
            'created_by' => $this->agent->id,
            'developer_id' => $this->developer->id,
        ], $attributes));

        PropertyPhoto::create([
            'property_id' => $property->id,
            'file_path' => 'properties/' . $property->id . '.jpg',
            'position' => 0,
        ]);

        return $property;
    }
}
