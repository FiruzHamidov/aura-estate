<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\CrmAuditLog;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmOperatorManagerFeatureTest extends TestCase
{
    private int $phoneCounter = 980000000;

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
            $table->unsignedBigInteger('branch_group_id')->nullable();
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

        Schema::create('client_collaborators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32)->default(Client::COLLABORATOR_ROLE_COLLABORATOR);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
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
            $table->string('moderation_status')->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->text('status_comment')->nullable();
            $table->text('rejection_comment')->nullable();
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

        \DB::table('client_need_types')->insert([
            'id' => 1,
            'name' => 'Продажа',
            'slug' => 'sell',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_operator_sees_all_branch_leads_but_not_other_branches(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $operatorRole = Role::create(['name' => 'Operator', 'slug' => 'operator']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $operator = $this->createUser($operatorRole, $branchA, 'Operator A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchA, 'Agent B');
        $agentForeign = $this->createUser($agentRole, $branchB, 'Agent C');

        $leadA = $this->createLead($branchA, $agentA, $agentA, 'Lead A');
        $leadB = $this->createLead($branchA, $agentB, $agentB, 'Lead B');
        $leadForeign = $this->createLead($branchB, $agentForeign, $agentForeign, 'Lead C');

        Sanctum::actingAs($operator);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $leadA->id])
            ->assertJsonFragment(['id' => $leadB->id])
            ->assertJsonMissing(['full_name' => $leadForeign->full_name]);

        $this->getJson('/api/leads/'.$leadB->id)->assertOk();
        $this->getJson('/api/leads/'.$leadForeign->id)->assertForbidden();
    }

    public function test_manager_sees_only_branch_property_control_cards_and_property_status_reopens_same_card(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $manager = $this->createUser($managerRole, $branchA, 'Manager A');
        $agentA = $this->createUser($agentRole, $branchA, 'Agent A');
        $agentB = $this->createUser($agentRole, $branchB, 'Agent B');

        $owner = $this->createClient($branchA, $agentA, 'Owner A');
        $property = Property::create([
            'title' => 'Object A',
            'moderation_status' => 'approved',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
            'owner_client_id' => $owner->id,
            'owner_name' => $owner->full_name,
            'owner_phone' => $owner->phone,
        ]);

        Sanctum::actingAs($agentA);
        $property->update([
            'moderation_status' => 'deleted',
            'status_comment' => 'Owner asked to remove listing',
        ]);

        $propertyControlPipeline = DealPipeline::query()
            ->where('branch_id', $branchA->id)
            ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
            ->firstOrFail();
        $createdCard = Deal::query()
            ->where('primary_property_id', $property->id)
            ->firstOrFail();

        $this->assertSame($branchA->id, $createdCard->branch_id);
        $this->assertSame('deleted', $createdCard->source_property_status);
        $this->assertSame($owner->id, $createdCard->client_id);
        $this->assertSame('new', $createdCard->stage()->value('slug'));

        [$salesPipeline, $salesStage] = $this->createPipeline($branchA, 'default_sales', DealPipeline::TYPE_SALES, false);
        Deal::create([
            'title' => 'Regular Sales Deal',
            'branch_id' => $branchA->id,
            'created_by' => $agentA->id,
            'responsible_agent_id' => $manager->id,
            'pipeline_id' => $salesPipeline->id,
            'stage_id' => $salesStage->id,
            'board_position' => 1,
            'currency' => 'TJS',
            'expected_company_income_currency' => 'TJS',
            'expected_agent_commission_currency' => 'TJS',
            'actual_company_income_currency' => 'TJS',
        ]);

        [$foreignPipeline, $foreignStage] = $this->createPipeline($branchB, DealPipeline::CODE_PROPERTY_CONTROL, DealPipeline::TYPE_PROPERTY_CONTROL, true);
        Deal::create([
            'title' => 'Foreign Property Control Deal',
            'branch_id' => $branchB->id,
            'created_by' => $agentB->id,
            'responsible_agent_id' => $agentB->id,
            'pipeline_id' => $foreignPipeline->id,
            'stage_id' => $foreignStage->id,
            'board_position' => 1,
            'currency' => 'TJS',
            'expected_company_income_currency' => 'TJS',
            'expected_agent_commission_currency' => 'TJS',
            'actual_company_income_currency' => 'TJS',
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $createdCard->id)
            ->assertJsonPath('data.0.pipeline.code', DealPipeline::CODE_PROPERTY_CONTROL);

        Sanctum::actingAs($agentA);
        $property->update(['moderation_status' => 'approved']);

        $reactivatedCard = $createdCard->fresh(['stage']);
        $this->assertSame('reactivated', $reactivatedCard->stage?->slug);

        $property->update(['moderation_status' => 'deleted']);

        $reopenedCard = $createdCard->fresh(['stage']);
        $this->assertSame('new', $reopenedCard->stage?->slug);
        $this->assertSame(1, Deal::query()->where('primary_property_id', $property->id)->count());
        $this->assertSame($propertyControlPipeline->id, $reopenedCard->pipeline_id);
    }

    public function test_branch_director_report_is_limited_to_own_branch_operators(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $operatorRole = Role::create(['name' => 'Operator', 'slug' => 'operator']);

        $director = $this->createUser($directorRole, $branchA, 'Director A');
        $operatorA = $this->createUser($operatorRole, $branchA, 'Operator A');
        $operatorB = $this->createUser($operatorRole, $branchB, 'Operator B');

        $leadA = $this->createLead($branchA, $operatorA, $operatorA, 'Lead A', nextFollowUpAt: now()->subHour());
        $leadB = $this->createLead($branchB, $operatorB, $operatorB, 'Lead B', nextFollowUpAt: now()->subHour());

        CrmAuditLog::create([
            'auditable_type' => Lead::class,
            'auditable_id' => $leadA->id,
            'actor_id' => $operatorA->id,
            'event' => 'status_change',
            'old_values' => ['status' => Lead::STATUS_NEW],
            'new_values' => ['status' => Lead::STATUS_IN_PROGRESS],
            'context' => null,
            'message' => 'Lead status changed.',
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        CrmAuditLog::create([
            'auditable_type' => Lead::class,
            'auditable_id' => $leadB->id,
            'actor_id' => $operatorB->id,
            'event' => 'status_change',
            'old_values' => ['status' => Lead::STATUS_NEW],
            'new_values' => ['status' => Lead::STATUS_IN_PROGRESS],
            'context' => null,
            'message' => 'Lead status changed.',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        Sanctum::actingAs($director);

        $this->getJson('/api/crm/reports/performance?role_type=operator&period=month')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $operatorA->id)
            ->assertJsonPath('data.0.metrics.processed_leads_count', 1)
            ->assertJsonPath('data.0.metrics.advanced_status_count', 1)
            ->assertJsonPath('data.0.metrics.overdue_follow_up_count', 1)
            ->assertJsonPath('summary.processed_leads_count', 1);
    }

    public function test_creating_deal_from_lead_copies_missing_fields_keeps_explicit_values_and_logs_origin(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $creator = $this->createUser($agentRole, $branch, 'Creator');
        $leadResponsible = $this->createUser($agentRole, $branch, 'Lead Responsible');
        $dealResponsible = $this->createUser($agentRole, $branch, 'Deal Responsible');
        $client = $this->createClient($branch, $creator, 'Client A');
        [$pipeline, $stage] = $this->createPipeline($branch, 'sales_pipeline', DealPipeline::TYPE_SALES, false);

        $lead = Lead::create([
            'full_name' => 'Lead A',
            'phone' => $leadPhone = $this->nextPhone(),
            'phone_normalized' => $leadPhone,
            'email' => 'lead@example.com',
            'note' => 'Lead note',
            'source' => 'instagram',
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $leadResponsible->id,
            'client_id' => $client->id,
            'status' => Lead::STATUS_IN_PROGRESS,
            'tags' => ['lead-tag'],
            'last_contact_result' => 'reached_owner',
            'next_follow_up_at' => now()->addDay(),
            'next_activity_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($creator);

        $explicitNextActivityAt = now()->addDays(3)->startOfMinute();

        $response = $this->postJson('/api/deals', [
            'lead_id' => $lead->id,
            'pipeline_id' => $pipeline->id,
            'note' => 'Explicit deal note',
            'tags' => ['deal-tag'],
            'responsible_agent_id' => $dealResponsible->id,
            'next_activity_at' => $explicitNextActivityAt->toISOString(),
            'meta' => [
                'custom' => [
                    'channel' => 'manual',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('source', 'instagram')
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('responsible_agent_id', $dealResponsible->id)
            ->assertJsonPath('note', 'Explicit deal note')
            ->assertJsonPath('tags.0', 'deal-tag');

        $deal = Deal::query()->with('auditLogs')->findOrFail($response->json('id'));

        $this->assertSame($branch->id, $deal->branch_id);
        $this->assertSame('instagram', $deal->source);
        $this->assertSame($client->id, $deal->client_id);
        $this->assertSame($dealResponsible->id, $deal->responsible_agent_id);
        $this->assertSame('Explicit deal note', $deal->note);
        $this->assertSame(['deal-tag'], $deal->tags);
        $this->assertSame('reached_owner', $deal->last_contact_result);
        $this->assertSame($explicitNextActivityAt->timestamp, $deal->next_activity_at?->timestamp);
        $this->assertSame('lead', data_get($deal->meta, 'origin.type'));
        $this->assertSame($lead->id, data_get($deal->meta, 'origin.lead_id'));
        $this->assertSame('Lead A', data_get($deal->meta, 'lead_snapshot.full_name'));
        $this->assertSame('lead@example.com', data_get($deal->meta, 'lead_snapshot.email'));
        $this->assertSame('instagram', data_get($deal->meta, 'lead_snapshot.source'));
        $this->assertSame('Lead note', data_get($deal->meta, 'lead_snapshot.note'));
        $this->assertSame('manual', data_get($deal->meta, 'custom.channel'));

        $createdFromLeadLog = $deal->auditLogs->firstWhere('event', 'created_from_lead');

        $this->assertNotNull($createdFromLeadLog);
        $this->assertSame($lead->id, data_get($createdFromLeadLog?->new_values, 'lead_id'));
        $this->assertSame($lead->id, data_get($createdFromLeadLog?->context, 'lead_id'));
    }

    public function test_deal_show_returns_activity_summary_and_optional_histories(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = $this->createUser($agentRole, $branch, 'Agent A');
        $owner = $this->createClient($branch, $agent, 'Owner A');
        [$pipeline, $stage] = $this->createPipeline($branch, 'sales_pipeline', DealPipeline::TYPE_SALES, false);

        $property = Property::create([
            'title' => 'Property A',
            'moderation_status' => 'deleted',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'owner_client_id' => $owner->id,
            'owner_name' => $owner->full_name,
            'owner_phone' => $owner->phone,
        ]);

        $deal = Deal::create([
            'title' => 'Deal A',
            'client_id' => $owner->id,
            'branch_id' => $branch->id,
            'created_by' => $agent->id,
            'responsible_agent_id' => $agent->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'primary_property_id' => $property->id,
            'board_position' => 1,
            'currency' => 'TJS',
            'expected_company_income_currency' => 'TJS',
            'expected_agent_commission_currency' => 'TJS',
            'actual_company_income_currency' => 'TJS',
        ]);

        $commentLog = CrmAuditLog::create([
            'auditable_type' => Deal::class,
            'auditable_id' => $deal->id,
            'actor_id' => $agent->id,
            'event' => 'comment',
            'old_values' => null,
            'new_values' => ['comment' => 'First comment'],
            'context' => ['deal_id' => $deal->id],
            'message' => 'Comment added.',
        ]);

        $statusLog = CrmAuditLog::create([
            'auditable_type' => Deal::class,
            'auditable_id' => $deal->id,
            'actor_id' => $agent->id,
            'event' => 'status_change',
            'old_values' => ['stage_id' => $stage->id],
            'new_values' => ['stage_id' => $stage->id],
            'context' => ['deal_id' => $deal->id],
            'message' => 'Deal stage changed.',
        ]);

        CrmAuditLog::query()->whereKey($commentLog->id)->update([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        CrmAuditLog::query()->whereKey($statusLog->id)->update([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $property->logs()->create([
            'user_id' => $agent->id,
            'action' => 'status_changed',
            'changes' => [
                'moderation_status' => ['old' => 'approved', 'new' => 'deleted'],
            ],
            'comment' => 'Listing moved to deleted.',
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/deals/'.$deal->id.'?include=activities,property_history')
            ->assertOk()
            ->assertJsonPath('activities_count', 2)
            ->assertJsonCount(2, 'latest_activities')
            ->assertJsonPath('latest_activities.0.event', 'status_change')
            ->assertJsonPath('activities.0.event', 'status_change')
            ->assertJsonFragment(['action' => 'status_changed']);

        $this->getJson('/api/crm/deals/'.$deal->id.'/activities?type=status_change')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'status_change');
    }

    public function test_lead_show_returns_activity_summary_and_history_route_supports_filters(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $operatorRole = Role::create(['name' => 'Operator', 'slug' => 'operator']);

        $operator = $this->createUser($operatorRole, $branch, 'Operator A');
        $lead = $this->createLead($branch, $operator, $operator, 'Lead A');

        $oldCommentLog = CrmAuditLog::create([
            'auditable_type' => Lead::class,
            'auditable_id' => $lead->id,
            'actor_id' => $operator->id,
            'event' => 'comment',
            'old_values' => null,
            'new_values' => ['comment' => 'Old comment'],
            'context' => ['lead_id' => $lead->id],
            'message' => 'Comment added.',
        ]);

        $recentCommentLog = CrmAuditLog::create([
            'auditable_type' => Lead::class,
            'auditable_id' => $lead->id,
            'actor_id' => $operator->id,
            'event' => 'comment',
            'old_values' => null,
            'new_values' => ['comment' => 'Recent comment'],
            'context' => ['lead_id' => $lead->id],
            'message' => 'Comment added.',
        ]);

        $callLog = CrmAuditLog::create([
            'auditable_type' => Lead::class,
            'auditable_id' => $lead->id,
            'actor_id' => $operator->id,
            'event' => 'call',
            'old_values' => null,
            'new_values' => ['result' => 'reached'],
            'context' => ['lead_id' => $lead->id],
            'message' => 'Call result saved.',
        ]);

        CrmAuditLog::query()->whereKey($oldCommentLog->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        CrmAuditLog::query()->whereKey($recentCommentLog->id)->update([
            'created_at' => now()->subHours(6),
            'updated_at' => now()->subHours(6),
        ]);

        CrmAuditLog::query()->whereKey($callLog->id)->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($operator);

        $this->getJson('/api/leads/'.$lead->id.'?include=activities')
            ->assertOk()
            ->assertJsonPath('activities_count', 3)
            ->assertJsonCount(3, 'latest_activities')
            ->assertJsonPath('latest_activities.0.event', 'call')
            ->assertJsonPath('activities.0.event', 'call');

        $this->getJson('/api/crm/leads/'.$lead->id.'/activities?type=comment&date_from='.urlencode(now()->subDay()->toISOString()))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'comment')
            ->assertJsonPath('data.0.new_values.comment', 'Recent comment');
    }

    public function test_operator_can_attach_existing_hidden_client_to_lead_without_changing_owner(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $operatorRole = Role::create(['name' => 'Operator', 'slug' => 'operator']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $operator = $this->createUser($operatorRole, $branch, 'Operator A');
        $ownerAgent = $this->createUser($agentRole, $branch, 'Owner Agent');
        $leadAgent = $this->createUser($agentRole, $branch, 'Lead Agent');
        $client = $this->createClient($branch, $ownerAgent, 'Private Client');
        $lead = $this->createLead($branch, $operator, $leadAgent, 'Lead A');

        Sanctum::actingAs($operator);

        $this->postJson('/api/clients/attach-existing', [
            'client_id' => $client->id,
            'context_type' => 'lead',
            'context_id' => $lead->id,
        ])
            ->assertOk()
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('context.context_type', 'lead')
            ->assertJsonPath('context.context_id', $lead->id);

        $lead->refresh();
        $client->refresh();

        $this->assertSame($client->id, $lead->client_id);
        $this->assertSame($ownerAgent->id, $client->responsible_agent_id);
        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $operator->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
        ]);
        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $leadAgent->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
        ]);
    }

    public function test_manager_can_attach_existing_hidden_client_to_deal(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $manager = $this->createUser($managerRole, $branch, 'Manager A');
        $ownerAgent = $this->createUser($agentRole, $branch, 'Owner Agent');
        $dealAgent = $this->createUser($agentRole, $branch, 'Deal Agent');
        $client = $this->createClient($branch, $ownerAgent, 'Deal Client');
        [$pipeline, $stage] = $this->createPipeline($branch, DealPipeline::CODE_PROPERTY_CONTROL, DealPipeline::TYPE_PROPERTY_CONTROL, true);

        $deal = Deal::create([
            'title' => 'Control Deal',
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'responsible_agent_id' => $dealAgent->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'board_position' => 1,
            'currency' => 'TJS',
            'expected_company_income_currency' => 'TJS',
            'expected_agent_commission_currency' => 'TJS',
            'actual_company_income_currency' => 'TJS',
        ]);

        Sanctum::actingAs($manager);

        $this->postJson('/api/clients/attach-existing', [
            'client_id' => $client->id,
            'context_type' => 'deal',
            'context_id' => $deal->id,
        ])
            ->assertOk()
            ->assertJsonPath('context.context_type', 'deal')
            ->assertJsonPath('context.context_id', $deal->id);

        $deal->refresh();

        $this->assertSame($client->id, $deal->client_id);
        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $dealAgent->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
        ]);
    }

    public function test_branch_director_can_attach_existing_hidden_client_to_branch_lead(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $director = $this->createUser($directorRole, $branch, 'Director A');
        $ownerAgent = $this->createUser($agentRole, $branch, 'Owner Agent');
        $leadAgent = $this->createUser($agentRole, $branch, 'Lead Agent');
        $client = $this->createClient($branch, $ownerAgent, 'Director Client');
        $lead = $this->createLead($branch, $leadAgent, $leadAgent, 'Lead B');

        Sanctum::actingAs($director);

        $this->postJson('/api/clients/attach-existing', [
            'client_id' => $client->id,
            'context_type' => 'lead',
            'context_id' => $lead->id,
        ])
            ->assertOk()
            ->assertJsonPath('context.context_type', 'lead')
            ->assertJsonPath('context.context_id', $lead->id);

        $this->assertDatabaseHas('client_collaborators', [
            'client_id' => $client->id,
            'user_id' => $leadAgent->id,
            'role' => Client::COLLABORATOR_ROLE_VIEWER,
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

    private function createClient(Branch $branch, User $creator, string $fullName): Client
    {
        $phone = $this->nextPhone();

        return Client::create([
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $creator->id,
            'client_type_id' => 1,
            'contact_kind' => Client::CONTACT_KIND_SELLER,
            'status' => 'active',
        ]);
    }

    private function createLead(
        Branch $branch,
        User $creator,
        User $responsible,
        string $name,
        ?string $phone = null,
        $nextFollowUpAt = null
    ): Lead {
        $phone ??= $this->nextPhone();

        return Lead::create([
            'full_name' => $name,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $branch->id,
            'created_by' => $creator->id,
            'responsible_agent_id' => $responsible->id,
            'status' => Lead::STATUS_NEW,
            'next_follow_up_at' => $nextFollowUpAt,
            'next_activity_at' => $nextFollowUpAt,
        ]);
    }

    private function createPipeline(Branch $branch, string $code, string $type, bool $propertyControl): array
    {
        $pipeline = DealPipeline::create([
            'name' => $propertyControl ? 'Контроль объектов' : 'Продажи',
            'slug' => $code.'_branch_'.$branch->id,
            'code' => $code,
            'type' => $type,
            'branch_id' => $branch->id,
            'sort_order' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);

        $stage = DealStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Новая',
            'slug' => 'new',
            'color' => '#64748b',
            'sort_order' => 10,
            'is_default' => true,
            'is_closed' => false,
            'is_lost' => false,
            'is_active' => true,
        ]);

        if ($propertyControl) {
            DealStage::create([
                'pipeline_id' => $pipeline->id,
                'name' => 'Реактивирован',
                'slug' => 'reactivated',
                'color' => '#16a34a',
                'sort_order' => 20,
                'is_default' => false,
                'is_closed' => true,
                'is_lost' => false,
                'is_active' => true,
            ]);
        }

        return [$pipeline, $stage];
    }

    private function nextPhone(): string
    {
        return '992'.(++$this->phoneCounter);
    }
}
