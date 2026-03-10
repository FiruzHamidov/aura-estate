<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    private int $phoneCounter = 930000000;

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

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('contact_visibility_mode', 32)->default(BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
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
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_need_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_closed')->default(false);
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

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->timestamp('closed_at')->nullable();
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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
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
            $table->unsignedBigInteger('contract_type_id')->nullable();
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
            $table->text('comment')->nullable();
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
            'id' => 1,
            'name' => 'Покупка',
            'slug' => 'buy',
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_statuses')->insert([
            'id' => 1,
            'name' => 'Новая',
            'slug' => 'new',
            'is_closed' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_agent_sees_all_clients_from_own_branch_in_all_branch_mode(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $foreignAgent = $this->createUser($agentRole, $branchB, 'Agent C');

        $clientA1 = $this->createClient($branchA, $agentA, $agentA, 'Client A1');
        $clientA2 = $this->createClient($branchA, $agentB, $agentB, 'Client A2');
        $this->createClient($branchB, $foreignAgent, $foreignAgent, 'Client B1');

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/clients');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $clientA1->id]);
        $response->assertJsonFragment(['id' => $clientA2->id]);
        $response->assertJsonMissing(['full_name' => 'Client B1']);
    }

    public function test_agent_in_own_only_mode_sees_only_own_clients_and_cannot_open_foreign_one(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');

        $ownClient = $this->createClient($branch, $agentA, $agentA, 'Own Client');
        $foreignClient = $this->createClient($branch, $agentB, $agentB, 'Foreign Client');

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/clients');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $ownClient->id);

        $this->getJson('/api/clients/' . $foreignClient->id)->assertForbidden();
    }

    public function test_branch_director_sees_all_clients_from_own_branch_even_in_own_only_mode(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);

        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $director = $this->createUser($directorRole, $branchA, 'Director A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        $clientA = $this->createClient($branchA, $agentA, $agentA, 'Client A');
        $clientB = $this->createClient($branchB, $agentB, $agentB, 'Client B');

        Sanctum::actingAs($director);

        $response = $this->getJson('/api/clients');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $clientA->id);
        $response->assertJsonMissing(['full_name' => 'Client B']);
    }

    public function test_agent_sees_branch_buyers_but_only_own_sellers_in_all_branch_mode(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');

        $buyerA = $this->createClient($branch, $agentA, $agentA, 'Buyer A', 1, Client::CONTACT_KIND_BUYER);
        $buyerB = $this->createClient($branch, $agentB, $agentB, 'Buyer B', 1, Client::CONTACT_KIND_BUYER);
        $sellerA = $this->createClient($branch, $agentA, $agentA, 'Seller A', 1, Client::CONTACT_KIND_SELLER);
        $sellerB = $this->createClient($branch, $agentB, $agentB, 'Seller B', 1, Client::CONTACT_KIND_SELLER);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $buyerA->id])
            ->assertJsonFragment(['id' => $buyerB->id])
            ->assertJsonFragment(['id' => $sellerA->id])
            ->assertJsonMissing(['full_name' => $sellerB->full_name]);

        $this->getJson('/api/clients/' . $buyerB->id)->assertOk();
        $this->getJson('/api/clients/' . $sellerB->id)->assertForbidden();
    }

    public function test_agent_does_not_see_any_sellers_when_seller_visibility_setting_is_disabled(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '0',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');

        $buyerA = $this->createClient($branch, $agentA, $agentA, 'Buyer A', 1, Client::CONTACT_KIND_BUYER);
        $buyerB = $this->createClient($branch, $agentB, $agentB, 'Buyer B', 1, Client::CONTACT_KIND_BUYER);
        $sellerA = $this->createClient($branch, $agentA, $agentA, 'Seller A', 1, Client::CONTACT_KIND_SELLER);
        $sellerB = $this->createClient($branch, $agentB, $agentB, 'Seller B', 1, Client::CONTACT_KIND_SELLER);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $buyerA->id])
            ->assertJsonFragment(['id' => $buyerB->id])
            ->assertJsonMissing(['id' => $sellerA->id])
            ->assertJsonMissing(['id' => $sellerB->id]);

        $this->getJson('/api/clients/' . $buyerB->id)->assertOk();
        $this->getJson('/api/clients/' . $sellerA->id)->assertForbidden();
        $this->getJson('/api/clients/' . $sellerB->id)->assertForbidden();
    }

    public function test_agent_in_own_only_mode_does_not_see_own_seller_when_seller_visibility_setting_is_disabled(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '0',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $ownBuyer = $this->createClient($branch, $agent, $agent, 'Buyer A', 1, Client::CONTACT_KIND_BUYER);
        $ownSeller = $this->createClient($branch, $agent, $agent, 'Seller A', 1, Client::CONTACT_KIND_SELLER);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownBuyer->id)
            ->assertJsonMissing(['id' => $ownSeller->id]);

        $this->getJson('/api/clients/' . $ownSeller->id)->assertForbidden();
    }

    public function test_branch_director_can_filter_sellers_and_see_entire_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $director = $this->createUser($directorRole, $branchA, 'Director A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        $sellerA = $this->createClient($branchA, $agentA, $agentA, 'Seller A', 1, Client::CONTACT_KIND_SELLER);
        $this->createClient($branchA, $agentA, $agentA, 'Buyer A', 1, Client::CONTACT_KIND_BUYER);
        $this->createClient($branchB, $agentB, $agentB, 'Seller B', 1, Client::CONTACT_KIND_SELLER);

        Sanctum::actingAs($director);

        $this->getJson('/api/clients?contact_kind=seller')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sellerA->id)
            ->assertJsonMissing(['full_name' => 'Seller B']);
    }

    public function test_agent_create_forces_branch_to_own_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $groupA = $this->createBranchGroup($branchA, 'Group A');
        $groupB = $this->createBranchGroup($branchB, 'Group B');

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branchA, 'Agent A', $groupA);

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'New Client',
            'phone' => '+992 90 000 0001',
            'branch_id' => $branchB->id,
            'branch_group_id' => $groupB->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('branch_id', $branchA->id);
        $response->assertJsonPath('branch_group_id', $groupA->id);
        $response->assertJsonPath('branch_group.id', $groupA->id);
        $response->assertJsonPath('responsible_agent_id', $agent->id);
        $response->assertJsonPath('created_by', $agent->id);
        $response->assertJsonPath('contact_kind', Client::CONTACT_KIND_BUYER);
    }

    public function test_admin_can_update_client_settings_independently(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');

        Sanctum::actingAs($admin);

        $this->putJson('/api/clients/settings', [
            'agent_visibility_mode' => ClientAccess::VISIBILITY_OWN_ONLY,
            'agent_can_view_sellers' => true,
        ])
            ->assertOk()
            ->assertJsonPath('agent_visibility_mode', ClientAccess::VISIBILITY_OWN_ONLY)
            ->assertJsonPath('agent_can_view_sellers', true);

        $this->patchJson('/api/clients/settings', [
            'agent_can_view_sellers' => false,
        ])
            ->assertOk()
            ->assertJsonPath('agent_visibility_mode', ClientAccess::VISIBILITY_OWN_ONLY)
            ->assertJsonPath('agent_can_view_sellers', false);

        $this->getJson('/api/clients/settings')
            ->assertOk()
            ->assertJsonPath('agent_visibility_mode', ClientAccess::VISIBILITY_OWN_ONLY)
            ->assertJsonPath('agent_can_view_sellers', false);
    }

    public function test_admin_can_create_business_client_with_client_type(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'Business Client',
            'phone' => '+992900000111',
            'branch_id' => $branch->id,
            'client_type_id' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('client_type_id', 2);
        $response->assertJsonPath('type.slug', ClientType::SLUG_BUSINESS_OWNER);
        $response->assertJsonPath('is_business_client', true);
    }

    public function test_admin_can_filter_clients_by_business_and_type(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');

        $businessClient = $this->createClient($branch, $admin, $admin, 'Business Client', 2);
        $this->createClient($branch, $admin, $admin, 'Regular Client', 1);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/clients?is_business=1&client_type_id=2');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $businessClient->id);
        $response->assertJsonPath('data.0.is_business_client', true);
    }

    public function test_agent_from_group_only_sees_only_contacts_from_own_group(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $groupA = $this->createBranchGroup($branch, 'Group A', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
        $groupB = $this->createBranchGroup($branch, 'Group B', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $groupA);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $groupB);

        $clientA = $this->createClient($branch, $agentA, $agentA, 'Client A', 1, Client::CONTACT_KIND_BUYER, $groupA);
        $clientB = $this->createClient($branch, $agentB, $agentB, 'Client B', 1, Client::CONTACT_KIND_BUYER, $groupB);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $clientA->id)
            ->assertJsonPath('data.0.branch_group.id', $groupA->id)
            ->assertJsonMissing(['id' => $clientB->id]);

        $this->getJson('/api/clients/' . $clientB->id)->assertForbidden();
    }

    public function test_agent_from_branch_visible_group_sees_contacts_from_entire_branch(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $groupA = $this->createBranchGroup($branch, 'Group A', BranchGroup::CONTACT_VISIBILITY_BRANCH);
        $groupB = $this->createBranchGroup($branch, 'Group B', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $groupA);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $groupB);

        $clientA = $this->createClient($branch, $agentA, $agentA, 'Client A', 1, Client::CONTACT_KIND_BUYER, $groupA);
        $clientB = $this->createClient($branch, $agentB, $agentB, 'Client B', 1, Client::CONTACT_KIND_BUYER, $groupB);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $clientA->id])
            ->assertJsonFragment(['id' => $clientB->id]);

        $this->getJson('/api/clients/' . $clientB->id)->assertOk();
    }

    public function test_own_only_setting_is_applied_after_group_scope(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $groupA = $this->createBranchGroup($branch, 'Group A', BranchGroup::CONTACT_VISIBILITY_BRANCH);
        $groupB = $this->createBranchGroup($branch, 'Group B', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $groupA);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $groupB);

        $ownClient = $this->createClient($branch, $agentA, $agentA, 'Own Client', 1, Client::CONTACT_KIND_BUYER, $groupA);
        $foreignClient = $this->createClient($branch, $agentB, $agentB, 'Foreign Client', 1, Client::CONTACT_KIND_BUYER, $groupB);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownClient->id)
            ->assertJsonMissing(['id' => $foreignClient->id]);
    }

    public function test_branch_director_sees_all_groups_in_own_branch(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $groupA1 = $this->createBranchGroup($branchA, 'Group A1', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
        $groupA2 = $this->createBranchGroup($branchA, 'Group A2', BranchGroup::CONTACT_VISIBILITY_BRANCH);
        $groupB1 = $this->createBranchGroup($branchB, 'Group B1', BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $director = $this->createUser($directorRole, $branchA, 'Director A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A', $groupA1);
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B', $groupA2);
        $foreignAgent = $this->createUser($agentRole, $branchB, 'Agent C', $groupB1);

        $clientA1 = $this->createClient($branchA, $agentA, $agentA, 'Client A1', 1, Client::CONTACT_KIND_BUYER, $groupA1);
        $clientA2 = $this->createClient($branchA, $agentB, $agentB, 'Client A2', 1, Client::CONTACT_KIND_BUYER, $groupA2);
        $this->createClient($branchB, $foreignAgent, $foreignAgent, 'Client B1', 1, Client::CONTACT_KIND_BUYER, $groupB1);

        Sanctum::actingAs($director);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $clientA1->id])
            ->assertJsonFragment(['id' => $clientA2->id])
            ->assertJsonMissing(['full_name' => 'Client B1']);
    }

    public function test_admin_cannot_assign_client_to_group_from_another_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $groupB = $this->createBranchGroup($branchB, 'Group B');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branchA, 'Admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/clients', [
            'full_name' => 'Wrong Group Client',
            'branch_id' => $branchA->id,
            'branch_group_id' => $groupB->id,
        ])->assertStatus(422);
    }

    public function test_admin_create_and_show_client_include_branch_group_relation(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'Grouped Client',
            'phone' => '+992900000111',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
        ]);

        $clientId = $response->json('id');

        $response->assertCreated();
        $response->assertJsonPath('branch_group.id', $group->id);
        $response->assertJsonPath('branch_group_id', $group->id);

        $this->getJson('/api/clients/' . $clientId)
            ->assertOk()
            ->assertJsonPath('branch_group.id', $group->id);
    }

    public function test_agent_can_update_own_property_when_hidden_client_stays_unchanged(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');
        $hiddenSeller = $this->createClient($branch, $agentB, $agentB, 'Hidden Seller', 1, Client::CONTACT_KIND_SELLER);

        $propertyType = PropertyType::create(['name' => 'Apartment']);
        $propertyStatus = PropertyStatus::create(['name' => 'Available']);
        $property = $this->createProperty($propertyType, $propertyStatus, $agentA, [
            'owner_client_id' => $hiddenSeller->id,
            'owner_name' => $hiddenSeller->full_name,
            'owner_phone' => $hiddenSeller->phone,
        ]);

        Sanctum::actingAs($agentA);

        $this->putJson('/api/properties/' . $property->id, [
            'title' => 'Updated title',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 260000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_client_id' => $hiddenSeller->id,
        ])
            ->assertOk()
            ->assertJsonPath('title', 'Updated title')
            ->assertJsonPath('owner_client_id', $hiddenSeller->id);
    }

    public function test_agent_cannot_reassign_property_to_hidden_client(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');
        $visibleSeller = $this->createClient($branch, $agentA, $agentA, 'Visible Seller', 1, Client::CONTACT_KIND_SELLER);
        $hiddenSeller = $this->createClient($branch, $agentB, $agentB, 'Hidden Seller', 1, Client::CONTACT_KIND_SELLER);

        $propertyType = PropertyType::create(['name' => 'Apartment']);
        $propertyStatus = PropertyStatus::create(['name' => 'Available']);
        $property = $this->createProperty($propertyType, $propertyStatus, $agentA, [
            'owner_client_id' => $visibleSeller->id,
            'owner_name' => $visibleSeller->full_name,
            'owner_phone' => $visibleSeller->phone,
        ]);

        Sanctum::actingAs($agentA);

        $this->putJson('/api/properties/' . $property->id, [
            'title' => 'Updated title',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 260000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'owner_client_id' => $hiddenSeller->id,
        ])->assertForbidden();
    }

    private function createUser(Role $role, Branch $branch, string $name, ?BranchGroup $branchGroup = null): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $branchGroup?->id,
            'status' => 'active',
        ]);
    }

    private function createBranchGroup(
        Branch $branch,
        string $name,
        string $contactVisibilityMode = BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY
    ): BranchGroup {
        return BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'contact_visibility_mode' => $contactVisibilityMode,
        ]);
    }

    private function createClient(
        Branch $branch,
        User $creator,
        User $responsibleAgent,
        string $fullName,
        int $clientTypeId = 1,
        string $contactKind = Client::CONTACT_KIND_BUYER,
        ?BranchGroup $branchGroup = null
    ): Client
    {
        return Client::create([
            'full_name' => $fullName,
            'phone' => '+992900000' . random_int(100, 999),
            'phone_normalized' => '992900000' . random_int(100, 999),
            'branch_id' => $branch->id,
            'branch_group_id' => $branchGroup?->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $responsibleAgent->id,
            'client_type_id' => $clientTypeId,
            'contact_kind' => $contactKind,
            'status' => 'active',
        ]);
    }

    private function createProperty(
        PropertyType $propertyType,
        PropertyStatus $propertyStatus,
        User $creator,
        array $overrides = []
    ): Property {
        return Property::create(array_merge([
            'title' => 'Test property',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 250000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $creator->id,
            'agent_id' => $creator->id,
        ], $overrides));
    }
}
