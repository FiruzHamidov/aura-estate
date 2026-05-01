<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\ClientType;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
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
            $table->boolean('is_default')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('client_needs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->decimal('budget_from', 15, 2)->nullable();
            $table->decimal('budget_to', 15, 2)->nullable();
            $table->decimal('budget_total', 15, 2)->nullable();
            $table->decimal('budget_cash', 15, 2)->nullable();
            $table->decimal('budget_mortgage', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->integer('rooms_from')->nullable();
            $table->integer('rooms_to')->nullable();
            $table->decimal('area_from', 10, 2)->nullable();
            $table->decimal('area_to', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('wants_mortgage')->default(false);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('client_need_property_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_need_id');
            $table->unsignedBigInteger('property_type_id');
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
            $table->unsignedBigInteger('converted_client_id')->nullable();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->unsignedBigInteger('client_need_id')->nullable();
            $table->string('status')->default(Lead::STATUS_NEW);
            $table->decimal('budget', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
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

        Schema::create('crm_deal_pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->nullable();
            $table->string('type')->default(DealPipeline::TYPE_SALES);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_deal_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_id');
            $table->string('name');
            $table->string('slug');
            $table->string('color', 24)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('client_need_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('stage_id');
            $table->unsignedBigInteger('primary_property_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->decimal('expected_company_income', 15, 2)->nullable();
            $table->string('expected_company_income_currency', 3)->default('TJS');
            $table->decimal('expected_agent_commission', 15, 2)->nullable();
            $table->string('expected_agent_commission_currency', 3)->default('TJS');
            $table->decimal('actual_company_income', 15, 2)->nullable();
            $table->string('actual_company_income_currency', 3)->default('TJS');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->string('source')->nullable();
            $table->unsignedInteger('board_position')->default(0);
            $table->json('meta')->nullable();
            $table->text('note')->nullable();
            $table->json('tags')->nullable();
            $table->string('last_contact_result', 100)->nullable();
            $table->timestamp('next_activity_at')->nullable();
            $table->string('source_property_status', 40)->nullable();
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
            'is_default' => true,
            'is_closed' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pipeline = DealPipeline::create([
            'name' => 'Основная воронка',
            'slug' => 'default_sales',
            'code' => 'default_sales',
            'type' => DealPipeline::TYPE_SALES,
            'branch_id' => null,
            'sort_order' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Новая',
            'slug' => 'new',
            'sort_order' => 10,
            'is_default' => true,
            'is_active' => true,
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

    public function test_manager_sees_and_updates_all_branch_leads(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $manager = $this->createUser($managerRole, $branchA, 'Manager A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $agentForeign = $this->createUser($agentRole, $branchB, 'Agent C');

        $leadA = $this->createLead($branchA, $agentA, $agentA, 'Lead A', '992950000110');
        $leadB = $this->createLead($branchA, $agentB, $agentB, 'Lead B', '992950000111');
        $leadForeign = $this->createLead($branchB, $agentForeign, $agentForeign, 'Lead C', '992950000112');

        Sanctum::actingAs($manager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $leadA->id])
            ->assertJsonFragment(['id' => $leadB->id])
            ->assertJsonMissing(['full_name' => $leadForeign->full_name]);

        $this->patchJson('/api/leads/'.$leadB->id, [
            'full_name' => 'Lead B Updated',
            'responsible_agent_id' => $agentA->id,
        ])->assertOk()
            ->assertJsonPath('full_name', 'Lead B Updated')
            ->assertJsonPath('responsible_agent_id', $agentA->id);

        $this->getJson('/api/leads/'.$leadForeign->id)->assertForbidden();
    }

    public function test_rop_branch_scope_violations_return_403_with_rbac_code_on_filters(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $rop = $this->createUser($ropRole, $branchA, 'ROP A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        Sanctum::actingAs($rop);

        $this->getJson('/api/leads?branch_id='.$branchB->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');

        $this->getJson('/api/leads?responsible_agent_id='.$agentB->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');

        $this->getJson('/api/deals?responsible_agent_id='.$agentB->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');

        $this->getJson('/api/crm/reports/performance?role_type=operator&responsible_user_id='.$agentB->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');

        $this->getJson('/api/leads?responsible_agent_id='.$agentA->id)->assertOk();
    }

    public function test_rop_gets_403_with_rbac_code_on_foreign_lead_and_deal_details(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $rop = $this->createUser($ropRole, $branchA, 'ROP A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        $foreignLead = $this->createLead($branchB, $agentB, $agentB, 'Foreign Lead', '992950000210');

        $stage = DealStage::query()->firstOrFail();
        $foreignDeal = Deal::create([
            'title' => 'Foreign Deal',
            'branch_id' => $branchB->id,
            'created_by' => $agentB->id,
            'responsible_agent_id' => $agentB->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);

        Sanctum::actingAs($rop);

        $this->getJson('/api/leads/'.$foreignLead->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');

        $this->getJson('/api/deals/'.$foreignDeal->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');
    }

    public function test_admin_and_superadmin_keep_multibranch_crm_visibility(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $admin = $this->createUser($adminRole, $branchA, 'Admin A');
        $superadmin = $this->createUser($superadminRole, $branchA, 'Superadmin A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        $leadA = $this->createLead($branchA, $agentA, $agentA, 'Lead A', '992950000220');
        $leadB = $this->createLead($branchB, $agentB, $agentB, 'Lead B', '992950000221');

        $stage = DealStage::query()->firstOrFail();
        $dealA = Deal::create([
            'title' => 'Deal A',
            'branch_id' => $branchA->id,
            'created_by' => $agentA->id,
            'responsible_agent_id' => $agentA->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);
        $dealB = Deal::create([
            'title' => 'Deal B',
            'branch_id' => $branchB->id,
            'created_by' => $agentB->id,
            'responsible_agent_id' => $agentB->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/leads')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/deals')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/leads/'.$leadA->id)->assertOk();
        $this->getJson('/api/leads/'.$leadB->id)->assertOk();
        $this->getJson('/api/deals/'.$dealA->id)->assertOk();
        $this->getJson('/api/deals/'.$dealB->id)->assertOk();

        Sanctum::actingAs($superadmin);
        $this->getJson('/api/leads')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/deals')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_client_id_filters_return_paginated_lead_and_deal_totals(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $client = $this->createClient($branch, $agent, 'Client With CRM', '992950000015');
        $otherClient = $this->createClient($branch, $agent, 'Other Client', '992950000016');
        $stage = DealStage::query()->firstOrFail();

        foreach (['Lead 1', 'Lead 2'] as $index => $name) {
            Lead::create([
                'full_name' => $name,
                'phone' => '99295000001'.(7 + $index),
                'client_id' => $client->id,
                'branch_id' => $branch->id,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'status' => Lead::STATUS_NEW,
            ]);
        }

        Lead::create([
            'full_name' => 'Other Lead',
            'phone' => '992950000019',
            'client_id' => $otherClient->id,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'status' => Lead::STATUS_NEW,
        ]);

        foreach (['Deal 1', 'Deal 2'] as $title) {
            Deal::create([
                'title' => $title,
                'client_id' => $client->id,
                'branch_id' => $branch->id,
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->id,
            ]);
        }

        Deal::create([
            'title' => 'Other Deal',
            'client_id' => $otherClient->id,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/leads?client_id='.$client->id.'&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 1);

        $this->getJson('/api/deals?client_id='.$client->id.'&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 1);
    }

    public function test_lead_and_deal_show_return_client_needs_and_specific_need(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $client = $this->createClient($branch, $agent, 'Need Client', '992950000025');
        $need = ClientNeed::create([
            'client_id' => $client->id,
            'type_id' => 1,
            'status_id' => 1,
            'budget_total' => 100000,
            'currency' => 'USD',
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
        ]);
        $lead = $this->createLead($branch, $agent, $agent, 'Need Lead', '992950000026');
        $lead->update([
            'client_id' => $client->id,
            'client_need_id' => $need->id,
        ]);
        $stage = DealStage::query()->firstOrFail();
        $deal = Deal::create([
            'title' => 'Need Deal',
            'client_id' => $client->id,
            'lead_id' => $lead->id,
            'client_need_id' => $need->id,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/leads/'.$lead->id.'?include=client,client.needs,deals')
            ->assertOk()
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('client_need_id', $need->id)
            ->assertJsonPath('need_id', $need->id)
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('client.needs.0.id', $need->id)
            ->assertJsonPath('client_need.id', $need->id)
            ->assertJsonPath('deals.0.id', $deal->id);

        $this->getJson('/api/deals/'.$deal->id.'?include=client,client.needs,lead,pipeline,stage')
            ->assertOk()
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('lead_id', $lead->id)
            ->assertJsonPath('client_need_id', $need->id)
            ->assertJsonPath('need_id', $need->id)
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('client.needs.0.id', $need->id)
            ->assertJsonPath('client_need.id', $need->id)
            ->assertJsonPath('lead.id', $lead->id)
            ->assertJsonPath('pipeline.id', $stage->pipeline_id)
            ->assertJsonPath('stage.id', $stage->id);
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
        $response->assertJsonPath('message', 'Лид успешно конвертирован в сделку');
        $response->assertJsonPath('lead.status', Lead::STATUS_CONVERTED);
        $response->assertJsonPath('client.id', $existingClient->id);
        $response->assertJsonPath('client.contact_kind', Client::CONTACT_KIND_BUYER);
        $response->assertJsonPath('deal.lead_id', $lead->id);
        $response->assertJsonPath('deal.client_id', $existingClient->id);
        $response->assertJsonPath('deal.title', 'Lead To Convert');
        $this->assertNotNull($response->json('deal.id'));

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => Lead::STATUS_CONVERTED,
            'client_id' => $existingClient->id,
            'converted_client_id' => $existingClient->id,
            'converted_deal_id' => $response->json('deal.id'),
        ]);
        $this->assertDatabaseHas('crm_deals', [
            'id' => $response->json('deal.id'),
            'lead_id' => $lead->id,
            'client_id' => $existingClient->id,
        ]);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('crm_deals', 1);
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
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => Deal::class,
            'auditable_id' => $response->json('deal.id'),
            'event' => 'created_from_lead',
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
        $response->assertJsonPath('lead.status', Lead::STATUS_CONVERTED);
        $response->assertJsonPath('client.full_name', 'Fresh Lead');
        $response->assertJsonPath('client.phone', '992950000030');
        $response->assertJsonPath('client.contact_kind', Client::CONTACT_KIND_BUYER);
        $response->assertJsonPath('deal.lead_id', $lead->id);
        $this->assertNotNull($response->json('deal.id'));

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

    public function test_converting_lead_creates_deal_and_is_idempotent(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        $lead = $this->createLead($branch, $agent, $agent, 'Budget Lead', '992950000040');
        $client = $this->createClient($branch, $agent, 'Budget Client', '992950000041');
        $need = ClientNeed::create([
            'client_id' => $client->id,
            'type_id' => 1,
            'status_id' => 1,
            'budget_total' => 125000,
            'currency' => 'USD',
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
        ]);
        $lead->update([
            'client_id' => $client->id,
            'client_need_id' => $need->id,
            'budget' => 125000,
            'currency' => 'USD',
            'note' => 'Interested in new buildings',
            'tags' => ['vip', 'new-build'],
            'last_contact_result' => 'called',
            'next_follow_up_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($agent);

        $first = $this->postJson('/api/leads/'.$lead->id.'/convert');
        $first->assertOk()
            ->assertJsonPath('lead.status', Lead::STATUS_CONVERTED)
            ->assertJsonPath('deal.lead_id', $lead->id)
            ->assertJsonPath('deal.client_need_id', $need->id)
            ->assertJsonPath('deal.need_id', $need->id)
            ->assertJsonPath('deal.client_need.id', $need->id)
            ->assertJsonPath('deal.client.needs.0.id', $need->id)
            ->assertJsonPath('deal.title', 'Budget Lead')
            ->assertJsonPath('deal.source', 'website')
            ->assertJsonPath('deal.currency', 'USD')
            ->assertJsonPath('deal.branch_id', $branch->id)
            ->assertJsonPath('deal.responsible_agent_id', $agent->id)
            ->assertJsonPath('deal.note', 'Interested in new buildings')
            ->assertJsonPath('deal.last_contact_result', 'called');

        $this->assertEquals(125000.0, (float) $first->json('deal.amount'));

        $dealId = $first->json('deal.id');
        $clientId = $first->json('client.id');

        $this->assertNotNull($dealId);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => Lead::STATUS_CONVERTED,
            'client_id' => $clientId,
            'converted_client_id' => $clientId,
            'converted_deal_id' => $dealId,
            'client_need_id' => $need->id,
        ]);
        $this->assertDatabaseHas('crm_deals', [
            'id' => $dealId,
            'lead_id' => $lead->id,
            'client_id' => $clientId,
            'client_need_id' => $need->id,
        ]);

        $second = $this->postJson('/api/leads/'.$lead->id.'/convert');

        $second->assertOk()
            ->assertJsonPath('deal.id', $dealId)
            ->assertJsonPath('client.id', $clientId);

        $this->assertDatabaseCount('crm_deals', 1);
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
