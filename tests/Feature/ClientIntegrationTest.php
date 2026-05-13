<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientIntegrationTest extends TestCase
{
    private int $phoneCounter = 940000000;

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

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
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

        Schema::create('client_need_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
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
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_collaborators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32)->default(Client::COLLABORATOR_ROLE_COLLABORATOR);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('offer_type')->nullable();
            $table->integer('rooms')->nullable();
            $table->string('youtube_link')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
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
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('listing_type')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable();
            $table->unsignedInteger('views_count')->default(0);
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
            $table->string('actual_sale_currency', 3)->nullable();
            $table->decimal('company_commission_amount', 15, 2)->nullable();
            $table->string('company_commission_currency', 3)->nullable();
            $table->string('money_holder')->nullable();
            $table->timestamp('money_received_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('deposit_currency', 3)->nullable();
            $table->timestamp('deposit_received_at')->nullable();
            $table->timestamp('deposit_taken_at')->nullable();
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency', 3)->nullable();
            $table->timestamp('planned_contract_signed_at')->nullable();
            $table->timestamps();
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
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('crm_client_id')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('primary_property_id')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency', 3)->nullable();
            $table->timestamp('agent_paid_at')->nullable();
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

        DB::table('client_types')->insert([
            [
                'id' => 1,
                'name' => 'Физлицо',
                'slug' => ClientType::SLUG_INDIVIDUAL,
                'is_business' => false,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Бизнесмен',
                'slug' => ClientType::SLUG_BUSINESS_OWNER,
                'is_business' => true,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('client_need_types')->insert([
            ['id' => 1, 'name' => 'Покупка', 'slug' => 'buy', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Аренда', 'slug' => 'rent', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Продажа', 'slug' => 'sell', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Инвестиция', 'slug' => 'invest', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_booking_store_uses_new_client_entity_and_fills_snapshot_fields(): void
    {
        [$agent, $client, $property] = $this->seedClientContext();

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/bookings', [
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'client_id' => $client->id,
            'start_time' => '2026-03-07T10:00:00+05:00',
            'end_time' => '2026-03-07T11:00:00+05:00',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('booking.crm_client_id', $client->id);
        $response->assertJsonPath('booking.client_name', $client->full_name);
        $response->assertJsonPath('booking.client_phone', $client->phone);

        $client->refresh();
        $this->assertSame(Client::CONTACT_KIND_BUYER, $client->contact_kind);
    }

    public function test_property_store_links_owner_client_and_snapshots(): void
    {
        [$agent, $client] = $this->seedClientContext(withProperty: false);
        $typeId = DB::table('property_types')->insertGetId([
            'name' => 'Apartment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statusId = DB::table('property_statuses')->insertGetId([
            'name' => 'New',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/properties', [
            'title' => 'Client Owner Property',
            'type_id' => $typeId,
            'status_id' => $statusId,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'owner_client_id' => $client->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('owner_client_id', $client->id);
        $response->assertJsonPath('owner_name', $client->full_name);
        $response->assertJsonPath('owner_phone', $client->phone);

        $client->refresh();
        $this->assertSame(Client::CONTACT_KIND_SELLER, $client->contact_kind);
    }

    public function test_save_deal_links_buyer_client_and_snapshots(): void
    {
        [$agent, $client, $property] = $this->seedClientContext();

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/properties/'.$property->id.'/deal', [
            'moderation_status' => 'sold',
            'buyer_client_id' => $client->id,
            'actual_sale_price' => 150000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 5000,
            'company_commission_currency' => 'USD',
            'agents' => [],
        ]);

        $response->assertOk();

        $property->refresh();

        $this->assertSame($client->id, $property->buyer_client_id);
        $this->assertSame($client->full_name, $property->buyer_full_name);
        $this->assertSame($client->phone, $property->buyer_phone);
        $this->assertSame('sold', $property->moderation_status);
    }

    public function test_save_deal_allows_deposit_with_buyer_client_only(): void
    {
        [$agent, $client, $property] = $this->seedClientContext();

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/properties/'.$property->id.'/deal', [
            'moderation_status' => 'deposit',
            'buyer_client_id' => $client->id,
            'deposit_amount' => 10000,
            'deposit_currency' => 'TJS',
            'deposit_received_at' => '2026-03-17',
            'deposit_taken_at' => null,
            'planned_contract_signed_at' => '2026-03-17',
            'company_expected_income' => 1231231,
            'company_expected_income_currency' => 'TJS',
            'money_holder' => 'company',
        ]);

        $response->assertOk();

        $property->refresh();

        $this->assertSame('deposit', $property->moderation_status);
        $this->assertSame($client->id, $property->buyer_client_id);
        $this->assertSame($client->full_name, $property->buyer_full_name);
        $this->assertSame($client->phone, $property->buyer_phone);
    }

    public function test_same_contact_becomes_both_when_used_as_owner_and_buyer(): void
    {
        [$agent, $client] = $this->seedClientContext(withProperty: false);
        $typeId = DB::table('property_types')->insertGetId([
            'name' => 'Apartment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statusId = DB::table('property_statuses')->insertGetId([
            'name' => 'New',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $propertyId = $this->postJson('/api/properties', [
            'title' => 'Dual Role Client Property',
            'type_id' => $typeId,
            'status_id' => $statusId,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'owner_client_id' => $client->id,
        ])->assertOk()->json('id');

        $this->postJson('/api/properties/'.$propertyId.'/deal', [
            'moderation_status' => 'sold',
            'buyer_client_id' => $client->id,
            'actual_sale_price' => 140000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 4000,
            'company_commission_currency' => 'USD',
            'agents' => [],
        ])->assertOk();

        $client->refresh();
        $this->assertSame(Client::CONTACT_KIND_BOTH, $client->contact_kind);
    }

    public function test_agent_clients_stats_use_linked_client_type_with_legacy_fallback(): void
    {
        [$agent] = $this->seedClientContext(withProperty: false);

        $businessClient = Client::create([
            'full_name' => 'Business Buyer',
            'phone' => '+992 90 555 1001',
            'phone_normalized' => '992905551001',
            'branch_id' => $agent->branch_id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 2,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);

        Property::create([
            'title' => 'Property with linked business client',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 100000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'buyer_client_id' => $businessClient->id,
            'buyer_full_name' => $businessClient->full_name,
            'buyer_phone' => $businessClient->phone,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
        ]);

        Property::create([
            'title' => 'Legacy business property',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'buyer_full_name' => 'Legacy Buyer',
            'buyer_phone' => '+992 90 555 2002',
            'is_business_owner' => true,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/reports/agent/clients?agent_id='.$agent->id);

        $response->assertOk();
        $response->assertJsonPath('unique_clients', 2);
        $response->assertJsonPath('business_clients', 2);
    }

    public function test_manager_efficiency_returns_unique_clients_count_with_filters(): void
    {
        [$agent, $assignedClient] = $this->seedClientContext(withProperty: false);

        $property = Property::create([
            'title' => 'March property',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 150000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'owner_client_id' => $assignedClient->id,
            'buyer_full_name' => 'Legacy Buyer',
            'buyer_phone' => '+992 90 555 3001',
            'listing_type' => 'regular',
            'moderation_status' => 'approved',
            'created_at' => '2026-03-10 09:00:00',
            'updated_at' => '2026-03-10 09:00:00',
        ]);

        $soldClient = Client::create([
            'full_name' => 'Sold Client',
            'phone' => '+992 90 555 3002',
            'phone_normalized' => '992905553002',
            'branch_id' => $agent->branch_id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
        ]);

        Property::create([
            'title' => 'Sold in March',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 175000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'buyer_client_id' => $soldClient->id,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-02-15 09:00:00',
            'updated_at' => '2026-03-12 09:00:00',
            'sold_at' => '2026-03-12 10:00:00',
        ]);

        $showClient = Client::create([
            'full_name' => 'Show Client',
            'phone' => '+992 90 555 3003',
            'phone_normalized' => '992905553003',
            'branch_id' => $agent->branch_id,
            'created_by' => $agent->id,
            'responsible_agent_id' => null,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
            'created_at' => '2026-03-13 09:00:00',
            'updated_at' => '2026-03-13 09:00:00',
        ]);

        DB::table('bookings')->insert([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'client_id' => null,
            'crm_client_id' => $showClient->id,
            'start_time' => '2026-03-13 10:00:00',
            'end_time' => '2026-03-13 11:00:00',
            'status' => 'confirmed',
            'client_name' => $showClient->full_name,
            'client_phone' => $showClient->phone,
            'created_at' => '2026-03-13 09:00:00',
            'updated_at' => '2026-03-13 09:00:00',
        ]);

        DB::table('bookings')->insert([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'client_id' => null,
            'crm_client_id' => $showClient->id,
            'start_time' => '2026-03-14 10:00:00',
            'end_time' => '2026-03-14 11:00:00',
            'status' => 'confirmed',
            'client_name' => $showClient->full_name,
            'client_phone' => $showClient->phone,
            'created_at' => '2026-03-14 09:00:00',
            'updated_at' => '2026-03-14 09:00:00',
        ]);

        $dealClient = Client::create([
            'full_name' => 'Deal Client',
            'phone' => '+992 90 555 3004',
            'phone_normalized' => '992905553004',
            'branch_id' => $agent->branch_id,
            'created_by' => $agent->id,
            'responsible_agent_id' => null,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
            'created_at' => '2026-03-15 09:00:00',
            'updated_at' => '2026-03-15 09:00:00',
        ]);

        DB::table('crm_deals')->insert([
            'client_id' => $dealClient->id,
            'responsible_agent_id' => $agent->id,
            'primary_property_id' => $property->id,
            'created_at' => '2026-03-15 09:00:00',
            'updated_at' => '2026-03-15 09:00:00',
        ]);

        $outsideClient = Client::create([
            'full_name' => 'Outside Period Client',
            'phone' => '+992 90 555 3999',
            'phone_normalized' => '992905553999',
            'branch_id' => $agent->branch_id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
            'created_at' => '2026-02-10 09:00:00',
            'updated_at' => '2026-02-10 09:00:00',
        ]);

        Property::create([
            'title' => 'Outside property',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 90000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'buyer_client_id' => $outsideClient->id,
            'listing_type' => 'regular',
            'moderation_status' => 'approved',
            'created_at' => '2026-02-10 09:00:00',
            'updated_at' => '2026-02-10 09:00:00',
        ]);

        Sanctum::actingAs($agent);

        DB::table('clients')
            ->where('id', $outsideClient->id)
            ->update(['created_at' => '2000-01-01 00:00:00']);

        $from = Carbon::now()->startOfMonth()->toDateString();
        $to = Carbon::now()->endOfMonth()->toDateString();
        $response = $this->getJson('/api/reports/properties/manager-efficiency?agent_id='.$agent->id.'&date_from='.$from.'&date_to='.$to.'&branch_id='.$agent->branch_id);

        $response->assertOk();
        $response->assertJsonPath('0.agent_id', $agent->id);
        $response->assertJsonPath('0.unique_clients_count', 0);
    }

    public function test_manager_efficiency_attributes_closed_deals_to_sale_agent(): void
    {
        [$creator] = $this->seedClientContext(withProperty: false);

        $seller = User::create([
            'name' => 'Seller Agent',
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $creator->role_id,
            'branch_id' => $creator->branch_id,
            'status' => 'active',
        ]);

        $property = Property::create([
            'title' => 'Sold by another agent',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 180000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $creator->id,
            'agent_id' => $creator->id,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-03-01 09:00:00',
            'updated_at' => '2026-03-20 09:00:00',
            'sold_at' => '2026-03-20 09:00:00',
        ]);

        DB::table('property_agent_sales')->insert([
            'property_id' => $property->id,
            'agent_id' => $seller->id,
            'created_at' => '2026-03-20 09:05:00',
            'updated_at' => '2026-03-20 09:05:00',
        ]);

        Sanctum::actingAs($creator);

        $response = $this->getJson('/api/reports/properties/manager-efficiency?date_from=2026-03-01&date_to=2026-03-31&branch_id='.$creator->branch_id);
        $response->assertOk();

        $rowsById = collect($response->json())->keyBy('agent_id');

        $this->assertSame(1, (int) ($rowsById[$seller->id]['sold'] ?? 0));
        $this->assertSame(0, (int) ($rowsById[$creator->id]['sold'] ?? 0));
    }

    public function test_agent_earnings_report_filters_by_agent_id_not_creator(): void
    {
        [$agent] = $this->seedClientContext(withProperty: false);

        $creator = User::create([
            'name' => 'Different Creator',
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $agent->role_id,
            'branch_id' => $agent->branch_id,
            'status' => 'active',
        ]);

        Property::create([
            'title' => 'Agent owned deal',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 100000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $creator->id,
            'agent_id' => $agent->id,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-02-20 09:00:00',
            'updated_at' => '2026-03-10 09:00:00',
            'sold_at' => '2026-03-10 09:00:00',
        ]);

        Property::create([
            'title' => 'Creator owned deal',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 999999,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $creator->id,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-02-20 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
            'sold_at' => '2026-03-11 09:00:00',
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/reports/agent/earnings?agent_id='.$agent->id.'&date_from=2026-03-01&date_to=2026-03-31&branch_id='.$agent->branch_id);

        $response->assertOk();
        $response->assertJsonPath('sum_price', 100000);
        $response->assertJsonPath('total_amount', 100000);
        $response->assertJsonPath('closed_count', 1);
        $response->assertJsonPath('deals_count', 1);
        $response->assertJsonPath('earnings', 3000);
        $response->assertJsonPath('sold_count', 1);
        $response->assertJsonPath('rented_count', 0);
        $response->assertJsonPath('sold_by_owner_count', 0);
    }

    public function test_agent_properties_report_returns_contracts_object_and_period_aware_statuses(): void
    {
        [$agent] = $this->seedClientContext(withProperty: false);

        Property::create([
            'title' => 'Approved March',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 110000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'contract_type_id' => 2,
            'listing_type' => 'regular',
            'moderation_status' => 'approved',
            'created_at' => '2026-03-05 09:00:00',
            'updated_at' => '2026-03-05 09:00:00',
        ]);

        Property::create([
            'title' => 'Sold by sold_at in March',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'contract_type_id' => 3,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-02-05 09:00:00',
            'updated_at' => '2026-03-20 09:00:00',
            'sold_at' => '2026-03-20 09:00:00',
        ]);

        Property::create([
            'title' => 'Deleted March',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 130000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'contract_type_id' => 1,
            'listing_type' => 'regular',
            'moderation_status' => 'deleted',
            'created_at' => '2026-03-08 09:00:00',
            'updated_at' => '2026-03-08 09:00:00',
        ]);

        Property::create([
            'title' => 'Sold outside sold_at period',
            'type_id' => 1,
            'status_id' => 1,
            'price' => 140000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'contract_type_id' => 1,
            'listing_type' => 'regular',
            'moderation_status' => 'sold',
            'created_at' => '2026-03-09 09:00:00',
            'updated_at' => '2026-04-02 09:00:00',
            'sold_at' => '2026-04-02 09:00:00',
        ]);

        Sanctum::actingAs($agent);

        $single = $this->getJson('/api/reports/agents/'.$agent->id.'/properties?from=2026-03-01&to=2026-03-31');
        $single->assertOk();
        $single->assertJsonPath('summary.by_status.approved', 1);
        $single->assertJsonPath('summary.by_status.sold', 1);
        $single->assertJsonMissingPath('summary.by_status.deleted');
        $single->assertJsonPath('summary.contracts.exclusive', 1);
        $single->assertJsonPath('summary.contracts.none', 1);

        $list = $this->getJson('/api/reports/agents/properties?from=2026-03-01&to=2026-03-31&agent_id='.$agent->id);
        $list->assertOk();
        $list->assertJsonPath('0.summary.by_status.approved', 1);
        $list->assertJsonPath('0.summary.by_status.sold', 1);
        $list->assertJsonMissingPath('0.summary.by_status.deleted');
        $list->assertJsonPath('0.summary.contracts.exclusive', 1);
        $list->assertJsonPath('0.summary.contracts.none', 1);
    }

    public function test_marketing_cannot_access_agent_reports(): void
    {
        [$agent] = $this->seedClientContext();

        $marketingRole = Role::create(['name' => 'Marketing', 'slug' => 'marketing']);
        $marketing = User::create([
            'name' => 'Marketing A',
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $marketingRole->id,
            'branch_id' => $agent->branch_id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($marketing);

        $this->getJson('/api/reports/agent/clients?agent_id=' . $agent->id)->assertForbidden();
        $this->getJson('/api/bookings/agents-report?agent_id=' . $agent->id)->assertForbidden();
    }

    private function seedClientContext(bool $withProperty = true): array
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create([
            'name' => 'Agent A',
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $client = Client::create([
            'full_name' => 'Visible Client',
            'phone' => '+992 90 555 0001',
            'phone_normalized' => '992905550001',
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);

        DB::table('property_types')->insert([
            'id' => 1,
            'name' => 'Apartment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('property_statuses')->insert([
            'id' => 1,
            'name' => 'New',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $property = null;
        if ($withProperty) {
            $property = Property::create([
                'title' => 'Test Property',
                'type_id' => 1,
                'status_id' => 1,
                'price' => 100000,
                'currency' => 'USD',
                'offer_type' => 'sale',
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'listing_type' => 'regular',
                'moderation_status' => 'approved',
            ]);
        }

        return [$agent, $client, $property];
    }
}
