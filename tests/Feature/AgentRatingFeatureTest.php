<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentWorkspace\RatingLedger;
use App\Services\AgentWorkspace\RatingReport;
use App\Services\AgentWorkspace\RatingSources;
use App\Services\Kpi\ResultFactService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Reuses the production-shaped KPI fixture to exercise the new financial boundary. */
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

    public function test_more_money_with_two_sales_is_above_three_sales_and_pagination_uses_same_snapshot(): void
    {
        $other = $this->user('agent', 2);
        for ($i = 0; $i < 2; $i++) {
            $this->confirm($this->source($this->agent, '20000'), $this->agent);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->confirm($this->source($other, '10000'), $other);
        }
        Sanctum::actingAs($this->agent);
        $response = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()
            ->assertJsonPath('rows.0.agent_id', $this->agent->id)->assertJsonPath('rows.0.deal_count', 2)
            ->assertJsonPath('rows.0.score_minor', 4000000)->assertJsonPath('rows.1.deal_count', 3);
        $this->getJson('/api/agent-ratings/participants?snapshot_id='.$response->json('snapshot_id'))->assertOk()->assertJsonPath('rows.0.rank', 1);
        $this->getJson('/api/agent-ratings/'.$this->agent->id.'/breakdown?snapshot_id='.$response->json('snapshot_id'))
            ->assertOk()->assertJsonPath('total_minor', 4000000)->assertJsonCount(2, 'rows');
    }

    public function test_roles_shares_refunds_and_zero_fee_unique_count(): void
    {
        $source = $this->source($this->agent);
        $participants = [['agent_id' => $this->agent->id, 'side' => 'listing', 'share_bps' => 4000], ['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 6000]];
        $revision = $this->draft($source, $participants, ['refund' => '2000', 'correction_reference' => 'Возврат комиссии №1']);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        $r = app(RatingReport::class);
        $all = $r->build($this->agent, '2026-11', 'all', null);
        self::assertSame(800000, $all['me']['score_minor']);
        self::assertSame(1, $all['me']['deal_count']);
        self::assertSame(320000, $r->build($this->agent, '2026-11', 'listing', null)['me']['score_minor']);
        self::assertSame(480000, $r->build($this->agent, '2026-11', 'closing', null)['me']['score_minor']);
        self::assertSame(0, $r->build($this->agent, '2026-11', 'rental', null)['me']['score_minor']);
        $this->confirm($this->source($this->agent, '0'), $this->agent);
        self::assertSame(2, $r->build($this->agent, '2026-11', 'all', null)['me']['deal_count']);
    }

    public function test_unconfirmed_sources_are_partial_and_self_confirmation_is_forbidden(): void
    {
        $source = $this->source($this->agent);
        $revision = $this->draft($source, [['agent_id' => $this->agent->id, 'side' => 'listing', 'share_bps' => 10000]]);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('data_quality', 'partial')->assertJsonCount(0, 'rows');
        $this->postJson('/api/agent-ratings/revisions/'.$revision->id.'/confirm')->assertForbidden();
        Sanctum::actingAs($this->confirmer);
        $this->postJson('/api/agent-ratings/revisions/'.$revision->id.'/confirm')->assertOk();
        $this->postJson('/api/agent-ratings/revisions/'.$revision->id.'/confirm')->assertOk();
        self::assertSame(1, DB::table('agent_rating_approvals')->count());
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

    public function test_scope_tampering_and_old_snapshot_after_permission_revocation_are_denied(): void
    {
        $this->confirm($this->source($this->agent), $this->agent);
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/agent-ratings?scope=all')->assertForbidden();
        $this->getJson('/api/agent-ratings?scope=branch:999')->assertForbidden();
        $id = $this->getJson('/api/agent-ratings')->assertOk()->json('snapshot_id');
        $role = $this->agent->role;
        $role->forceFill(['permissions' => ['agent_rating.view', 'agent_rating.scope.own']])->save();
        $this->agent->unsetRelation('role');
        $this->getJson('/api/agent-ratings/participants?snapshot_id='.$id)->assertForbidden();
        $this->getJson('/api/agent-ratings')->assertOk()->assertJsonPath('total_ranked', null)->assertJsonCount(0, 'rows')->assertJsonPath('me.rank', null);
    }

    public function test_closed_month_keeps_its_original_snapshot_until_explicit_new_version(): void
    {
        $source = $this->source($this->agent);
        $this->confirm($source, $this->agent);
        Carbon::setTestNow('2026-12-02 10:00:00');
        CarbonImmutable::setTestNow('2026-12-02 10:00:00');
        $ledger = app(RatingLedger::class);
        $ledger->close($this->confirmer, '2026-11', 'Месяц проверен', 0);
        $source = DB::table('agent_rating_sources')->find($source->id);
        $rev = $this->draft($source, [['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 10000]], ['refund' => '2000', 'correction_reference' => 'Акт возврата']);
        $ledger->confirm($this->confirmer, $rev->id);
        $report = app(RatingReport::class);
        self::assertSame(1000000, $report->build($this->agent, '2026-11', 'all', null)['me']['score_minor']);
        $ledger->close($this->confirmer, '2026-11', 'Коррекция по акту возврата', 1);
        $new = $report->build($this->agent, '2026-11', 'all', null);
        self::assertSame(800000, $new['me']['score_minor']);
        self::assertSame('corrected', $new['status']);
        self::assertSame(2, DB::table('agent_rating_periods')->count());
    }

    public function test_dashboard_card_totals_match_daily_series_and_unknown_sources_are_not_zero(): void
    {
        $this->confirm($this->source($this->agent, '1234.56'), $this->agent);
        Sanctum::actingAs($this->agent);
        $result = $this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json();
        $metrics = collect($result['metrics'])->keyBy('key');
        self::assertSame(123456, $metrics['revenue']['value']);
        self::assertSame(123456, array_sum(array_column($metrics['revenue']['series'], 'value')));
        self::assertSame(1, $metrics['deals']['value']);
        self::assertSame('unavailable', $metrics['contracts']['status']);
        self::assertNull($metrics['contracts']['value']);
        self::assertNull($result['goals'][0]['target']);
    }

    public function test_top_fifteen_places_include_all_tied_agents_and_zero_has_no_rank(): void
    {
        for ($i = 0; $i < 17; $i++) {
            $agent = $this->user('agent', 1);
            $this->confirm($this->source($agent, $i < 14 ? (string) (20000 - $i * 100) : '1000'), $agent);
        }
        $zero = $this->user('agent', 1);
        $this->confirm($this->source($zero, '0'), $zero);
        $result = $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->json();
        self::assertCount(17, $result['rows']);
        self::assertSame([15, 15, 15], array_column(array_slice($result['rows'], 14), 'rank'));
        self::assertSame(17, $result['total_ranked']);
    }

    public function test_financial_correction_preserves_closing_office_after_transfer(): void
    {
        $s = $this->source($this->agent);
        $this->confirm($s, $this->agent);
        DB::table('branches')->insert(['id' => 2, 'name' => 'Новый филиал']);
        $this->agent->forceFill(['branch_id' => 2, 'branch_group_id' => null])->saveQuietly();
        $source = DB::table('agent_rating_sources')->find($s->id);
        $revision = $this->draft($source, [['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 10000]], ['refund' => '1000', 'correction_reference' => 'Акт']);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        $report = app(RatingReport::class);
        self::assertSame(900000, $report->build($this->admin, '2026-11', 'all', 'branch:1')['rows'][0]['score_minor']);
        self::assertCount(0, $report->build($this->admin, '2026-11', 'all', 'branch:2')['rows']);
    }

    public function test_pending_correction_keeps_confirmed_amount_and_marks_quality(): void
    {
        $s = $this->source($this->agent);
        $this->confirm($s, $this->agent);
        $s = DB::table('agent_rating_sources')->find($s->id);
        $this->draft($s, [['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 10000]], ['commission' => '20000']);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('pending_count', 1)->assertJsonPath('data_quality', 'partial')->assertJsonPath('rows.0.score_minor', 1000000);
    }

    public function test_pending_shared_commission_is_visible_to_the_other_office_and_personal_dashboard(): void
    {
        DB::table('branches')->insert(['id' => 2, 'name' => 'Другой филиал']);
        $participant = $this->user('agent', 2);
        $participant->forceFill(['branch_id' => 2, 'branch_group_id' => null])->saveQuietly();
        $participant->role->forceFill(['permissions' => ['agent_rating.view', 'agent_rating.scope.own', 'agent_rating.scope.branch', 'kpi.reports.view']])->save();
        $participant->unsetRelation('role');
        $source = $this->source($this->agent);
        $participants = [
            ['agent_id' => $this->agent->id, 'side' => 'closing', 'share_bps' => 6000],
            ['agent_id' => $participant->id, 'side' => 'listing', 'share_bps' => 4000, 'branch_id' => 2, 'branch_group_id' => null],
        ];
        $revision = $this->draft($source, $participants);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        $source = DB::table('agent_rating_sources')->find($source->id);
        $this->draft($source, $participants, ['commission' => '20000']);

        Sanctum::actingAs($participant);
        foreach (['own', 'branch:2'] as $scope) {
            $this->getJson('/api/agent-ratings?month=2026-11&scope='.$scope)->assertOk()
                ->assertJsonPath('pending_count', 1)->assertJsonPath('data_quality', 'partial')
                ->assertJsonPath('me.score_minor', 400000);
        }
        $this->getJson('/api/agent-ratings?month=2026-11&scope=own&category=rental')->assertOk()
            ->assertJsonPath('pending_count', 0)->assertJsonPath('data_quality', 'complete');
        $metrics = collect($this->getJson('/api/agent-dashboard?month=2026-11')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame('partial', $metrics['revenue']['status']);
        self::assertSame('partial', $metrics['deals']['status']);
        self::assertSame(400000, $metrics['revenue']['value']);
        self::assertSame(1, $metrics['deals']['value']);
        $rental = collect($this->getJson('/api/agent-dashboard?month=2026-11&kind=rental')->assertOk()->json('metrics'))->keyBy('key');
        self::assertSame('complete', $rental['revenue']['status']);

        Sanctum::actingAs($this->user('agent', 2));
        $this->getJson('/api/agent-ratings?month=2026-11&scope=own')->assertOk()
            ->assertJsonPath('pending_count', 0)->assertJsonPath('data_quality', 'complete');
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
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('rows.0.deal_count', 1)->assertJsonPath('excluded_count', 0);
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
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('excluded_count', 1)->assertJsonPath('data_quality', 'partial');
    }

    public function test_admin_defaults_to_company_and_overview_counts_contracts_once(): void
    {
        $other = $this->user('agent', 2);
        $source = $this->source($this->agent);
        $revision = $this->draft($source, [
            ['agent_id' => $this->agent->id, 'side' => 'listing', 'share_bps' => 4000],
            ['agent_id' => $other->id, 'side' => 'closing', 'share_bps' => 6000],
        ]);
        app(RatingLedger::class)->confirm($this->confirmer, $revision->id);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('scope', 'all')
            ->assertJsonPath('summary.score_minor', 1000000)->assertJsonPath('summary.deal_count', 1)
            ->assertJsonPath('summary.agent_count', 2)->assertJsonPath('show_personal_result', false);
        $this->getJson('/api/agent-ratings?month=2026-11&scope=branch:1')->assertOk()->assertJsonPath('scope', 'branch:1');
        $this->getJson('/api/agent-ratings?month=2026-11&scope=own')->assertForbidden();
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('scope', 'branch:1')
            ->assertJsonPath('summary', null)->assertJsonPath('show_personal_result', true);
    }

    public function test_admin_with_actual_contribution_keeps_personal_result(): void
    {
        $this->confirm($this->source($this->admin), $this->admin);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('show_personal_result', true)
            ->assertJsonPath('me.score_minor', 1000000)->assertJsonPath('summary.score_minor', 1000000);
    }

    public function test_recorded_commission_is_separate_from_ranking_and_respects_scope_and_kind(): void
    {
        DB::table('branches')->insert(['id' => 2, 'name' => 'Другой филиал']);
        $this->source($this->agent, '12000');
        $this->source($this->agent, '2000', 'rented');
        $invalid = $this->source($this->agent);
        DB::table('agent_rating_sources')->where('id', $invalid->id)->update(['financials' => json_encode(['commission' => '50', 'currency' => 'USD', 'fx_rate' => null])]);
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('summary.score_minor', 0)
            ->assertJsonPath('summary.recorded_unranked_minor', 1400000)->assertJsonPath('summary.recorded_unranked_count', 2)
            ->assertJsonPath('excluded_count', 3)->assertJsonCount(0, 'rows');
        $this->getJson('/api/agent-ratings?month=2026-11&category=rental')->assertOk()
            ->assertJsonPath('summary.recorded_unranked_minor', 200000)->assertJsonPath('summary.recorded_unranked_count', 1);
        $this->getJson('/api/agent-ratings?month=2026-11&category=listing')->assertOk()->assertJsonPath('summary.recorded_unranked_count', 0);
        $this->getJson('/api/agent-ratings?month=2026-11&scope=branch:2')->assertOk()->assertJsonPath('summary.recorded_unranked_count', 0);
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
        $this->getJson('/api/agent-ratings?month=2026-11')->assertOk()->assertJsonPath('excluded_count', 0)
            ->assertJsonPath('summary.score_minor', 1000000)->assertJsonPath('summary.deal_count', 1);
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
