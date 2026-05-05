<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\CrmAuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientWorkflowFeatureTest extends TestCase
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

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('contact_visibility_mode', 32)->default(BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
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

        Schema::create('client_need_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        Schema::create('client_need_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
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

        DB::table('client_need_statuses')->insert([
            'id' => 1,
            'name' => 'Новая',
            'slug' => 'new',
            'is_closed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_need_types')->insert([
            'id' => 1,
            'name' => 'Покупка',
            'slug' => 'buy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_visible_duplicate_does_not_block_create(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $group);
        $this->createClient($branch, $agentB, $agentB, 'Existing Buyer', '+992 90 111 1111');

        Sanctum::actingAs($agentA);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'Duplicate Buyer',
            'phone' => '+992 90 111 1111',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('phone_normalized', ClientPhone::normalize('+992 90 111 1111'));
    }

    public function test_hidden_duplicate_does_not_block_create(): void
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
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $group);
        $this->createClient(
            $branch,
            $agentB,
            $agentB,
            'Hidden Seller',
            '+992 90 222 2222',
            null,
            Client::CONTACT_KIND_SELLER
        );

        Sanctum::actingAs($agentA);

        $response = $this->postJson('/api/clients', [
            'full_name' => 'Seller Duplicate',
            'phone' => '+992 90 222 2222',
            'contact_kind' => Client::CONTACT_KIND_SELLER,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('phone_normalized', ClientPhone::normalize('+992 90 222 2222'));
    }

    public function test_agent_can_attach_existing_hidden_client_and_receive_shared_access(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $ownerAgent = $this->createUser($agentRole, $branch, 'Owner Agent', $group);
        $secondAgent = $this->createUser($agentRole, $branch, 'Second Agent', $group);
        $client = $this->createClient(
            $branch,
            $ownerAgent,
            $ownerAgent,
            'Shared Existing Client',
            '+992 90 212 1212',
            null,
            Client::CONTACT_KIND_SELLER
        );

        Sanctum::actingAs($secondAgent);

        $this->getJson('/api/clients/'.$client->id)->assertForbidden();

        $this->postJson('/api/clients/attach-existing', [
            'client_id' => $client->id,
        ])
            ->assertOk()
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('context.context_type', 'client');

        $this->getJson('/api/clients/'.$client->id)
            ->assertOk()
            ->assertJsonPath('id', $client->id);

        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $secondAgent->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
        ]);

        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'event' => 'attached_existing_client',
        ]);
    }

    public function test_update_excludes_current_client_from_duplicate_check(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $client = $this->createClient($branch, $agent, $agent, 'Editable Client', '+992 90 333 3333');

        Sanctum::actingAs($agent);

        $this->putJson('/api/clients/' . $client->id, [
            'full_name' => 'Editable Client Updated',
            'phone' => '+992 90 333 3333',
        ])
            ->assertOk()
            ->assertJsonPath('full_name', 'Editable Client Updated')
            ->assertJsonPath('phone_normalized', ClientPhone::normalize('+992 90 333 3333'));
    }

    public function test_booking_store_requires_client_picker_and_rejects_manual_client_fields(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $propertyId = $this->createProperty();

        Sanctum::actingAs($agent);

        $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $agent->id,
            'start_time' => '2026-03-10T10:00:00+05:00',
            'end_time' => '2026-03-10T11:00:00+05:00',
            'client_name' => 'Manual Client',
            'client_phone' => '+992901234567',
        ])->assertStatus(422);
    }

    public function test_booking_create_and_update_sync_snapshots_from_crm_client(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $clientA = $this->createClient($branch, $agent, $agent, 'Client A', '+992 90 444 4444');
        $clientB = $this->createClient($branch, $agent, $agent, 'Client B', '+992 90 555 5555');
        $propertyId = $this->createProperty();

        Sanctum::actingAs($agent);

        $createResponse = $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $agent->id,
            'client_id' => $clientA->id,
            'start_time' => '2026-03-10T12:00:00+05:00',
            'end_time' => '2026-03-10T13:00:00+05:00',
        ]);

        $bookingId = $createResponse->json('booking.id');

        $createResponse
            ->assertCreated()
            ->assertJsonPath('booking.crm_client_id', $clientA->id)
            ->assertJsonPath('booking.client_name', $clientA->full_name)
            ->assertJsonPath('booking.client_phone', $clientA->phone);

        $this->putJson('/api/bookings/' . $bookingId, [
            'client_id' => $clientB->id,
            'note' => 'Updated note',
        ])
            ->assertOk()
            ->assertJsonPath('crm_client_id', $clientB->id)
            ->assertJsonPath('client_name', $clientB->full_name)
            ->assertJsonPath('client_phone', $clientB->phone)
            ->assertJsonPath('note', 'Updated note');
    }

    public function test_client_show_with_activities_include_returns_audit_entries(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $client = $this->createClient($branch, $agent, $agent, 'Client Activities', '+992 90 666 6666');

        CrmAuditLog::create([
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'actor_id' => $agent->id,
            'event' => 'comment',
            'new_values' => ['comment' => 'Latest note'],
            'message' => 'Comment added.',
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/clients/' . $client->id . '?include=activities')
            ->assertOk()
            ->assertJsonPath('activities_count', 1)
            ->assertJsonPath('activities.0.event', 'comment')
            ->assertJsonPath('latest_activities.0.event', 'comment');
    }

    public function test_booking_actions_create_client_audit_entries(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $client = $this->createClient($branch, $agent, $agent, 'Booking Client', '+992 90 777 7777');
        $propertyId = $this->createProperty();

        Sanctum::actingAs($agent);

        $bookingId = $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $agent->id,
            'client_id' => $client->id,
            'start_time' => '2026-03-10T14:00:00+05:00',
            'end_time' => '2026-03-10T15:00:00+05:00',
        ])->assertCreated()->json('booking.id');

        $this->putJson('/api/bookings/' . $bookingId, [
            'note' => 'Follow-up note',
        ])->assertOk();

        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'event' => 'booking_created',
        ]);

        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'event' => 'booking_updated',
        ]);
    }

    public function test_client_can_be_shared_with_multiple_agents_via_collaborators(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $group);
        $client = $this->createClient(
            $branch,
            $agentA,
            $agentA,
            'Shared Seller',
            '+992 90 888 8888',
            null,
            Client::CONTACT_KIND_SELLER
        );

        Sanctum::actingAs($agentB);
        $this->getJson('/api/clients/' . $client->id)->assertForbidden();

        Sanctum::actingAs($agentA);
        $this->postJson('/api/clients/' . $client->id . '/collaborators', [
            'user_id' => $agentB->id,
            'role' => Client::COLLABORATOR_ROLE_COLLABORATOR,
        ])->assertOk();

        Sanctum::actingAs($agentB);
        $this->getJson('/api/clients/' . $client->id)
            ->assertOk()
            ->assertJsonPath('id', $client->id);
    }

    public function test_second_agent_can_create_duplicate_and_sees_existing_client_after_collaboration(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $group);
        $client = $this->createClient(
            $branch,
            $agentA,
            $agentA,
            'No Duplicate Seller',
            '+992 90 999 9999',
            null,
            Client::CONTACT_KIND_SELLER
        );

        Sanctum::actingAs($agentA);
        $this->postJson('/api/clients/' . $client->id . '/collaborators', [
            'user_id' => $agentB->id,
            'role' => Client::COLLABORATOR_ROLE_COLLABORATOR,
        ])->assertOk();

        Sanctum::actingAs($agentB);
        $this->postJson('/api/clients', [
            'full_name' => 'Attempted Duplicate',
            'phone' => '+992 90 999 9999',
            'contact_kind' => Client::CONTACT_KIND_SELLER,
        ])
            ->assertCreated()
            ->assertJsonPath('phone_normalized', ClientPhone::normalize('+992 90 999 9999'));
    }

    public function test_actions_of_different_agents_appear_in_one_client_history(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agentA = $this->createUser($agentRole, $branch, 'Agent A', $group);
        $agentB = $this->createUser($agentRole, $branch, 'Agent B', $group);
        $client = $this->createClient($branch, $agentA, $agentA, 'Shared Buyer', '+992 90 123 1234');
        $propertyId = $this->createProperty();

        Sanctum::actingAs($agentA);
        $this->postJson('/api/clients/' . $client->id . '/collaborators', [
            'user_id' => $agentB->id,
            'role' => Client::COLLABORATOR_ROLE_COLLABORATOR,
        ])->assertOk();

        Sanctum::actingAs($agentA);
        $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $agentA->id,
            'client_id' => $client->id,
            'start_time' => '2026-03-11T10:00:00+05:00',
            'end_time' => '2026-03-11T11:00:00+05:00',
        ])->assertCreated();

        Sanctum::actingAs($agentB);
        $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $agentB->id,
            'client_id' => $client->id,
            'start_time' => '2026-03-11T12:00:00+05:00',
            'end_time' => '2026-03-11T13:00:00+05:00',
        ])->assertCreated();

        $response = $this->getJson('/api/clients/' . $client->id . '/activities');

        $response->assertOk();
        $this->assertSame(
            [$agentB->id, $agentA->id],
            collect($response->json('data'))->take(2)->pluck('actor_id')->all()
        );
    }

    public function test_booking_store_auto_adds_assigned_agent_as_client_viewer_when_client_is_not_visible(): void
    {
        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_OWN_ONLY,
        ]);
        Setting::create([
            'key' => ClientAccess::AGENT_CAN_VIEW_SELLERS_SETTING_KEY,
            'value' => '1',
        ]);

        $branch = Branch::create(['name' => 'Branch A']);
        $group = $this->createBranchGroup($branch, 'Group A');
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $ownerAgent = $this->createUser($agentRole, $branch, 'Owner Agent', $group);
        $bookingAgent = $this->createUser($managerRole, $branch, 'Booking Agent', $group);
        $client = $this->createClient($branch, $ownerAgent, $ownerAgent, 'Private Buyer', '+992 90 101 0101');
        $propertyId = $this->createProperty();

        Sanctum::actingAs($bookingAgent);
        $this->getJson('/api/clients/' . $client->id)->assertForbidden();

        $this->postJson('/api/bookings', [
            'property_id' => $propertyId,
            'agent_id' => $bookingAgent->id,
            'client_id' => $client->id,
            'start_time' => '2026-03-11T14:00:00+05:00',
            'end_time' => '2026-03-11T15:00:00+05:00',
        ])->assertCreated();

        $this->getJson('/api/clients/' . $client->id)
            ->assertOk()
            ->assertJsonPath('id', $client->id);

        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $bookingAgent->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
        ]);
    }

    private function createUser(Role $role, Branch $branch, string $name, ?BranchGroup $group = null): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group?->id,
            'status' => 'active',
        ]);
    }

    private function createBranchGroup(
        Branch $branch,
        string $name,
        string $visibility = BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY
    ): BranchGroup {
        return BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => $name,
            'contact_visibility_mode' => $visibility,
        ]);
    }

    private function createClient(
        Branch $branch,
        User $creator,
        User $responsibleAgent,
        string $fullName,
        string $phone,
        ?string $email = null,
        string $contactKind = Client::CONTACT_KIND_BUYER,
        ?BranchGroup $group = null
    ): Client {
        return Client::create([
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_normalized' => ClientPhone::normalize($phone),
            'email' => $email,
            'branch_id' => $branch->id,
            'branch_group_id' => $group?->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $responsibleAgent->id,
            'client_type_id' => 1,
            'contact_kind' => $contactKind,
            'status' => 'active',
        ]);
    }

    private function createProperty(): int
    {
        return DB::table('properties')->insertGetId([
            'title' => 'Test property',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
