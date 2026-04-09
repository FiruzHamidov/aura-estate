<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\ClientType;
use App\Models\Property;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPropertyMatchingFeatureTest extends TestCase
{
    private int $phoneCounter = 970000000;

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
            $table->foreignId('branch_id')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('contact_visibility_mode')->default(BranchGroup::CONTACT_VISIBILITY_BRANCH);
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

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_type_id')->nullable()->constrained('client_types')->nullOnDelete();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default(Client::COLLABORATOR_ROLE_COLLABORATOR);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('client_need_types')->cascadeOnDelete();
            $table->foreignId('status_id')->constrained('client_need_statuses')->cascadeOnDelete();
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->decimal('budget_total', 15, 2)->nullable();
            $table->decimal('budget_cash', 15, 2)->nullable();
            $table->decimal('budget_mortgage', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('district')->nullable();
            $table->foreignId('property_type_id')->nullable()->constrained('property_types')->nullOnDelete();
            $table->foreignId('repair_type_id')->nullable()->constrained('repair_types')->nullOnDelete();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('wants_mortgage')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_need_id')->constrained('client_needs')->cascadeOnDelete();
            $table->foreignId('property_type_id')->constrained('property_types')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('type_id')->nullable()->constrained('property_types')->nullOnDelete();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('repair_type_id')->nullable()->constrained('repair_types')->nullOnDelete();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->enum('offer_type', ['rent', 'sale'])->default('sale');
            $table->unsignedInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->string('moderation_status')->default('approved');
            $table->string('district')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('buyer_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
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

        ClientType::create([
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        DB::table('client_need_types')->insert([
            ['id' => 1, 'name' => 'Покупка', 'slug' => 'buy', 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Аренда', 'slug' => 'rent', 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Инвестиция', 'slug' => 'invest', 'sort_order' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('client_need_statuses')->insert([
            ['id' => 1, 'name' => 'Новая', 'slug' => 'new', 'is_closed' => false, 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Закрыта', 'slug' => 'closed_success', 'is_closed' => true, 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);

        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);
    }

    public function test_property_matching_clients_returns_visible_matches_with_reasons(): void
    {
        [$agentA, $agentB, $propertyTypeId, $repairTypeId, $locationId] = $this->seedBaseContext();

        $visibleClient = $this->createClient($agentA, 'Visible Buyer');
        $hiddenClient = $this->createClient($agentB, 'Hidden Buyer');

        $visibleNeed = $this->createNeed($visibleClient, $agentA, [
            'location_id' => $locationId,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
            'repair_type_id' => $repairTypeId,
            'budget_from' => 100000,
            'budget_to' => 120000,
            'budget_total' => 120000,
            'rooms_from' => 2,
            'rooms_to' => 3,
            'area_from' => 70,
            'area_to' => 90,
            'wants_mortgage' => true,
        ]);

        $this->createNeed($hiddenClient, $agentB, [
            'location_id' => $locationId,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
            'budget_from' => 100000,
            'budget_to' => 120000,
            'budget_total' => 120000,
            'rooms_from' => 2,
            'rooms_to' => 3,
            'area_from' => 70,
            'area_to' => 90,
        ]);

        $property = Property::create([
            'title' => 'Center apartment',
            'type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'repair_type_id' => $repairTypeId,
            'price' => 110000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'rooms' => 3,
            'total_area' => 82,
            'district' => 'Center',
            'is_mortgage_available' => true,
            'moderation_status' => 'approved',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/properties/' . $property->id . '/matching-clients');

        $response->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.client_id', $visibleClient->id)
            ->assertJsonPath('matches.0.need_id', $visibleNeed->id)
            ->assertJsonPath('matches.0.match_level', 'excellent');

        $this->assertGreaterThanOrEqual(80, $response->json('matches.0.score'));
        $this->assertContains('Цена входит в бюджет', $response->json('matches.0.reasons'));
        $this->assertContains('Совпадает тип недвижимости', $response->json('matches.0.reasons'));
        $this->assertSame([$visibleClient->id], collect($response->json('matches'))->pluck('client_id')->all());
    }

    public function test_property_matching_clients_limits_agents_to_their_own_clients_even_with_branch_visibility(): void
    {
        [$agentA, $agentB, $propertyTypeId, $repairTypeId, $locationId] = $this->seedBaseContext();

        Setting::query()->updateOrCreate(
            ['key' => ClientAccess::VISIBILITY_SETTING_KEY],
            ['value' => ClientAccess::VISIBILITY_ALL_BRANCH]
        );

        $ownClient = $this->createClient($agentA, 'Own Buyer');
        $branchClient = $this->createClient($agentB, 'Branch Buyer');

        $ownNeed = $this->createNeed($ownClient, $agentA, [
            'location_id' => $locationId,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
            'repair_type_id' => $repairTypeId,
            'budget_from' => 100000,
            'budget_to' => 120000,
            'budget_total' => 120000,
            'rooms_from' => 2,
            'rooms_to' => 3,
            'area_from' => 70,
            'area_to' => 90,
        ]);

        $this->createNeed($branchClient, $agentB, [
            'location_id' => $locationId,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
            'repair_type_id' => $repairTypeId,
            'budget_from' => 100000,
            'budget_to' => 120000,
            'budget_total' => 120000,
            'rooms_from' => 2,
            'rooms_to' => 3,
            'area_from' => 70,
            'area_to' => 90,
        ]);

        $property = Property::create([
            'title' => 'Center apartment',
            'type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'repair_type_id' => $repairTypeId,
            'price' => 110000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'rooms' => 3,
            'total_area' => 82,
            'district' => 'Center',
            'moderation_status' => 'approved',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/properties/' . $property->id . '/matching-clients');

        $response->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.client_id', $ownClient->id)
            ->assertJsonPath('matches.0.need_id', $ownNeed->id);

        $this->assertSame([$ownClient->id], collect($response->json('matches'))->pluck('client_id')->all());
    }

    public function test_client_matching_properties_groups_results_by_open_need_and_skips_irrelevant_objects(): void
    {
        [$agentA, $agentB, $propertyTypeId, $repairTypeId, $locationId] = $this->seedBaseContext();

        $client = $this->createClient($agentA, 'Buyer');
        $need = $this->createNeed($client, $agentA, [
            'location_id' => $locationId,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
            'repair_type_id' => $repairTypeId,
            'budget_from' => 90000,
            'budget_to' => 130000,
            'budget_total' => 130000,
            'rooms_from' => 2,
            'rooms_to' => 3,
            'area_from' => 65,
            'area_to' => 95,
            'wants_mortgage' => true,
        ]);

        $this->createNeed($client, $agentA, [
            'type_id' => 1,
            'status_id' => 2,
            'district' => 'Center',
            'property_type_id' => $propertyTypeId,
        ]);

        $goodProperty = Property::create([
            'title' => 'Best match',
            'type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'repair_type_id' => $repairTypeId,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'rooms' => 3,
            'total_area' => 88,
            'district' => 'Center',
            'is_mortgage_available' => true,
            'moderation_status' => 'approved',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Property::create([
            'title' => 'Wrong offer type',
            'type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'rent',
            'rooms' => 3,
            'total_area' => 88,
            'district' => 'Center',
            'moderation_status' => 'approved',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Property::create([
            'title' => 'Sold property',
            'type_id' => $propertyTypeId,
            'location_id' => $locationId,
            'price' => 120000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'rooms' => 3,
            'total_area' => 88,
            'district' => 'Center',
            'moderation_status' => 'sold',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/clients/' . $client->id . '/matching-properties');

        $response->assertOk()
            ->assertJsonPath('needs.0.need.id', $need->id)
            ->assertJsonPath('needs.0.matches.0.property.id', $goodProperty->id)
            ->assertJsonPath('needs.0.matches.0.match_level', 'excellent');

        $matchedPropertyIds = collect($response->json('needs.0.matches'))->pluck('property.id')->all();
        $this->assertSame([$goodProperty->id], $matchedPropertyIds);
        $this->assertContains('Подходит по типу сделки', $response->json('needs.0.matches.0.reasons'));
    }

    private function seedBaseContext(): array
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ]);
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($role, $branch, $group, 'Agent A');
        $agentB = $this->createUser($role, $branch, $group, 'Agent B');
        $propertyTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Apartment',
            'slug' => 'apartment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repairTypeId = DB::table('repair_types')->insertGetId([
            'name' => 'Euro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Dushanbe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$agentA, $agentB, $propertyTypeId, $repairTypeId, $locationId];
    }

    private function createUser(Role $role, Branch $branch, BranchGroup $group, string $name): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);
    }

    private function createClient(User $owner, string $name): Client
    {
        return Client::create([
            'full_name' => $name,
            'phone' => '+99290000' . random_int(1000, 9999),
            'phone_normalized' => '99290000' . random_int(1000, 9999),
            'branch_id' => $owner->branch_id,
            'branch_group_id' => $owner->branch_group_id,
            'created_by' => $owner->id,
            'responsible_agent_id' => $owner->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }

    private function createNeed(Client $client, User $owner, array $overrides = []): ClientNeed
    {
        $need = ClientNeed::create(array_merge([
            'client_id' => $client->id,
            'type_id' => 1,
            'status_id' => 1,
            'currency' => 'USD',
            'created_by' => $owner->id,
            'responsible_agent_id' => $owner->id,
            'wants_mortgage' => false,
        ], $overrides));

        if (!empty($overrides['property_type_id'])) {
            DB::table('client_need_property_type')->insert([
                'client_need_id' => $need->id,
                'property_type_id' => $overrides['property_type_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $need;
    }
}
