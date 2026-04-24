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
