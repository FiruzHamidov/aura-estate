<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientNeedPropertyTypeFilterTest extends TestCase
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
            $table->unsignedBigInteger('branch_group_id')->nullable();
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
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('client_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
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
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('source_comment')->nullable();
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
            $table->unsignedBigInteger('status_id');
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->decimal('budget_total', 15, 2)->nullable();
            $table->decimal('budget_cash', 15, 2)->nullable();
            $table->decimal('budget_mortgage', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->unsignedInteger('rooms_from')->nullable();
            $table->unsignedInteger('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->boolean('wants_mortgage')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_need_id');
            $table->unsignedBigInteger('property_type_id');
            $table->timestamps();
            $table->unique(['client_need_id', 'property_type_id']);
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

        DB::table('client_types')->insert([
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
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

        DB::table('repair_types')->insert([
            [
                'id' => 1,
                'name' => 'Черновой',
                'slug' => 'rough',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Косметический',
                'slug' => 'cosmetic',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'Дизайнерский',
                'slug' => 'designer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('client_sources')->insert([
            [
                'id' => 1,
                'code' => 'phone',
                'name' => 'Телефон',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'code' => 'instagram',
                'name' => 'Instagram',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'code' => 'other',
                'name' => 'Другое',
                'is_active' => false,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_clients_index_filters_by_property_type_ids_across_legacy_and_pivot_storage(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $houseTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Дом',
            'slug' => 'houses',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apartmentTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Квартира',
            'slug' => 'apartments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyHouseClient = $this->createClient($branch, $agent, 'Legacy house');
        $pivotHouseClient = $this->createClient($branch, $agent, 'Pivot house');
        $apartmentClient = $this->createClient($branch, $agent, 'Apartment only');

        DB::table('client_needs')->insert([
            'client_id' => $legacyHouseClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $houseTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pivotNeedId = DB::table('client_needs')->insertGetId([
            'client_id' => $pivotHouseClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $apartmentTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_property_type')->insert([
            'client_need_id' => $pivotNeedId,
            'property_type_id' => $houseTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_needs')->insert([
            'client_id' => $apartmentClient->id,
            'type_id' => 1,
            'status_id' => 1,
            'property_type_id' => $apartmentTypeId,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?contact_kind=buyer&property_type_ids[]='.$houseTypeId.'&per_page=15')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $legacyHouseClient->id])
            ->assertJsonFragment(['id' => $pivotHouseClient->id])
            ->assertJsonMissing(['id' => $apartmentClient->id]);
    }

    public function test_clients_index_filters_by_multiple_property_type_ids(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $houseTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Дом',
            'slug' => 'houses',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apartmentTypeId = DB::table('property_types')->insertGetId([
            'name' => 'Квартира',
            'slug' => 'apartments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $houseClient = $this->createClient($branch, $agent, 'House client');
        $apartmentClient = $this->createClient($branch, $agent, 'Apartment client');
        $landClient = $this->createClient($branch, $agent, 'Land client');

        foreach ([
            [$houseClient->id, $houseTypeId],
            [$apartmentClient->id, $apartmentTypeId],
        ] as [$clientId, $propertyTypeId]) {
            DB::table('client_needs')->insert([
                'client_id' => $clientId,
                'type_id' => 1,
                'status_id' => 1,
                'property_type_id' => $propertyTypeId,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?property_type_ids[]='.$houseTypeId.'&property_type_ids[]='.$apartmentTypeId)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $houseClient->id])
            ->assertJsonFragment(['id' => $apartmentClient->id])
            ->assertJsonMissing(['id' => $landClient->id]);
    }

    public function test_clients_index_filters_by_single_repair_type_id_in_array_param(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $matchClient = $this->createClient($branch, $agent, 'Repair match');
        $otherClient = $this->createClient($branch, $agent, 'Repair other');

        DB::table('client_needs')->insert([
            [
                'client_id' => $matchClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $otherClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?repair_type_ids[]=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $matchClient->id])
            ->assertJsonMissing(['id' => $otherClient->id]);
    }

    public function test_clients_index_filters_by_multiple_repair_type_ids(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $firstClient = $this->createClient($branch, $agent, 'Repair one');
        $secondClient = $this->createClient($branch, $agent, 'Repair two');
        $thirdClient = $this->createClient($branch, $agent, 'Repair three');

        DB::table('client_needs')->insert([
            [
                'client_id' => $firstClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $secondClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $thirdClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 3,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?repair_type_ids=1,2')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $firstClient->id])
            ->assertJsonFragment(['id' => $secondClient->id])
            ->assertJsonMissing(['id' => $thirdClient->id]);
    }

    public function test_clients_index_ignores_empty_repair_type_ids_parameter(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();
        $firstClient = $this->createClient($branch, $agent, 'First client');
        $secondClient = $this->createClient($branch, $agent, 'Second client');

        DB::table('client_needs')->insert([
            [
                'client_id' => $firstClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $secondClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?repair_type_ids=')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $firstClient->id])
            ->assertJsonFragment(['id' => $secondClient->id]);
    }

    public function test_clients_index_keeps_backward_compatibility_for_repair_type_id_and_prioritizes_repair_type_ids(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $legacyClient = $this->createClient($branch, $agent, 'Legacy filter client');
        $priorityClient = $this->createClient($branch, $agent, 'Priority filter client');

        DB::table('client_needs')->insert([
            [
                'client_id' => $legacyClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 1,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'client_id' => $priorityClient->id,
                'type_id' => 1,
                'status_id' => 1,
                'repair_type_id' => 2,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?repair_type_id=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $legacyClient->id])
            ->assertJsonMissing(['id' => $priorityClient->id]);

        $this->getJson('/api/clients?repair_type_id=1&repair_type_ids[]=2')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $priorityClient->id])
            ->assertJsonMissing(['id' => $legacyClient->id]);
    }

    public function test_client_store_accepts_valid_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Source Test',
            'source_id' => 1,
            'source_comment' => 'С рекламы',
        ])->assertCreated()
            ->assertJsonPath('source_id', 1)
            ->assertJsonPath('source.id', 1)
            ->assertJsonPath('source.code', 'phone');
    }

    public function test_client_store_rejects_non_existing_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Invalid Source',
            'source_id' => 999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['source_id']);
    }

    public function test_client_store_rejects_inactive_source_id(): void
    {
        [$agent] = $this->prepareAgentContext();
        Sanctum::actingAs($agent);

        $this->postJson('/api/clients', [
            'full_name' => 'Inactive Source',
            'source_id' => 3,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['source_id']);
    }

    public function test_clients_index_filters_by_source_id_and_source_ids_with_priority(): void
    {
        [$agent, $branch] = $this->prepareAgentContext();

        $phoneClient = $this->createClient($branch, $agent, 'Phone source');
        $instaClient = $this->createClient($branch, $agent, 'Insta source');

        $phoneClient->update(['source_id' => 1]);
        $instaClient->update(['source_id' => 2]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients?source_id=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $phoneClient->id])
            ->assertJsonMissing(['id' => $instaClient->id]);

        $this->getJson('/api/clients?source_ids[]=1&source_ids[]=2')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $phoneClient->id])
            ->assertJsonFragment(['id' => $instaClient->id]);

        $this->getJson('/api/clients?source_ids=')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson('/api/clients?source_id=1&source_ids[]=2')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $instaClient->id])
            ->assertJsonMissing(['id' => $phoneClient->id]);
    }

    public function test_client_sources_endpoint_returns_only_active_sources_sorted(): void
    {
        $this->getJson('/api/client-sources')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', 1)
            ->assertJsonPath('0.code', 'phone')
            ->assertJsonPath('1.id', 2)
            ->assertJsonPath('1.code', 'instagram')
            ->assertJsonMissing(['id' => 3]);
    }

    private function prepareAgentContext(): array
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);

        $branch = Branch::create(['name' => 'Main branch']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        return [$agent, $branch];
    }

    private function createUser(Role $role, Branch $branch, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'phone' => (string) $this->phoneCounter++,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function createClient(Branch $branch, User $agent, string $name): Client
    {
        return Client::create([
            'full_name' => $name,
            'phone' => (string) $this->phoneCounter++,
            'phone_normalized' => (string) $this->phoneCounter,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }
}
