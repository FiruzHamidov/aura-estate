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

class ClientNeedFeatureTest extends TestCase
{
    private int $phoneCounter = 950000000;

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
            ['id' => 1, 'name' => 'Физлицо', 'slug' => ClientType::SLUG_INDIVIDUAL, 'is_business' => false, 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Бизнесмен', 'slug' => ClientType::SLUG_BUSINESS_OWNER, 'is_business' => true, 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('client_need_types')->insert([
            ['id' => 1, 'name' => 'Покупка', 'slug' => 'buy', 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Аренда', 'slug' => 'rent', 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Продажа', 'slug' => 'sell', 'sort_order' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Инвестиция', 'slug' => 'invest', 'sort_order' => 40, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('client_need_statuses')->insert([
            ['id' => 1, 'name' => 'Новая', 'slug' => 'new', 'is_closed' => false, 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'В работе', 'slug' => 'in_progress', 'is_closed' => false, 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Ожидание', 'slug' => 'waiting', 'is_closed' => false, 'sort_order' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Закрыта успешно', 'slug' => 'closed_success', 'is_closed' => true, 'sort_order' => 40, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Закрыта без результата', 'slug' => 'closed_lost', 'is_closed' => true, 'sort_order' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_public_can_fetch_client_reference_dictionaries(): void
    {
        $this->getJson('/api/client-types')
            ->assertOk()
            ->assertJsonCount(2);

        $this->getJson('/api/client-need-types')
            ->assertOk()
            ->assertJsonCount(4);

        $this->getJson('/api/client-need-statuses')
            ->assertOk()
            ->assertJsonCount(5);
    }

    public function test_agent_can_create_multiple_needs_for_visible_client_and_see_them_in_client_card(): void
    {
        [$agent, $client] = $this->seedClientContext();

        Sanctum::actingAs($agent);

        $this->postJson('/api/clients/' . $client->id . '/needs', [
            'type_id' => 1,
            'status_id' => 1,
            'budget_from' => 100000,
            'budget_to' => 150000,
            'comment' => 'Need one',
        ])->assertCreated();

        $this->postJson('/api/clients/' . $client->id . '/needs', [
            'type_id' => 2,
            'status_id' => 2,
            'budget_from' => 500,
            'budget_to' => 800,
            'comment' => 'Need two',
        ])->assertCreated();

        $this->getJson('/api/clients/' . $client->id . '/needs')
            ->assertOk()
            ->assertJsonCount(2);

        $this->getJson('/api/clients/' . $client->id)
            ->assertOk()
            ->assertJsonPath('needs_count', 2)
            ->assertJsonPath('open_needs_count', 2)
            ->assertJsonCount(2, 'needs');
    }

    public function test_need_closed_at_is_set_on_closed_status_and_cleared_on_reopen(): void
    {
        [$agent, $client] = $this->seedClientContext();

        Sanctum::actingAs($agent);

        $create = $this->postJson('/api/clients/' . $client->id . '/needs', [
            'type_id' => 1,
            'status_id' => 1,
            'comment' => 'Lifecycle',
        ]);

        $create->assertCreated();
        $needId = $create->json('id');

        $this->patchJson('/api/client-needs/' . $needId, [
            'status_id' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('status.slug', 'closed_success');

        $closedAt = $this->getJson('/api/client-needs/' . $needId)
            ->assertOk()
            ->json('closed_at');

        $this->assertNotNull($closedAt);

        $this->patchJson('/api/client-needs/' . $needId, [
            'status_id' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('status.slug', 'waiting')
            ->assertJsonPath('closed_at', null);
    }

    public function test_agent_cannot_access_foreign_client_need_in_own_only_mode(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A');
        $agentB = $this->createUser($agentRole, $branch, 'Agent B');
        $foreignClient = $this->createClient($branch, $agentB, $agentB, 'Foreign Client');

        Sanctum::actingAs($agentB);
        $create = $this->postJson('/api/clients/' . $foreignClient->id . '/needs', [
            'type_id' => 1,
            'status_id' => 1,
            'comment' => 'Hidden need',
        ]);
        $create->assertCreated();
        $needId = $create->json('id');

        Sanctum::actingAs($agentA);

        $this->getJson('/api/clients/' . $foreignClient->id . '/needs')->assertForbidden();
        $this->getJson('/api/client-needs/' . $needId)->assertForbidden();
    }

    private function seedClientContext(): array
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $client = $this->createClient($branch, $agent, $agent, 'Visible Client');

        return [$agent, $client];
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
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }
}
