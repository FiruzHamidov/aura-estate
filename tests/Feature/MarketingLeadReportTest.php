<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketing\LeadReceiptTracker;
use App\Services\Marketing\MarketingLeadReport;
use App\Support\ClientPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class MarketingLeadReportTest extends LeadFeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_25_160000_create_marketing_attribution_tables.php'))->up();
        Schema::table('properties', function (Blueprint $t) {
            $t->string('moderation_status')->nullable();
            $t->timestamp('sold_at')->nullable();
            $t->unsignedBigInteger('buyer_client_id')->nullable();
            $t->string('buyer_phone')->nullable();
            $t->unsignedBigInteger('sale_user_id')->nullable();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('crm_client_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->timestamp('start_time');
            $t->string('status');
        });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->string('result_code')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('related_entity_type');
            $t->unsignedBigInteger('related_entity_id');
            $t->unsignedBigInteger('assignee_id');
        });
        $this->travelTo(now()->setDate(2026, 9, 25)->setTime(12, 0));
    }

    private function actor(string $role, int $branch = 1): User
    {
        $r = Role::firstOrCreate(['slug' => $role], ['name' => $role]);
        Branch::firstOrCreate(['id' => $branch], ['name' => 'Branch '.$branch]);

        return User::create(['name' => $role, 'role_id' => $r->id, 'branch_id' => $branch, 'phone' => (string) random_int(900000000, 999999999), 'status' => 'active']);
    }

    private function marketingLead(User $creator, User $agent, string $phone = '+992 900 111 222'): Lead
    {
        $lead = new Lead(['created_by' => $creator->id, 'responsible_agent_id' => $agent->id, 'branch_id' => $agent->branch_id,
            'full_name' => 'Buyer', 'phone' => $phone, 'phone_normalized' => ClientPhone::normalize($phone), 'source' => 'Instagram',
            'status' => 'assigned']);
        $lead->forceFill(['created_at' => now()->subDays(10)])->save();

        return $lead;
    }

    private function report(User $actor, array $filters = []): array
    {
        return app(MarketingLeadReport::class)->report($actor->loadMissing('role'), $filters + ['date_from' => '2026-09-01', 'date_to' => '2026-09-25', 'horizon_days' => 30]);
    }

    public function test_marketing_receipt_is_immutable_and_assignment_history_is_retained(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $next = $this->actor('agent');
        $lead = $this->marketingLead($marketer, $agent);
        $lead->update(['source' => 'Other', 'responsible_agent_id' => $next->id]);
        $this->assertDatabaseHas('marketing_lead_receipts', ['lead_id' => $lead->id, 'source' => 'Instagram']);
        $this->assertSame(2, DB::table('marketing_lead_assignments')->where('lead_id', $lead->id)->count());
        $this->assertNotNull(DB::table('marketing_lead_assignments')->where('agent_id', $agent->id)->value('ended_at'));
        app(LeadReceiptTracker::class)->capture($lead, true);
        $this->assertSame(1, DB::table('marketing_lead_receipts')->count());
    }

    public function test_marketing_duplicate_and_existing_contacts_are_not_new_acquisitions(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $first = $this->marketingLead($marketer, $agent);
        $repeat = $this->marketingLead($marketer, $agent, '992900111222');
        $existingClient = new Client(['full_name' => 'Existing', 'phone_normalized' => ClientPhone::normalize('900222333')]);
        $existingClient->forceFill(['created_at' => now()->subMonth()])->save();
        $existing = $this->marketingLead($marketer, $agent, '900222333');
        $this->assertDatabaseHas('marketing_lead_receipts', ['lead_id' => $repeat->id, 'classification' => 'repeat']);
        $this->assertDatabaseHas('marketing_lead_receipts', ['lead_id' => $existing->id, 'classification' => 'existing']);
        $report = $this->report($marketer);
        $this->assertSame(2, $report['summary']['unique_contacts']);
        $this->assertSame(1, $report['summary']['new_contacts']);
    }

    public function test_marketing_phone_match_requires_review_and_does_not_double_count(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $admin = $this->actor('admin');
        $lead = $this->marketingLead($marketer, $agent);
        $repeat = $this->marketingLead($marketer, $agent);
        $property = DB::table('properties')->insertGetId(['buyer_phone' => '+992900111222', 'sold_at' => now()->subDay(), 'moderation_status' => 'sold', 'sale_user_id' => $agent->id, 'branch_id' => 1]);
        $report = $this->report($admin);
        $this->assertSame(0, $report['summary']['sales']);
        $this->assertSame(1, $report['summary']['candidate_sales']);
        Sanctum::actingAs($admin);
        $url = '/api/crm/marketing-leads/'.$lead->id.'/sales/'.$property.'/review';
        $this->postJson($url, ['decision' => 'confirm', 'reason' => 'Проверен покупатель и источник'])->assertOk();
        $this->postJson('/api/crm/marketing-leads/'.$repeat->id.'/sales/'.$property.'/review', ['decision' => 'confirm', 'reason' => 'Другой лид'])->assertStatus(409);
        $report = $this->report($admin);
        $this->assertSame(1, $report['summary']['sales']);
        $this->assertSame(1, $report['summary']['buying_contacts']);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'marketing_sale_review']);
        Sanctum::actingAs($agent);
        $this->postJson($url, ['decision' => 'reject', 'reason' => 'Сам себе проверил'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson($url, ['decision' => 'reject', 'reason' => 'Связь не подтвердилась'])->assertOk();
        $this->assertSame(0, $this->report($admin)['summary']['sales']);
    }

    public function test_marketing_status_changes_and_scheduled_bookings_are_not_work(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $lead = $this->marketingLead($marketer, $agent);
        $lead->update(['status' => 'converted', 'first_contacted_at' => now()]);
        $report = $this->report($marketer);
        $this->assertSame(1, $report['summary']['no_actions']);
        $this->assertSame(0, $report['summary']['sales']);
        DB::table('crm_audit_logs')->insert(['auditable_type' => Lead::class, 'auditable_id' => $lead->id, 'actor_id' => $agent->id,
            'event' => 'call', 'new_values' => json_encode(['result' => 'Не ответил']), 'context' => json_encode(['contact_outcome' => 'no_answer']), 'created_at' => now(), 'updated_at' => now()]);
        $report = $this->report($marketer);
        $this->assertSame(1, $report['summary']['worked_contacts']);
        $this->assertSame(0, $report['summary']['contacted']);
    }

    public function test_marketing_report_preserves_agent_and_branch_scope_and_sale_time_window(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $other = $this->actor('agent', 2);
        $lead = $this->marketingLead($marketer, $agent);
        $this->marketingLead($marketer, $other, '900999888');
        DB::table('properties')->insert(['buyer_phone' => $lead->phone, 'sold_at' => now()->subMonth(), 'moderation_status' => 'sold', 'sale_user_id' => $agent->id, 'branch_id' => 1]);
        Sanctum::actingAs($agent);
        $this->getJson('/api/crm/marketing-leads?date_from=2026-09-01&date_to=2026-09-25')->assertOk()
            ->assertJsonPath('summary.leads', 1)->assertJsonPath('summary.candidate_sales', 0);
        $this->assertSame(0, $this->report($agent, ['user_ids' => [$other->id]])['summary']['leads']);
        Sanctum::actingAs($this->actor('client'));
        $this->getJson('/api/crm/marketing-leads?date_from=2026-09-01&date_to=2026-09-25')->assertForbidden();
    }

    public function test_marketing_direct_sale_requires_matching_deal_buyer_and_reversal_removes_it(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $lead = $this->marketingLead($marketer, $agent);
        $client = Client::create(['full_name' => 'Buyer']);
        $lead->update(['client_id' => $client->id]);
        $property = DB::table('properties')->insertGetId(['buyer_client_id' => $client->id, 'sold_at' => now()->subDay(), 'moderation_status' => 'sold', 'sale_user_id' => $agent->id, 'branch_id' => 1]);
        $deal = Deal::create(['lead_id' => $lead->id, 'client_id' => $client->id, 'primary_property_id' => $property, 'branch_id' => 1,
            'pipeline_id' => 1, 'stage_id' => 1, 'actual_company_income' => 1250, 'actual_company_income_currency' => 'TJS']);
        $report = $this->report($marketer);
        $this->assertSame(1, $report['summary']['sales']);
        $this->assertEquals(1250, $report['summary']['income']['TJS']);
        $deal->update(['client_id' => null]);
        $this->assertSame(0, $this->report($marketer)['summary']['sales']);
        $deal->update(['client_id' => $client->id]);
        DB::table('properties')->where('id', $property)->update(['moderation_status' => 'approved']);
        $this->assertSame(0, $this->report($marketer)['summary']['sales']);
    }

    public function test_marketing_manual_activity_counts_and_repeats_do_not_inflate_unworked_numbers(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $lead = $this->marketingLead($marketer, $agent);
        $this->marketingLead($marketer, $agent);
        Sanctum::actingAs($agent);
        $url = '/api/crm/leads/'.$lead->id.'/activities';
        $this->postJson($url, ['type' => 'show_completed'])->assertUnprocessable();
        $this->postJson($url, ['type' => 'show_completed', 'comment' => 'Показ состоялся'])->assertSuccessful();
        $this->postJson($url, ['type' => 'call', 'result' => 'Договорились о встрече', 'contact_outcome' => 'connected'])->assertSuccessful();
        $report = $this->report($marketer);
        $this->assertSame(1, $report['summary']['worked_contacts']);
        $this->assertSame(1, $report['summary']['shown_contacts']);
        $this->assertSame(1, $report['summary']['contacted']);
        $this->assertSame(0, $report['summary']['no_actions']);
        $this->assertSame(0, $report['agents'][0]['no_actions']);
    }

    public function test_marketing_returning_classification_requires_manager_and_reason(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $admin = $this->actor('admin');
        $this->marketingLead($marketer, $agent);
        $repeat = $this->marketingLead($marketer, $agent);
        $url = '/api/crm/marketing-leads/'.$repeat->id.'/classification';
        Sanctum::actingAs($agent);
        $this->postJson($url, ['returning' => true, 'reason' => 'Новая кампания вернула клиента'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson($url, ['returning' => true, 'reason' => 'Новая кампания вернула клиента'])->assertOk();
        $this->assertSame(1, $this->report($admin)['summary']['returning_leads']);
        $this->assertDatabaseHas('marketing_lead_receipts', ['lead_id' => $repeat->id, 'classification' => 'repeat']);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'marketing_classification_review']);
        $this->postJson($url, ['returning' => false, 'reason' => 'Возврат не подтверждён'])->assertOk();
        $this->assertSame(0, $this->report($admin)['summary']['returning_leads']);
    }

    public function test_marketing_horizon_excludes_late_actions_and_backfill_is_idempotent(): void
    {
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $lead = $this->marketingLead($marketer, $agent);
        $this->travel(31)->days();
        DB::table('crm_audit_logs')->insert(['auditable_type' => Lead::class, 'auditable_id' => $lead->id, 'actor_id' => $agent->id,
            'event' => 'call', 'new_values' => json_encode(['result' => 'Поздний звонок']), 'created_at' => now(), 'updated_at' => now()]);
        $report = $this->report($marketer);
        $this->assertSame(1, $report['summary']['mature_leads']);
        $this->assertSame(0, $report['summary']['worked_contacts']);
        $this->assertEquals(0, $report['summary']['mature_conversion_pct']);
        $this->assertSame(1, $this->report($marketer, ['horizon_days' => 60])['summary']['worked_contacts']);
        DB::table('marketing_lead_assignments')->delete();
        DB::table('marketing_lead_receipts')->delete();
        (require database_path('migrations/2026_10_14_110000_backfill_marketing_lead_receipts.php'))->up();
        (require database_path('migrations/2026_10_14_110000_backfill_marketing_lead_receipts.php'))->up();
        $this->assertDatabaseCount('marketing_lead_receipts', 1);
        $this->assertDatabaseCount('marketing_lead_assignments', 1);
        $this->assertDatabaseHas('marketing_lead_receipts', ['historical' => true]);
    }
    public function test_marketing_report_keeps_assigned_group_boundary_even_for_admin_role(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $marketer = $this->actor('marketing');
        $agent = $this->actor('agent');
        $admin = $this->actor('admin');
        $group = \App\Models\BranchGroup::create(['branch_id' => 1, 'name' => 'Allowed']);
        $other = \App\Models\BranchGroup::create(['branch_id' => 1, 'name' => 'Hidden']);
        $admin->role->forceFill(['permissions' => ['data.scope.assigned_groups', 'leads.scope.all']])->save();
        $admin->supervisedGroups()->attach($group->id);
        $agent->update(['branch_group_id' => $group->id]);
        $lead = $this->marketingLead($marketer, $agent);
        $lead->update(['branch_group_id' => $group->id]);
        $otherAgent = $this->actor('agent');
        $otherAgent->update(['branch_group_id' => $other->id]);
        $hidden = $this->marketingLead($marketer, $otherAgent, '900999887');
        $hidden->update(['branch_group_id' => $other->id]);
        $this->assertSame(1, $this->report($admin)['summary']['leads']);
        $this->assertFalse($this->report($admin)['meta']['can_review']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/marketing-leads/'.$lead->id.'/classification', ['returning' => true, 'reason' => 'Review denied'])
            ->assertForbidden();
        $admin->role->forceFill(['permissions' => []])->save();
        $this->getJson('/api/crm/marketing-leads?date_from=2026-09-01&date_to=2026-09-25')->assertForbidden();
    }

}
