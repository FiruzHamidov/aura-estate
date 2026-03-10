<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
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
