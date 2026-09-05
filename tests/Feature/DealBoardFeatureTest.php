<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Crm\PropertyControlService;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealBoardFeatureTest extends TestCase
{
    private int $phoneCounter = 960000000;

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
            $table->string('role', 32)->default(Client::COLLABORATOR_ROLE_VIEWER);
            $table->unsignedBigInteger('granted_by')->nullable();
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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('object_key')->nullable();
            $table->string('moderation_status')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable();
            $table->unsignedBigInteger('deposit_user_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->string('actual_sale_currency', 3)->nullable();
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency', 3)->nullable();
            $table->decimal('company_commission_amount', 15, 2)->nullable();
            $table->string('company_commission_currency', 3)->nullable();
            $table->string('money_holder')->nullable();
            $table->timestamp('money_received_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('deposit_currency', 3)->nullable();
            $table->timestamp('deposit_received_at')->nullable();
            $table->text('status_comment')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->timestamp('sold_at')->nullable();
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

        Schema::create('crm_deal_pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->nullable();
            $table->string('type')->default('sales');
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
            $table->string('color')->nullable();
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
            $table->string('control_kind', 64)->nullable();
            $table->uuid('source_event_uuid')->nullable()->unique();
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

        ClientType::create([
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Setting::create([
            'key' => ClientAccess::VISIBILITY_SETTING_KEY,
            'value' => ClientAccess::VISIBILITY_ALL_BRANCH,
        ]);
    }

    public function test_branch_director_can_create_edit_and_reorder_stages(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $director = $this->createUser($directorRole, $branch, 'Director A');

        Sanctum::actingAs($director);

        $createPipeline = $this->postJson('/api/deal-pipelines', [
            'name' => 'Secondary Pipeline',
            'slug' => 'secondary_pipeline',
        ]);

        $createPipeline->assertCreated();
        $pipelineId = $createPipeline->json('id');
        $this->assertSame($branch->id, $createPipeline->json('branch_id'));
        $this->assertCount(4, $createPipeline->json('stages'));

        $createStage = $this->postJson('/api/deal-pipelines/'.$pipelineId.'/stages', [
            'name' => 'Договор',
            'slug' => 'contract',
            'color' => '#111827',
        ]);

        $createStage->assertCreated();
        $stageId = $createStage->json('id');

        $this->patchJson('/api/deal-stages/'.$stageId, [
            'name' => 'Подписание договора',
            'is_closed' => true,
        ])->assertOk()
            ->assertJsonPath('name', 'Подписание договора')
            ->assertJsonPath('is_closed', true);

        $currentStages = DealPipeline::findOrFail($pipelineId)->stages()->pluck('id')->all();
        $reordered = array_reverse($currentStages);

        $this->patchJson('/api/deal-pipelines/'.$pipelineId.'/stages/reorder', [
            'stage_ids' => $reordered,
        ])->assertOk();

        $this->assertSame(
            $reordered,
            DealPipeline::findOrFail($pipelineId)->stages()->pluck('id')->all()
        );
    }

    public function test_agent_can_drag_deals_between_stages_and_preserve_order(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $client = $this->createClient($branch, $agent, 'Buyer Client', '992960000010');

        [$pipeline, $newStage, $negotiationStage] = $this->createPipelineWithStages($branch);

        Sanctum::actingAs($agent);

        $dealA = $this->postJson('/api/deals', [
            'pipeline_id' => $pipeline->id,
            'stage_id' => $newStage->id,
            'client_id' => $client->id,
            'title' => 'Deal A',
        ])->assertCreated()->json();

        $dealB = $this->postJson('/api/deals', [
            'pipeline_id' => $pipeline->id,
            'stage_id' => $newStage->id,
            'client_id' => $client->id,
            'title' => 'Deal B',
        ])->assertCreated()->json();

        $this->patchJson('/api/deals/'.$dealB['id'].'/move', [
            'stage_id' => $negotiationStage->id,
            'position' => 0,
        ])->assertOk()
            ->assertJsonPath('stage_id', $negotiationStage->id)
            ->assertJsonPath('board_position', 1);

        $this->patchJson('/api/deals/'.$dealA['id'].'/move', [
            'stage_id' => $negotiationStage->id,
            'position' => 0,
        ])->assertOk()
            ->assertJsonPath('stage_id', $negotiationStage->id)
            ->assertJsonPath('board_position', 1);

        $board = $this->getJson('/api/deal-pipelines/'.$pipeline->id.'/board')
            ->assertOk()
            ->json();

        $stagesById = collect($board['stages'])->keyBy('id');
        $this->assertCount(0, $stagesById[$newStage->id]['deals']);
        $this->assertSame(
            [$dealA['id'], $dealB['id']],
            collect($stagesById[$negotiationStage->id]['deals'])->pluck('id')->all()
        );
    }

    public function test_agent_cannot_move_foreign_branch_deal_but_rop_sees_branch_board_only(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);

        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $agentForeign = $this->createUser($agentRole, $branchB, 'Agent C');
        $rop = $this->createUser($ropRole, $branchA, 'ROP A');

        [$pipelineA, $newStageA, $wonStageA] = $this->createPipelineWithStages($branchA);
        [$pipelineB, $newStageB] = $this->createPipelineWithStages($branchB);

        $clientA = $this->createClient($branchA, $agentB, 'Client A', '992960000020');
        $clientB = $this->createClient($branchB, $agentForeign, 'Client B', '992960000021');

        $foreignSameBranchDeal = $this->createDeal($pipelineA, $newStageA, $branchA, $agentB, $clientA, 'Branch Deal');
        $foreignBranchDeal = $this->createDeal($pipelineB, $newStageB, $branchB, $agentForeign, $clientB, 'Foreign Branch Deal');

        Sanctum::actingAs($agentA);

        $this->patchJson('/api/deals/'.$foreignSameBranchDeal->id.'/move', [
            'stage_id' => $wonStageA->id,
        ])->assertForbidden();

        Sanctum::actingAs($rop);

        $this->getJson('/api/deal-pipelines/'.$pipelineA->id.'/board')
            ->assertOk()
            ->assertJsonFragment(['id' => $foreignSameBranchDeal->id])
            ->assertJsonMissing(['title' => $foreignBranchDeal->title]);
    }

    public function test_agent_cannot_create_deal_for_another_agents_property(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $agentC = $this->createUser($agentRole, $branchB, 'Agent C');

        [$pipelineA, $newStageA] = $this->createPipelineWithStages($branchA);

        $ownProperty = Property::create([
            'title' => 'Own Property',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
        ]);
        $sameBranchForeignProperty = Property::create([
            'title' => 'Same Branch Foreign Property',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
        ]);
        $otherBranchForeignProperty = Property::create([
            'title' => 'Other Branch Foreign Property',
            'created_by' => $agentC->id,
            'agent_id' => $agentC->id,
        ]);

        Sanctum::actingAs($agentA);

        $this->postJson('/api/deals', [
            'title' => 'Own Property Deal',
            'pipeline_id' => $pipelineA->id,
            'stage_id' => $newStageA->id,
            'primary_property_id' => $ownProperty->id,
        ])->assertCreated()
            ->assertJsonPath('primary_property_id', $ownProperty->id);

        $this->postJson('/api/deals', [
            'title' => 'Same Branch Foreign Property Deal',
            'pipeline_id' => $pipelineA->id,
            'stage_id' => $newStageA->id,
            'primary_property_id' => $sameBranchForeignProperty->id,
        ])->assertForbidden();

        $this->postJson('/api/deals', [
            'title' => 'Other Branch Foreign Property Deal',
            'pipeline_id' => $pipelineA->id,
            'stage_id' => $newStageA->id,
            'primary_property_id' => $otherBranchForeignProperty->id,
        ])->assertForbidden();

        $this->assertSame(1, Deal::query()->count());
    }

    public function test_hr_sees_only_hr_pipeline_and_can_work_with_hr_cards(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $hrRole = Role::create(['name' => 'HR', 'slug' => 'hr']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $hr = $this->createUser($hrRole, $branch, 'HR A');
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        [$salesPipeline] = $this->createPipelineWithStages($branch);
        [$hrPipeline, $hrNewStage, $hrOfferStage] = $this->createHrPipelineWithStages();

        Sanctum::actingAs($hr);

        $this->getJson('/api/deal-pipelines')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $hrPipeline->id)
            ->assertJsonMissing(['id' => $salesPipeline->id]);

        $createdDeal = $this->postJson('/api/deals', [
            'pipeline_id' => $hrPipeline->id,
            'stage_id' => $hrNewStage->id,
            'title' => 'Backend Developer',
            'responsible_agent_id' => $agent->id,
        ])->assertCreated()
            ->assertJsonPath('pipeline_id', $hrPipeline->id)
            ->assertJsonPath('branch_id', null)
            ->json();

        $this->postJson('/api/deals', [
            'pipeline_id' => $salesPipeline->id,
            'title' => 'Forbidden Sales Deal',
        ])->assertStatus(422);

        $this->patchJson('/api/deals/'.$createdDeal['id'].'/move', [
            'stage_id' => $hrOfferStage->id,
            'position' => 0,
        ])->assertOk()
            ->assertJsonPath('stage_id', $hrOfferStage->id);

        $this->getJson('/api/deal-pipelines/'.$hrPipeline->id.'/board')
            ->assertOk()
            ->assertJsonFragment(['id' => $createdDeal['id']]);

        $this->getJson('/api/deal-pipelines/'.$salesPipeline->id.'/board')
            ->assertForbidden();
    }

    public function test_stage_cannot_be_deleted_when_it_has_deals(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $director = $this->createUser($directorRole, $branch, 'Director A');
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $client = $this->createClient($branch, $agent, 'Deal Client', '992960000030');

        [$pipeline, $newStage] = $this->createPipelineWithStages($branch);
        $this->createDeal($pipeline, $newStage, $branch, $agent, $client, 'Protected Deal');

        Sanctum::actingAs($director);

        $this->deleteJson('/api/deal-stages/'.$newStage->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Нельзя удалить стадию: в ней есть сделки.');
    }

    public function test_security_sees_only_property_control_pipelines_across_branches(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $securityRole = Role::create(['name' => 'Security', 'slug' => 'security']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $security = $this->createUser($securityRole, $branchA, 'Security Officer');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        [$controlA, $newA] = $this->createPropertyControlPipeline($branchA);
        [$controlB, $newB] = $this->createPropertyControlPipeline($branchB);
        [$sales] = $this->createPipelineWithStages($branchA);

        $controlDealA = $this->createControlDeal($controlA, $newA, $branchA, $agentA, 'Control A');
        $controlDealB = $this->createControlDeal($controlB, $newB, $branchB, $agentB, 'Control B');
        $salesDeal = Deal::create([
            'title' => 'Sales hidden',
            'branch_id' => $branchA->id,
            'created_by' => $agentA->id,
            'pipeline_id' => $sales->id,
            'stage_id' => $sales->defaultStage()->firstOrFail()->id,
        ]);

        Sanctum::actingAs($security);

        $pipelines = $this->getJson('/api/deal-pipelines')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();
        $this->assertEqualsCanonicalizing(
            [$controlA->id, $controlB->id],
            collect($pipelines)->pluck('id')->all()
        );

        $deals = $this->getJson('/api/deals?pipeline_type=property_control')
            ->assertOk()
            ->json('data');
        $this->assertEqualsCanonicalizing(
            [$controlDealA->id, $controlDealB->id],
            collect($deals)->pluck('id')->all()
        );
        $this->assertNotContains($salesDeal->id, collect($deals)->pluck('id')->all());
    }

    public function test_security_claim_and_workflow_transitions_are_enforced(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $securityRole = Role::create(['name' => 'Security', 'slug' => 'security']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $security = $this->createUser($securityRole, $branch, 'Security Officer');
        $rop = $this->createUser($ropRole, $branch, 'ROP A');
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        [$pipeline, $new, $review, $clarification, $correction, $recheck, $verified] =
            $this->createPropertyControlPipeline($branch);
        $deal = $this->createControlDeal($pipeline, $new, $branch, $agent, 'Control card');

        Sanctum::actingAs($security);

        $this->postJson('/api/deals/'.$deal->id.'/claim')
            ->assertOk()
            ->assertJsonPath('responsible_agent_id', $security->id)
            ->assertJsonPath('stage.slug', 'security_review');

        $this->postJson('/api/deals/'.$deal->id.'/claim')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CRM_DEAL_ALREADY_CLAIMED');

        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $clarification->id,
            'comment' => 'short',
        ])->assertUnprocessable();

        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $clarification->id,
            'comment' => 'Нужно уточнить данные комиссии.',
        ])->assertOk()->assertJsonPath('stage_id', $clarification->id);

        Sanctum::actingAs($rop);
        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $verified->id,
        ])->assertUnprocessable();
        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $correction->id,
        ])->assertOk();
        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $recheck->id,
        ])->assertOk();

        Sanctum::actingAs($security);
        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $verified->id,
        ])->assertOk()->assertJsonPath('stage_id', $verified->id);

        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_id' => $deal->id,
            'actor_id' => $security->id,
            'event' => 'status_change',
        ]);
    }

    public function test_security_cannot_create_update_or_delete_deals(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $securityRole = Role::create(['name' => 'Security', 'slug' => 'security']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $security = $this->createUser($securityRole, $branch, 'Security Officer');
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        [$pipeline, $new] = $this->createPropertyControlPipeline($branch);
        $deal = $this->createControlDeal($pipeline, $new, $branch, $agent, 'Control card');

        Sanctum::actingAs($security);

        $this->postJson('/api/deals', [
            'title' => 'Forbidden',
            'pipeline_id' => $pipeline->id,
        ])->assertForbidden();
        $this->patchJson('/api/deals/'.$deal->id, ['title' => 'Changed'])->assertForbidden();
        $this->deleteJson('/api/deals/'.$deal->id)->assertForbidden();
    }

    public function test_property_control_report_filters_export_and_meta_are_scoped_to_crm_control(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $securityRole = Role::create(['name' => 'Security', 'slug' => 'security']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $security = $this->createUser($securityRole, $branchA, 'Security Officer');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');
        [$pipelineA, $newA] = $this->createPropertyControlPipeline($branchA);
        [$pipelineB, $newB] = $this->createPropertyControlPipeline($branchB);

        $this->createControlDeal($pipelineA, $newA, $branchA, $agentA, 'Control A');
        $this->createControlDeal($pipelineB, $newB, $branchB, $agentB, 'Control B');

        Sanctum::actingAs($security);

        $this->getJson('/api/crm/property-control/report?branch_id='.$branchB->id.'&stage_slug=new&source_property_status=sold')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('open', 1)
            ->assertJsonPath('closed', 0)
            ->assertJsonPath('by_stage.0.slug', 'new');

        $this->getJson('/api/crm/property-control/meta')
            ->assertOk()
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('security_officers.0.id', $security->id);

        $this->get('/api/crm/property-control/export?branch_id='.$branchA->id)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => $security->getMorphClass(),
            'auditable_id' => $security->id,
            'event' => 'property_control_report_viewed',
        ]);
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => $security->getMorphClass(),
            'auditable_id' => $security->id,
            'event' => 'property_control_exported',
        ]);
    }

    public function test_agent_sees_control_card_for_own_property_but_cannot_set_deadline_or_final_stage(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $other = $this->createUser($agentRole, $branch, 'Agent B');
        $control = $this->createPropertyControlPipeline($branch);
        $pipeline = $control[0];
        $clarification = $control[3];
        $verified = $control[6];
        $property = Property::create(['title' => 'Own property', 'created_by' => $other->id, 'agent_id' => $agent->id]);
        $deal = $this->createControlDeal($pipeline, $clarification, $branch, $other, 'Related control card');
        $deal->update(['primary_property_id' => $property->id]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/deals?pipeline_type=property_control')
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id);
        $this->postJson('/api/crm/deals/'.$deal->id.'/activities', [
            'type' => 'follow_up_changed',
            'next_activity_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();
        $this->patchJson('/api/deals/'.$deal->id.'/move', [
            'stage_id' => $verified->id,
        ])->assertUnprocessable();
        $this->deleteJson('/api/deals/'.$deal->id)->assertForbidden();
    }

    public function test_system_property_control_stages_cannot_be_edited_or_reordered(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = $this->createUser($adminRole, $branch, 'Admin');
        [$pipeline, $new] = $this->createPropertyControlPipeline($branch);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/deal-stages/'.$new->id, ['slug' => 'renamed'])->assertStatus(409);
        $this->patchJson('/api/deal-pipelines/'.$pipeline->id.'/stages/reorder', [
            'stage_ids' => $pipeline->stages()->pluck('id')->reverse()->values()->all(),
        ])->assertStatus(409);
    }

    public function test_closing_events_are_idempotent_snapshot_based_and_repeat_after_reactivation(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $property = Property::create([
            'title' => 'Apartment',
            'object_key' => 'OBJ-100',
            'moderation_status' => 'approved',
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'owner_name' => 'Owner',
            'owner_phone' => '992900000000',
            'actual_sale_price' => 125000,
            'actual_sale_currency' => 'USD',
            'company_commission_amount' => 2500,
            'company_commission_currency' => 'USD',
        ]);

        $property->update(['moderation_status' => 'sold', 'status_comment' => 'Closed by agent']);
        $first = Deal::query()->where('primary_property_id', $property->id)->sole();

        $this->assertSame('security_property_closure', $first->control_kind);
        $this->assertSame('sold', $first->source_property_status);
        $this->assertNotEmpty($first->source_event_uuid);
        $this->assertSame('Owner', data_get($first->meta, 'control.closing_snapshot.owner.name'));
        $this->assertSame(125000.0, (float) data_get($first->meta, 'control.closing_snapshot.actual_sale_price'));

        $statusLog = $property->logs()->where('action', 'status_change')->latest('id')->firstOrFail();
        $uuid = app(PropertyControlService::class)->eventUuidFor($property->fresh(), 'property-log-'.$statusLog->id);
        app(PropertyControlService::class)->syncForProperty($property->fresh(), $agent, $uuid);
        $this->assertSame(1, Deal::query()->where('primary_property_id', $property->id)->count());

        $property->update(['moderation_status' => 'approved']);
        $this->assertSame('cancelled', $first->fresh('stage')->stage->slug);

        $property->update(['moderation_status' => 'sold']);
        $this->assertSame(2, Deal::query()->where('primary_property_id', $property->id)->count());
        $this->assertNotSame($first->source_event_uuid, Deal::query()->latest('id')->firstOrFail()->source_event_uuid);
    }

    public function test_all_trigger_statuses_create_control_cards_and_missing_branch_is_audited(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');

        foreach (['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'] as $index => $status) {
            $property = Property::create([
                'title' => 'Property '.$index,
                'moderation_status' => 'approved',
                'branch_id' => $branch->id,
                'created_by' => $agent->id,
            ]);
            $property->update(['moderation_status' => $status]);
            $this->assertDatabaseHas('crm_deals', [
                'primary_property_id' => $property->id,
                'source_property_status' => $status,
                'control_kind' => 'security_property_closure',
            ]);
        }

        $orphan = Property::create(['title' => 'No branch', 'moderation_status' => 'approved']);
        $orphan->update(['moderation_status' => 'sold']);
        $this->assertDatabaseHas('crm_audit_logs', [
            'auditable_type' => $orphan->getMorphClass(),
            'auditable_id' => $orphan->id,
            'event' => 'property_control_branch_missing',
        ]);
    }

    public function test_property_control_backfill_is_idempotent_and_uses_historical_event_time(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $soldAt = now()->subDays(5)->startOfSecond();

        $property = Property::withoutEvents(fn () => Property::create([
            'title' => 'Historical sale',
            'moderation_status' => 'sold_by_owner',
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'sold_at' => $soldAt,
        ]));

        $this->artisan('security:backfill-property-control', ['--apply' => true])->assertExitCode(0);
        $first = Deal::query()->where('primary_property_id', $property->id)->sole();
        $this->assertSame($soldAt->toIso8601String(), data_get($first->meta, 'control.triggered_at'));

        $this->artisan('security:backfill-property-control', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Deal::query()->where('primary_property_id', $property->id)->count());
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

    private function createClient(Branch $branch, User $agent, string $name, string $phone): Client
    {
        return Client::create([
            'full_name' => $name,
            'phone' => $phone,
            'phone_normalized' => ClientPhone::normalize($phone),
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
        ]);
    }

    private function createPipelineWithStages(Branch $branch): array
    {
        $pipeline = DealPipeline::create([
            'name' => 'Pipeline '.$branch->id.'-'.$this->nextPhone(),
            'slug' => 'pipeline_'.$branch->id.'_'.$this->nextPhone(),
            'branch_id' => $branch->id,
            'sort_order' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        $newStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Новая',
            'slug' => 'new',
            'sort_order' => 10,
            'is_default' => true,
            'is_closed' => false,
            'is_lost' => false,
            'is_active' => true,
        ]);

        $negotiationStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Переговоры',
            'slug' => 'negotiation',
            'sort_order' => 20,
            'is_default' => false,
            'is_closed' => false,
            'is_lost' => false,
            'is_active' => true,
        ]);

        $wonStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Успешно закрыта',
            'slug' => 'won',
            'sort_order' => 30,
            'is_default' => false,
            'is_closed' => true,
            'is_lost' => false,
            'is_active' => true,
        ]);

        return [$pipeline, $newStage, $negotiationStage, $wonStage];
    }

    private function createHrPipelineWithStages(): array
    {
        $pipeline = DealPipeline::create([
            'name' => 'HR: Найм',
            'slug' => 'hr_pipeline_'.$this->nextPhone(),
            'code' => DealPipeline::CODE_HR_RECRUITMENT,
            'type' => DealPipeline::TYPE_SALES,
            'branch_id' => null,
            'sort_order' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);

        $newStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Новый отклик',
            'slug' => 'new_application',
            'sort_order' => 10,
            'is_default' => true,
            'is_closed' => false,
            'is_lost' => false,
            'is_active' => true,
        ]);

        $offerStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Оффер',
            'slug' => 'offer',
            'sort_order' => 20,
            'is_default' => false,
            'is_closed' => false,
            'is_lost' => false,
            'is_active' => true,
        ]);

        $hiredStage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Нанят',
            'slug' => 'hired',
            'sort_order' => 30,
            'is_default' => false,
            'is_closed' => true,
            'is_lost' => false,
            'is_active' => true,
        ]);

        return [$pipeline, $newStage, $offerStage, $hiredStage];
    }

    private function createPropertyControlPipeline(Branch $branch): array
    {
        $pipeline = DealPipeline::create([
            'name' => 'Контроль объектов',
            'slug' => 'property_control_'.$branch->id.'_'.$this->nextPhone(),
            'code' => DealPipeline::CODE_PROPERTY_CONTROL,
            'type' => DealPipeline::TYPE_PROPERTY_CONTROL,
            'branch_id' => $branch->id,
            'sort_order' => 20,
            'is_default' => false,
            'is_active' => true,
        ]);

        $definitions = [
            ['Новая', 'new', false, false],
            ['На проверке СБ', 'security_review', false, false],
            ['Запрос в филиал', 'branch_clarification', false, false],
            ['Исправление филиалом', 'branch_correction', false, false],
            ['Повторная проверка', 'security_recheck', false, false],
            ['Подтверждено СБ', 'security_verified', true, false],
            ['Подозрительно', 'security_flagged', true, true],
            ['Отменено', 'cancelled', true, false],
        ];
        $stages = [];

        foreach ($definitions as $index => [$name, $slug, $closed, $lost]) {
            $stages[] = DealStage::create([
                'pipeline_id' => $pipeline->id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => ($index + 1) * 10,
                'is_default' => $slug === 'new',
                'is_closed' => $closed,
                'is_lost' => $lost,
                'is_active' => true,
            ]);
        }

        return array_merge([$pipeline], $stages);
    }

    private function createControlDeal(
        DealPipeline $pipeline,
        DealStage $stage,
        Branch $branch,
        User $creator,
        string $title
    ): Deal {
        return Deal::create([
            'title' => $title,
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'source_property_status' => 'sold',
            'control_kind' => 'security_property_closure',
            'source_event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'board_position' => 1,
        ]);
    }

    private function createDeal(DealPipeline $pipeline, DealStage $stage, Branch $branch, User $agent, Client $client, string $title): Deal
    {
        return Deal::create([
            'title' => $title,
            'client_id' => $client->id,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'board_position' => ((int) Deal::query()->where('stage_id', $stage->id)->max('board_position')) + 1,
        ]);
    }

    private function nextPhone(): string
    {
        return '992'.$this->phoneCounter++;
    }
}
