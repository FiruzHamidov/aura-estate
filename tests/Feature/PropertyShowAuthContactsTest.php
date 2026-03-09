<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientType;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyShowAuthContactsTest extends TestCase
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

        Schema::create('client_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_business')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
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

        Schema::create('building_types', function (Blueprint $table) {
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
            $table->string('moderation_status')->default('approved');
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
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->string('object_key')->nullable();
            $table->boolean('is_business_owner')->default(false);
            $table->unsignedBigInteger('developer_id')->nullable();
            $table->boolean('is_full_apartment')->default(false);
            $table->boolean('is_for_aura')->default(false);
            $table->unsignedBigInteger('parking_type_id')->nullable();
            $table->unsignedBigInteger('heating_type_id')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->text('status_comment')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->string('actual_sale_currency')->nullable();
            $table->decimal('company_commission_amount', 15, 2)->nullable();
            $table->string('company_commission_currency')->nullable();
            $table->string('money_holder')->nullable();
            $table->timestamp('money_received_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('deposit_currency')->nullable();
            $table->timestamp('deposit_received_at')->nullable();
            $table->timestamp('deposit_taken_at')->nullable();
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency')->nullable();
            $table->timestamp('planned_contract_signed_at')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable();
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
    }

    public function test_public_property_show_with_bearer_token_includes_owner_and_buyer_contacts(): void
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

        $individualType = ClientType::create([
            'name' => 'Individual',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
        ]);

        $businessType = ClientType::create([
            'name' => 'Business',
            'slug' => ClientType::SLUG_BUSINESS_OWNER,
            'is_business' => true,
        ]);

        $ownerClient = Client::create([
            'full_name' => 'Owner Client',
            'phone' => '930000201',
            'client_type_id' => $individualType->id,
            'contact_kind' => Client::CONTACT_KIND_SELLER,
        ]);

        $buyerClient = Client::create([
            'full_name' => 'Buyer Client',
            'phone' => '930000202',
            'client_type_id' => $businessType->id,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
        ]);

        $propertyType = PropertyType::create(['name' => 'Apartment']);
        $propertyStatus = PropertyStatus::create(['name' => 'Available']);

        $property = Property::create([
            'title' => 'Test property',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 250000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'owner_client_id' => $ownerClient->id,
            'owner_name' => 'Owner Snapshot',
            'owner_phone' => '930000201',
            'buyer_client_id' => $buyerClient->id,
            'buyer_full_name' => 'Buyer Snapshot',
            'buyer_phone' => '930000202',
        ]);

        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/properties/' . $property->id);

        $response->assertOk();
        $response->assertJsonPath('owner_client_id', $ownerClient->id);
        $response->assertJsonPath('owner_name', 'Owner Snapshot');
        $response->assertJsonPath('owner_phone', '930000201');
        $response->assertJsonPath('owner_client.id', $ownerClient->id);
        $response->assertJsonPath('owner_client.full_name', 'Owner Client');
        $response->assertJsonPath('ownerClient.id', $ownerClient->id);
        $response->assertJsonPath('buyer_client_id', $buyerClient->id);
        $response->assertJsonPath('buyer_full_name', 'Buyer Snapshot');
        $response->assertJsonPath('buyer_phone', '930000202');
        $response->assertJsonPath('buyer_client.id', $buyerClient->id);
        $response->assertJsonPath('buyer_client.full_name', 'Buyer Client');
        $response->assertJsonPath('buyerClient.id', $buyerClient->id);
    }
}
