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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
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

    public function test_agent_create_forces_branch_to_own_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branchA, 'Agent A');

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'New Client',
            'phone' => '+992 90 000 0001',
            'branch_id' => $branchB->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('branch_id', $branchA->id);
        $response->assertJsonPath('responsible_agent_id', $agent->id);
        $response->assertJsonPath('created_by', $agent->id);
    }

    public function test_admin_can_update_client_visibility_setting(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');

        Sanctum::actingAs($admin);

        $this->putJson('/api/clients/settings', [
            'agent_visibility_mode' => ClientAccess::VISIBILITY_OWN_ONLY,
        ])
            ->assertOk()
            ->assertJsonPath('agent_visibility_mode', ClientAccess::VISIBILITY_OWN_ONLY);

        $this->getJson('/api/clients/settings')
            ->assertOk()
            ->assertJsonPath('agent_visibility_mode', ClientAccess::VISIBILITY_OWN_ONLY);
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

    private function createUser(Role $role, Branch $branch, string $name): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }

    private function createClient(Branch $branch, User $creator, User $responsibleAgent, string $fullName, int $clientTypeId = 1): Client
    {
        return Client::create([
            'full_name' => $fullName,
            'phone' => '+992900000' . random_int(100, 999),
            'phone_normalized' => '992900000' . random_int(100, 999),
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $responsibleAgent->id,
            'client_type_id' => $clientTypeId,
            'status' => 'active',
        ]);
    }
}
