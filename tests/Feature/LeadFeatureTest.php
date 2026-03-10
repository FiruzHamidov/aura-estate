<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadFeatureTest extends TestCase
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
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
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

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('status')->default(Lead::STATUS_NEW);
            $table->timestamp('first_contact_due_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->json('meta')->nullable();
            $table->json('tags')->nullable();
            $table->string('last_contact_result', 100)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('next_activity_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
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
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_agent_can_create_lead_with_duplicate_summary_from_existing_client(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $existingClient = $this->createClient($branch, $agent, 'Duplicate Client', '992950000001');

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/leads', [
            'full_name' => 'Inbound Lead',
            'phone' => '992950000001',
            'source' => 'website',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('duplicate_summary.client_matches_count', 1);
        $response->assertJsonPath('duplicate_summary.top_client_match.id', $existingClient->id);
        $response->assertJsonPath('responsible_agent_id', $agent->id);
    }

    public function test_agent_sees_only_own_leads_while_rop_sees_entire_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);

        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $agentForeign = $this->createUser($agentRole, $branchB, 'Agent C');
        $rop = $this->createUser($ropRole, $branchA, 'ROP A');

        $ownLead = $this->createLead($branchA, $agentA, $agentA, 'Lead A', '992950000010');
        $foreignBranchLead = $this->createLead($branchB, $agentForeign, $agentForeign, 'Lead C', '992950000012');
        $sameBranchForeignLead = $this->createLead($branchA, $agentB, $agentB, 'Lead B', '992950000011');

        Sanctum::actingAs($agentA);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownLead->id);

        $this->getJson('/api/leads/'.$sameBranchForeignLead->id)->assertForbidden();

        Sanctum::actingAs($rop);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $ownLead->id])
            ->assertJsonFragment(['id' => $sameBranchForeignLead->id])
            ->assertJsonMissing(['full_name' => $foreignBranchLead->full_name]);
    }

    public function test_converting_lead_reuses_existing_client_and_writes_audit_log(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $existingClient = $this->createClient($branch, $agent, 'Existing Client', '992950000020');

        $lead = $this->createLead($branch, $agent, $agent, 'Lead To Convert', '992950000020');

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/leads/'.$lead->id.'/convert');

        $response->assertOk();
        $response->assertJsonPath('status', Lead::STATUS_CONVERTED);
        $response->assertJsonPath('client.id', $existingClient->id);
        $response->assertJsonPath('client.contact_kind', Client::CONTACT_KIND_BUYER);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => Lead::STATUS_CONVERTED,
            'client_id' => $existingClient->id,
        ]);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Lead::class,
            'auditable_id' => $lead->id,
            'event' => 'converted',
        ]);
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $existingClient->id,
            'event' => 'lead_linked',
        ]);
    }

    public function test_converting_lead_creates_new_client_when_no_duplicate_exists(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $lead = $this->createLead($branch, $agent, $agent, 'Fresh Lead', '992950000030', 'fresh@example.com');

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/leads/'.$lead->id.'/convert');

        $response->assertOk();
        $response->assertJsonPath('status', Lead::STATUS_CONVERTED);
        $response->assertJsonPath('client.full_name', 'Fresh Lead');
        $response->assertJsonPath('client.phone', '992950000030');
        $response->assertJsonPath('client.contact_kind', Client::CONTACT_KIND_BUYER);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', [
            'full_name' => 'Fresh Lead',
            'phone' => '992950000030',
            'email' => 'fresh@example.com',
            'branch_id' => $branch->id,
        ]);
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Client::class,
            'event' => 'created_from_lead',
        ]);
    }

    private function createUser(Role $role, Branch $branch, string $name): User
    {
        return User::create([
            'name' => $name,
            'phone' => $this->nextPhone(),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function createClient(Branch $branch, User $agent, string $name, string $phone, ?string $email = null): Client
    {
        return Client::create([
            'full_name' => $name,
            'phone' => $phone,
            'phone_normalized' => ClientPhone::normalize($phone),
            'email' => $email,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }

    private function createLead(Branch $branch, User $creator, User $responsible, string $name, string $phone, ?string $email = null): Lead
    {
        return Lead::create([
            'full_name' => $name,
            'phone' => $phone,
            'phone_normalized' => ClientPhone::normalize($phone),
            'email' => $email,
            'source' => 'website',
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $responsible->id,
            'status' => Lead::STATUS_NEW,
            'first_contact_due_at' => now()->addMinutes(15),
            'last_activity_at' => now(),
        ]);
    }

    private function nextPhone(): string
    {
        return '992'.$this->phoneCounter++;
    }
}
