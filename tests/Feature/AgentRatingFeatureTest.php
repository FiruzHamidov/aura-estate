<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentWorkspace\RatingLedger;
use App\Services\AgentWorkspace\RatingSources;
use App\Services\Kpi\ResultFactService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Listing-based reporting plus regression coverage for the separate review ledger. */
class AgentRatingFeatureTest extends KpiResultsFeatureTest
{
    private User $confirmer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(Carbon::getTestNow());
        (require database_path('migrations/2026_11_25_120000_create_agent_rating_ledger.php'))->up();
        foreach (['2026_05_04_120000_create_kpi_module_tables', '2026_05_05_180000_extend_kpi_plans_for_personal_periods', '2026_05_06_140000_extend_kpi_plans_for_common_scope', '2026_05_09_162458_add_plan_period_to_kpi_plans'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->confirmer = $this->user('superadmin', 1);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function source(User $agent, string $commission = '10000', string $kind = 'sold'): object
    {
        $property = $this->property(['sale_user_id' => $agent->id, 'moderation_status' => $kind, 'company_commission_amount' => $commission, 'company_commission_currency' => 'TJS']);
        app(ResultFactService::class)->record($property, (string) Str::uuid());
        app(RatingSources::class)->sync();

        return DB::table('agent_rating_sources')->where('property_id', $property->id)->latest('id')->first();
    }

    private function draft(object $source, array $participants, array $overrides = []): object
    {
        $commission = json_decode($source->financials, true)['commission'];

        return app(RatingLedger::class)->draft($this->admin, $source->id, array_replace([
            'event_key' => (string) Str::uuid(), 'source_version' => $source->source_version,
            'commission' => (string) $commission, 'refund' => '0', 'currency' => 'TJS', 'reason' => 'Проверка финансов сделки',
            'participants' => $participants,
        ], $overrides));
    }

    private function confirm(object $source, User $agent): void
    {
        $revision = $this->draft($source, [['agent_id' => $agent->id, 'side' => 'closing', 'share_bps' => 10000]]);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
    }

    public function test_invalid_shares_and_excess_refunds_are_rejected(): void
    {
        $s = $this->source($this->agent);
        $body = ['event_key' => (string) Str::uuid(), 'source_version' => $s->source_version, 'reason' => 'Проверка долей', 'commission' => '100', 'refund' => '0', 'currency' => 'TJS',
            'participants' => [['agent_id' => $this->agent->id, 'side' => 'listing', 'share_bps' => 4000], ['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 7000]]];
        $this->postJson('/api/agent-ratings/sources/'.$s->id.'/revisions', $body)->assertUnprocessable()->assertJsonValidationErrors('participants', 'details.errors');
        $body['participants'][1]['share_bps'] = 6000;
        $body['refund'] = '101';
        $body['correction_reference'] = 'Возврат';
        $this->postJson('/api/agent-ratings/sources/'.$s->id.'/revisions', $body)->assertUnprocessable()->assertJsonValidationErrors('refund', 'details.errors');
    }

    public function test_source_revision_invalidates_approval_and_reopening_creates_a_new_cycle(): void
    {
        $source = $this->source($this->agent);
        $this->confirm($source, $this->agent);
        $p = \App\Models\Property::find($source->property_id);
        $p->company_commission_amount = 12000;
        app(ResultFactService::class)->record($p, (string) Str::uuid());
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('data_quality', 'partial')->assertJsonCount(0, 'rows');
        $p->moderation_status = 'approved';
        app(ResultFactService::class)->record($p, (string) Str::uuid());
        $p->moderation_status = 'rented';
        app(ResultFactService::class)->record($p, (string) Str::uuid());
        app(RatingSources::class)->sync();
        self::assertSame(2, DB::table('agent_rating_sources')->where('property_id', $p->id)->count());
        self::assertSame('reversed', DB::table('agent_rating_sources')->find($source->id)->state);
    }

    public function test_crm_records_share_one_canonical_cycle_and_ambiguous_links_require_review(): void
    {
        $this->crmFixture();
        $source = $this->source($this->agent);
        $deal = $this->crmDeal(['primary_property_id' => $source->property_id, 'closed_at' => CarbonImmutable::parse($source->closed_at, 'UTC')->setTimezone(config('app.timezone'))->toDateTimeString()]);
        app(RatingSources::class)->sync(true);
        self::assertSame(1, DB::table('agent_rating_sources')->count());
        self::assertSame($deal, (int) DB::table('agent_rating_sources')->find($source->id)->deal_id);
        $other = $this->crmDeal(['primary_property_id' => $source->property_id, 'closed_at' => '2026-11-04 10:00:00']);
        app(RatingSources::class)->sync(true);
        $duplicate = DB::table('agent_rating_sources')->where('deal_id', $other)->first();
        self::assertSame('needs_link', $duplicate->state);
        app(RatingLedger::class)->link($this->admin, $duplicate->id, $source->id, 'Подтверждён тот же договор');
        app(RatingSources::class)->sync(true);
        self::assertSame('duplicate', DB::table('agent_rating_sources')->find($duplicate->id)->state);
        $this->confirm($source, $this->agent);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 1)->assertJsonPath('excluded_count', 1);
    }

    public function test_crm_reopen_creates_new_cycle_deletion_removes_it_and_unknown_date_stays_partial(): void
    {
        $this->crmFixture();
        $id = $this->crmDeal(['closed_at' => '2026-11-04 10:00:00']);
        app(RatingSources::class)->sync(true);
        $source = DB::table('agent_rating_sources')->where('deal_id', $id)->first();
        self::assertSame('unknown', $source->kind);
        $revision = $this->draft($source, [['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 10000]], ['kind' => 'sale']);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        DB::table('agent_rating_sources')->where('id', $source->id)->update(['financials' => json_encode(['fx_rate' => null, 'currency' => 'TJS', 'commission' => 10000])]);
        app(RatingSources::class)->sync(true);
        self::assertSame((int) $revision->id, (int) DB::table('agent_rating_sources')->find($source->id)->approved_revision_id);
        DB::table('crm_deals')->where('id', $id)->update(['stage_id' => 2, 'closed_at' => null]);
        app(RatingSources::class)->sync(true);
        self::assertSame('reversed', DB::table('agent_rating_sources')->find($source->id)->state);
        DB::table('crm_deals')->where('id', $id)->update(['stage_id' => 1, 'closed_at' => '2026-11-05 06:00:00']);
        app(RatingSources::class)->sync(true);
        self::assertSame(2, DB::table('agent_rating_sources')->count());
        self::assertNull(DB::table('agent_rating_sources')->where('deal_id', $id)->first()->approved_revision_id);
        DB::table('crm_deals')->where('id', $id)->update(['deleted_at' => now()]);
        $this->crmDeal(['closed_at' => null]);
        app(RatingSources::class)->sync(true);
        self::assertSame('reversed', DB::table('agent_rating_sources')->where('deal_id', $id)->first()->state);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('excluded_count', 0)->assertJsonPath('data_quality', 'complete');
    }

    public function test_service_pipeline_sources_are_excluded_without_removing_audit_or_property_results(): void
    {
        $this->crmFixture();
        $property = $this->source($this->agent);
        $this->confirm($property, $this->agent);
        $linked = $this->crmDeal(['primary_property_id' => $property->property_id, 'closed_at' => CarbonImmutable::parse($property->closed_at, 'UTC')->setTimezone(config('app.timezone'))->toDateTimeString()]);
        $independent = $this->crmDeal([]);
        app(RatingSources::class)->sync(true);
        $crmSource = DB::table('agent_rating_sources')->where('deal_id', $independent)->first();
        $revision = $this->draft($crmSource, [['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 10000]], ['kind' => 'sale']);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        self::assertSame($linked, (int) DB::table('agent_rating_sources')->find($property->id)->deal_id);
        DB::table('crm_deal_pipelines')->where('id', 1)->update(['code' => 'property_control']);
        app(RatingSources::class)->sync(true);
        $excluded = DB::table('agent_rating_sources')->find($crmSource->id);
        self::assertSame('excluded', $excluded->state);
        self::assertNull($excluded->approved_revision_id);
        self::assertTrue(DB::table('agent_rating_revisions')->where('id', $revision->id)->exists());
        self::assertTrue(DB::table('agent_rating_approvals')->where('revision_id', $revision->id)->exists());
        self::assertNull(DB::table('agent_rating_sources')->find($property->id)->deal_id);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('excluded_count', 1)
            ->assertJsonPath('summary.score_minor', 0)->assertJsonPath('summary.deal_count', 1);
        $this->getJson('/api/agent-ratings/sources?month=2026-11')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $property->id);
        app(RatingSources::class)->sync(true);
        self::assertSame((int) $excluded->source_version, (int) DB::table('agent_rating_sources')->find($crmSource->id)->source_version);
        // Reproduce an old service source referencing the same property: a later KPI
        // correction must update the property cycle, never reactivate that service source.
        DB::table('agent_rating_sources')->where('id', $crmSource->id)->update(['property_id' => $property->property_id]);
        $p = \App\Models\Property::find($property->property_id);
        $p->company_commission_amount = '15000';
        app(ResultFactService::class)->record($p, (string) Str::uuid());
        app(RatingSources::class)->sync(true);
        self::assertSame('excluded', DB::table('agent_rating_sources')->find($crmSource->id)->state);
        self::assertSame(15000, json_decode(DB::table('agent_rating_sources')->find($property->id)->financials, true)['commission']);
    }

    public function test_service_pipelines_are_never_ingested_and_reclassification_requires_new_review(): void
    {
        $this->crmFixture();
        $id = $this->crmDeal(['meta' => json_encode(['transaction_kind' => 'sale'])]);
        foreach (['property_control', 'hr_recruitment'] as $code) {
            DB::table('crm_deal_pipelines')->where('id', 1)->update(['code' => $code]);
            app(RatingSources::class)->sync(true);
            self::assertSame(0, DB::table('agent_rating_sources')->count());
        }
        DB::table('crm_deal_pipelines')->where('id', 1)->update(['code' => null]);
        app(RatingSources::class)->sync(true);
        $source = DB::table('agent_rating_sources')->where('deal_id', $id)->first();
        $this->confirm($source, $this->agent);
        DB::table('crm_deal_pipelines')->where('id', 1)->update(['code' => 'property_control']);
        app(RatingSources::class)->sync(true);
        DB::table('crm_deal_pipelines')->where('id', 1)->update(['code' => null]);
        app(RatingSources::class)->sync(true);
        $restored = DB::table('agent_rating_sources')->find($source->id);
        self::assertSame('closed', $restored->state);
        self::assertNull($restored->approved_revision_id);
        self::assertNull($restored->pending_revision_id);
        self::assertGreaterThan($source->source_version, $restored->source_version);
    }

    private function participant(object $property, User $agent, ?string $amount, string $role = 'main', string $currency = 'TJS'): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('property_agent_sales')) {
            (require database_path('migrations/2025_12_31_115529_create_property_agent_sales_table.php'))->up();
        }
        DB::table('property_agent_sales')->insert(['property_id' => $property->id, 'agent_id' => $agent->id,
            'role' => $role, 'agent_commission_amount' => $amount, 'agent_commission_currency' => $currency]);
    }

    public function test_rating_uses_moderation_participants_without_crm_or_financial_approval(): void
    {
        $helper = $this->user('agent', 2);
        $p = $this->property(['company_commission_amount' => 99000, 'company_commission_currency' => 'TJS']);
        $this->participant($p, $this->agent, '2000');
        $this->participant($p, $helper, '800', 'assistant');
        $response = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()
            ->assertJsonPath('data_source', 'listing_participants')->assertJsonPath('data_quality', 'complete')
            ->assertJsonPath('summary.score_minor', 280000)->assertJsonPath('summary.deal_count', 1)
            ->assertJsonPath('summary.agent_count', 2)->assertJsonPath('rows.0.agent_id', $this->agent->id)
            ->assertJsonPath('rows.1.agent_id', $helper->id)->assertJsonPath('rows.1.score_minor', 80000)
            ->assertJsonPath('show_personal_result', false);
        $this->getJson('/api/agent-ratings/'.$helper->id.'/breakdown?snapshot_id='.$response->json('snapshot_id'))
            ->assertOk()->assertJsonPath('rows.0.property_id', $p->id)->assertJsonPath('rows.0.role', 'assistant')
            ->assertJsonPath('total_minor', 80000);
        $this->getJson('/api/agent-ratings/rules?version='.$response->json('rules_version'))
            ->assertOk()->assertJsonPath('version', 'aura-listing-participants-v2');
        self::assertSame(0, DB::table('agent_rating_sources')->count());
        self::assertSame(0, DB::table('agent_rating_approvals')->count());
    }

    public function test_participant_list_takes_precedence_over_seller_and_listing_owner(): void
    {
        $participant = $this->user('agent', 2);
        $p = $this->property();
        $this->participant($p, $participant, '1234.56', 'partner');
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.agent_id', $participant->id)->assertJsonPath('rows.0.score_minor', 123456);
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('me.deal_count', 0);
        Sanctum::actingAs($participant);
        $metrics = collect($this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame(123456, $metrics['revenue']['value']);
        self::assertSame(1, $metrics['deals']['value']);
        self::assertSame(123456, array_sum(array_column($metrics['revenue']['series'], 'value')));
    }

    public function test_roles_rental_and_scope_filters_use_the_participant_data(): void
    {
        $helper = $this->user('agent', 2);
        $p = $this->property();
        $this->participant($p, $this->agent, '1000', 'main');
        $this->participant($p, $helper, '200', 'partner');
        $rent = $this->property(['moderation_status' => 'rented']);
        $this->participant($rent, $helper, '50', 'assistant');
        foreach (['main' => 100000, 'partner' => 20000, 'assistant' => 5000, 'rental' => 5000] as $category => $score) {
            $this->getJson('/api/agent-ratings?month=2026-11&category='.$category)->assertOk()
                ->assertJsonPath('summary.score_minor', $score)->assertJsonPath('summary.deal_count', 1);
        }
        Sanctum::actingAs($helper);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()
            ->assertJsonPath('me.score_minor', 25000)->assertJsonPath('me.deal_count', 2)->assertJsonPath('me.rank', 2);
        $this->getJson('/api/agent-ratings?month=2026-11&scope=all')->assertForbidden();
        $this->getJson('/api/agent-ratings?month=2026-11&scope=branch:999')->assertForbidden();
    }

    public function test_missing_commissions_or_fx_do_not_hide_closed_listing_counts(): void
    {
        $p = $this->property();
        $this->participant($p, $this->agent, null);
        $usd = $this->property();
        $this->participant($usd, $this->agent, '100', 'main', 'USD');
        $zero = $this->property();
        $this->participant($zero, $this->agent, '0', 'main', 'USD');
        $this->property(); // legacy closing without participants
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()
            ->assertJsonPath('summary.deal_count', 4)->assertJsonPath('summary.score_minor', 0)
            ->assertJsonPath('excluded_count', 3)->assertJsonPath('data_quality', 'partial')->assertJsonCount(0, 'rows');
        Sanctum::actingAs($this->agent);
        $metrics = collect($this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame(4, $metrics['deals']['value']);
        self::assertSame('complete', $metrics['deals']['status']);
        self::assertSame('partial', $metrics['revenue']['status']);
        $result = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk();
        $this->getJson('/api/agent-ratings/'.$this->agent->id.'/breakdown?snapshot_id='.$result->json('snapshot_id'))
            ->assertOk()->assertJsonPath('rows.0.credit_minor', null)->assertJsonPath('rows.2.credit_minor', 0);
    }

    public function test_dollar_commissions_use_saved_fractional_rate_and_ignore_company_income(): void
    {
        $p = $this->property(['deal_fx_rate' => '10.12345678', 'company_commission_amount' => '99999']);
        $this->participant($p, $this->agent, '10.01', 'main', 'USD');
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.score_minor', 10134);
    }

    public function test_participant_edits_reopening_and_deletion_update_new_snapshots_only(): void
    {
        $p = $this->property();
        $this->participant($p, $this->agent, '100');
        $snapshot = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->json('snapshot_id');
        DB::table('property_agent_sales')->where('property_id', $p->id)->update(['agent_commission_amount' => 200]);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.score_minor', 20000);
        $this->getJson('/api/agent-ratings/participants?snapshot_id='.$snapshot)->assertOk()->assertJsonPath('summary.score_minor', 10000);
        DB::table('properties')->where('id', $p->id)->update(['moderation_status' => 'approved']);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 0);
        DB::table('properties')->where('id', $p->id)->update(['moderation_status' => 'sold', 'sold_at' => '2026-11-05 09:00:00']);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 1);
        DB::table('properties')->where('id', $p->id)->update(['deleted_at' => now()]);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 0);
    }

    public function test_company_dashboard_and_agent_dashboard_use_consistent_listing_counts(): void
    {
        $helper = $this->user('agent', 2);
        $p = $this->property();
        DB::table('properties')->where('id', $p->id)->update(['created_at' => '2026-11-04 12:00:00']);
        $this->participant($p, $this->agent, '100');
        $this->participant($p, $helper, '50', 'assistant');
        $response = $this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()
            ->assertJsonPath('scope', 'all')->assertJsonPath('scope_label', 'Вся компания')->assertJsonCount(0, 'goals');
        $metrics = collect($response->json('metrics'))->keyBy('key');
        self::assertSame(15000, $metrics['revenue']['value']);
        self::assertSame(1, $metrics['deals']['value']);
        self::assertSame(1, $metrics['objects']['value']);
        foreach ($metrics as $metric) {
            if ($metric['status'] !== 'unavailable') {
                self::assertSame($metric['value'], array_sum(array_column($metric['series'], 'value')));
            }
        }
        Sanctum::actingAs($helper);
        $personal = $this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->assertJsonPath('scope', 'own');
        self::assertSame(5000, collect($personal->json('metrics'))->firstWhere('key', 'revenue')['value']);
    }

    public function test_month_boundaries_future_and_undated_closings_are_handled_in_dushanbe_time(): void
    {
        config(['app.timezone' => 'UTC']);
        foreach (['2026-10-31 18:59:59', '2026-10-31 19:00:00', '2026-11-30 19:00:00', '2026-11-10 00:00:00', null] as $date) {
            $p = $this->property(['sold_at' => $date]);
            $this->participant($p, $this->agent, '100');
        }
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 1)
            ->assertJsonPath('summary.score_minor', 10000)->assertJsonPath('excluded_count', 1);
        Sanctum::actingAs($this->agent);
        $metrics = collect($this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame(1, $metrics['deals']['value']);
        self::assertSame('2026-11-01', $metrics['revenue']['series'][0]['date']);
        self::assertSame(10000, $metrics['revenue']['series'][0]['value']);
    }

    public function test_ties_top_fifteen_and_zero_amount_have_stable_ranks(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $agent = $this->user('agent', 1);
            $p = $this->property();
            $this->participant($p, $agent, $i < 14 ? (string) (20000 - $i * 100) : ($i === 17 ? '0' : '1000'));
        }
        $result = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->json();
        self::assertCount(17, $result['rows']);
        self::assertSame([15, 15, 15], array_column(array_slice($result['rows'], 14), 'rank'));
        self::assertSame(17, $result['total_ranked']);
        $this->getJson('/api/agent-ratings/participants?snapshot_id='.$result['snapshot_id'])->assertOk()->assertJsonCount(18, 'rows');
    }

    public function test_snapshot_access_revocation_and_peer_financial_details_remain_protected(): void
    {
        $p = $this->property();
        $other = $this->user('agent', 2);
        $this->participant($p, $other, '100');
        Sanctum::actingAs($this->agent);
        $id = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->json('snapshot_id');
        $this->getJson('/api/agent-ratings/'.$other->id.'/breakdown?snapshot_id='.$id)->assertOk()
            ->assertJsonCount(0, 'rows')->assertJsonPath('restricted_credit_minor', 10000);
        $this->agent->role->forceFill(['permissions' => ['agent_rating.view', 'agent_rating.scope.own']])->save();
        $this->agent->unsetRelation('role');
        $this->getJson('/api/agent-ratings/participants?snapshot_id='.$id)->assertForbidden();
    }

    public function test_hidden_branches_are_excluded_and_crm_only_commissions_do_not_enter_rating(): void
    {
        $this->crmFixture();
        $this->crmDeal(['meta' => json_encode(['transaction_kind' => 'sale'])]);
        app(RatingSources::class)->sync(true);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.score_minor', 0)->assertJsonPath('data_quality', 'complete');
        (require database_path('migrations/2026_10_16_010000_add_branch_visibility.php'))->up();
        $p = $this->property();
        $this->participant($p, $this->agent, '100');
        DB::table('branches')->where('id', 1)->update(['is_hidden' => true]);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.deal_count', 0);
        $metrics = collect($this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame(0, $metrics['deals']['value']);
    }

    private function crmFixture(): void
    {
        \Illuminate\Support\Facades\Schema::create('crm_deal_pipelines', function ($t) {
            $t->id();
            $t->string('code')->nullable();
        });
        \Illuminate\Support\Facades\Schema::create('crm_deal_stages', function ($t) {
            $t->id();
            $t->boolean('is_closed');
            $t->boolean('is_lost')->default(false);
        });
        \Illuminate\Support\Facades\Schema::create('crm_deals', function ($t) {
            $t->id();
            $t->unsignedBigInteger('pipeline_id');
            $t->unsignedBigInteger('stage_id');
            $t->unsignedBigInteger('primary_property_id')->nullable();
            $t->unsignedBigInteger('responsible_agent_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->decimal('actual_company_income', 16, 2)->nullable();
            $t->string('actual_company_income_currency')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        DB::table('crm_deal_pipelines')->insert(['id' => 1, 'code' => null]);
        DB::table('crm_deal_stages')->insert([['id' => 1, 'is_closed' => true], ['id' => 2, 'is_closed' => false]]);
    }

    private function crmDeal(array $overrides): int
    {
        return DB::table('crm_deals')->insertGetId(array_replace(['pipeline_id' => 1, 'stage_id' => 1, 'primary_property_id' => null,
            'responsible_agent_id' => $this->agent->id, 'branch_id' => 1, 'branch_group_id' => 1, 'actual_company_income' => 10000,
            'actual_company_income_currency' => 'TJS', 'meta' => '{}', 'closed_at' => '2026-11-04 10:00:00'], $overrides));
    }
}
