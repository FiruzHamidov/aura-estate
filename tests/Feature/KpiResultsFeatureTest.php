<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\MotivationAchievement;
use App\Models\MotivationRule;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Kpi\ResultFactService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiResultsFeatureTest extends TestCase
{
    protected User $agent;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', 'Asia/Dushanbe'));
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->json('permissions')->nullable();
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('branch_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('sale_user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('moderation_status')->nullable();
            $t->timestamp('sold_at')->nullable();
            $t->decimal('actual_sale_price', 16, 2)->nullable();
            $t->string('actual_sale_currency')->nullable();
            $t->string('status_comment')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        foreach ([
            '2026_05_08_180000_create_motivation_rules_table',
            '2026_05_08_180100_create_motivation_achievements_table',
            '2026_05_08_180200_create_motivation_reward_issues_table',
            '2026_05_08_200000_add_ui_meta_and_reward_type_string_to_motivation_rules_table',
            '2026_11_05_140000_create_kpi_result_goals_and_facts',
            '2026_11_05_150000_extend_motivation_programs',
        ] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('branches')->insert(['id' => 1, 'name' => 'Филиал']);
        DB::table('branch_groups')->insert([['id' => 1, 'branch_id' => 1, 'name' => 'Группа 1'], ['id' => 2, 'branch_id' => 1, 'name' => 'Группа 2']]);
        $this->agent = $this->user('agent', 1);
        $this->admin = $this->user('admin', 1);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(string $role, int $group): User
    {
        $r = Role::firstOrCreate(['slug' => $role], ['name' => $role]);

        return User::withoutEvents(fn () => User::create([
            'name' => $role, 'role_id' => $r->id, 'branch_id' => 1, 'branch_group_id' => $group, 'status' => 'active',
        ]));
    }

    protected function property(array $changes = []): Property
    {
        return Property::withoutEvents(fn () => Property::create(array_replace([
            'agent_id' => $this->agent->id, 'sale_user_id' => $this->agent->id, 'branch_id' => 1, 'branch_group_id' => 1,
            'moderation_status' => 'sold', 'sold_at' => '2026-11-04 05:00:00',
            'actual_sale_price' => 1000, 'actual_sale_currency' => 'USD',
        ], $changes)));
    }

    protected function goal(array $changes = []): array
    {
        return array_replace([
            'month' => '2026-11', 'kind' => 'sale', 'user_id' => $this->agent->id, 'expected_version' => 0,
            'mode' => 'count_or_amount', 'count_target' => 3, 'amount_target' => 1000, 'currency' => 'TJS',
        ], $changes);
    }

    protected function program(): MotivationRule
    {
        return MotivationRule::create([
            'scope' => 'agent', 'metric_key' => 'result_goal', 'threshold_value' => 1, 'reward_type' => 'trip_tashkent',
            'name' => 'Ташкент', 'period_type' => 'month', 'date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'is_active' => true,
            'condition' => ['kind' => 'sale', 'mode' => 'count', 'count_target' => 1, 'currency' => 'TJS'],
        ]);
    }

    public function test_month_boundaries_match_for_legacy_and_ledger_in_each_storage_timezone(): void
    {
        $facts = app(ResultFactService::class);
        foreach (['UTC', 'Asia/Dushanbe'] as $timezone) {
            config(['app.timezone' => $timezone]);
            DB::table('kpi_result_facts')->delete();
            DB::table('properties')->delete();
            foreach (['2026-10-31 23:59:59', '2026-11-01 00:00:00', '2026-11-30 23:59:59', '2026-12-01 00:00:00'] as $localDate) {
                $this->property(['sold_at' => Carbon::parse($localDate, 'Asia/Dushanbe')->setTimezone($timezone)->toDateTimeString()]);
            }
            $legacyIds = $facts->forPeriod('2026-11-01', '2026-11-30')->pluck('property_id')->all();
            $this->assertCount(2, $legacyIds, $timezone);
            foreach (Property::all() as $property) {
                $facts->record($property, $timezone.'-'.$property->id);
            }
            $ledgerIds = $facts->forPeriod('2026-11-01', '2026-11-30')->pluck('property_id')->all();
            $this->assertSame($legacyIds, $ledgerIds, $timezone);
        }
    }

    public function test_hidden_branches_do_not_reappear_through_historical_facts(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->boolean('is_hidden')->default(false));
        $property = $this->property();
        app(ResultFactService::class)->record($property, 'hidden-branch');
        DB::table('branches')->where('id', 1)->update(['is_hidden' => true]);
        $this->getJson('/api/kpi/results?month=2026-11')->assertOk()->assertJsonCount(0, 'rows');
        $this->assertDatabaseCount('kpi_result_facts', 1);
    }

    public function test_rop_can_verify_only_assigned_group_and_revocation_closes_access(): void
    {
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        $rop = $this->user('rop', 1);
        $rop->supervisedGroups()->attach(1);
        $other = $this->user('agent', 2);
        $this->property();
        $this->property(['agent_id' => $other->id, 'sale_user_id' => $other->id, 'branch_group_id' => 2]);
        $rule = $this->program();
        $preview = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->json('data');
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $preview['preview_token']])->assertOk();
        $own = MotivationAchievement::where('user_id', $this->agent->id)->sole();
        $foreign = MotivationAchievement::where('user_id', $other->id)->sole();
        Sanctum::actingAs($rop->fresh());
        $this->postJson('/api/kpi/results/goals', $this->goal())->assertCreated();
        $this->postJson('/api/kpi/results/goals', $this->goal(['user_id' => $other->id]))->assertForbidden();
        $this->getJson('/api/kpi/results?month=2026-11')->assertOk()->assertJsonPath('totals.sales_count', 1);
        $this->getJson('/api/motivation/achievements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->postJson('/api/motivation/achievements/'.$own->id.'/transition', ['action' => 'verify', 'expected_version' => 1])->assertOk();
        $this->postJson('/api/motivation/achievements/'.$foreign->id.'/transition', ['action' => 'verify', 'expected_version' => 1])->assertForbidden();
        $this->postJson('/api/motivation/achievements/'.$own->id.'/transition', ['action' => 'approve', 'expected_version' => 2])->assertForbidden();
        $rop->supervisedGroups()->detach();
        Sanctum::actingAs($rop->fresh());
        $this->getJson('/api/motivation/achievements/'.$own->id.'/history')->assertForbidden();
    }

    public function test_own_plan_view_includes_applied_group_template_but_never_foreign_template(): void
    {
        $groupGoal = $this->goal(['branch_group_id' => 1]);
        unset($groupGoal['user_id']);
        $this->postJson('/api/kpi/results/goals', $groupGoal)->assertCreated();
        $this->postJson('/api/kpi/results/goals', array_replace($groupGoal, ['branch_group_id' => 2]))->assertCreated();
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/kpi/results/goals?month=2026-11')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_group_id', 1);
        $this->postJson('/api/kpi/results/goals', $groupGoal)->assertForbidden();
    }

    public function test_branch_director_can_publish_branch_program_but_not_global_or_foreign_branch(): void
    {
        $director = $this->user('branch_director', 1);
        DB::table('branches')->insert(['id' => 2, 'name' => 'Другой филиал']);
        Sanctum::actingAs($director);
        $program = ['scope' => 'agent', 'metric_key' => 'result_goal', 'threshold_value' => 1, 'reward_type' => 'gift',
            'name' => 'Цель филиала', 'period_type' => 'month', 'date_from' => '2026-12-01', 'date_to' => '2026-12-31',
            'branch_id' => 1, 'condition' => ['source' => 'independent', 'kind' => 'sale', 'mode' => 'count', 'count_target' => 3, 'currency' => 'TJS']];
        $id = $this->postJson('/api/motivation/rules', $program)->assertCreated()->assertJsonPath('data.branch_id', 1)->json('data.id');
        $this->patchJson('/api/motivation/rules/'.$id, ['is_active' => false, 'expected_version' => 1])->assertOk();
        $this->postJson('/api/motivation/rules', array_replace($program, ['branch_id' => 2]))->assertForbidden();
        $this->postJson('/api/motivation/rules', array_replace($program, ['branch_id' => null]))->assertForbidden();
    }

    public function test_earned_linked_goal_is_frozen_when_employee_moves_groups(): void
    {
        $goal = $this->goal(['branch_group_id' => 1, 'mode' => 'count', 'count_target' => 1]);
        unset($goal['user_id']);
        $this->postJson('/api/kpi/results/goals', $goal)->assertCreated();
        $this->postJson('/api/kpi/results/goals', array_replace($goal, ['branch_group_id' => 2, 'count_target' => 5]))->assertCreated();
        $property = $this->property();
        app(ResultFactService::class)->record($property, 'before-transfer');
        $rule = $this->program();
        $rule->condition = ['source' => 'kpi_goal', 'kind' => 'sale', 'mode' => 'count', 'currency' => 'TJS'];
        $rule->save();
        $preview = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->json('data');
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $preview['preview_token']])->assertOk();
        $award = MotivationAchievement::sole();
        $this->agent->forceFill(['branch_group_id' => 2])->save();
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->assertJsonCount(0, 'data.changes');
        $this->postJson('/api/motivation/achievements/'.$award->id.'/transition', ['action' => 'verify', 'expected_version' => 1])->assertOk();
        $this->assertEquals(1, $award->fresh()->meta['eligibility']['condition']['count_target']);
        $this->assertEquals(1, $award->fresh()->meta['scope']['branch_group_id']);
    }

    public function test_goals_are_versioned_and_agent_cannot_publish_or_change_current_period(): void
    {
        $this->postJson('/api/kpi/results/goals', $this->goal())->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson('/api/kpi/results/goals', $this->goal())->assertConflict();
        $this->postJson('/api/kpi/results/goals', $this->goal(['expected_version' => 1]))->assertUnprocessable();
        $future = $this->goal(['month' => '2026-12']);
        $this->postJson('/api/kpi/results/goals', $future)->assertCreated();
        $this->postJson('/api/kpi/results/goals', array_replace($future, ['expected_version' => 1, 'count_target' => 5]))
            ->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('kpi_result_goals', 3);
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/kpi/results/goals', $this->goal(['month' => '2027-01']))->assertForbidden();
    }

    public function test_rental_bases_and_unknown_fx_are_separate_from_sale_and_commission(): void
    {
        $this->postJson('/api/kpi/results/goals', $this->goal())->assertCreated();
        $this->postJson('/api/kpi/results/goals', $this->goal(['kind' => 'rental', 'mode' => 'amount', 'rental_basis' => 'contract_total']))->assertCreated();
        $this->property();
        $this->property(['moderation_status' => 'rented', 'actual_sale_currency' => 'TJS', 'rental_monthly_amount' => 200, 'rental_contract_amount' => 2400]);
        $this->property(['moderation_status' => 'rented', 'actual_sale_currency' => 'TJS']);
        Sanctum::actingAs($this->agent);
        $row = $this->getJson('/api/kpi/results?month=2026-11')->assertOk()->assertJsonCount(1, 'rows')->json('rows.0');
        $this->assertSame(1, $row['sale']['count']);
        $this->assertSame(1, $row['sale']['missing_amounts']);
        $this->assertSame(2, $row['rental']['count']);
        $this->assertEquals(2400, $row['rental']['amount']);
        $rentalDeal = collect($row['deals'])->firstWhere('kind', 'rental');
        $this->assertEquals(2400, $rentalDeal['kpi_amount']);
        $this->assertSame('TJS', $rentalDeal['kpi_currency']);
        $this->assertSame('contract_total', $rentalDeal['rental_basis']);
        $this->assertTrue($row['rental']['achieved']);
        $this->assertFalse($row['rental']['amount_complete']);
    }

    public function test_fact_events_are_idempotent_and_reopening_preserves_history_and_scope(): void
    {
        $p = $this->property(['deal_fx_rate' => 10]);
        $service = app(ResultFactService::class);
        $service->record($p, 'event-1');
        $service->record($p, 'event-1');
        $this->assertDatabaseCount('kpi_result_facts', 1);
        $this->agent->forceFill(['branch_group_id' => 2])->save();
        $p->actual_sale_price = 2000;
        $service->record($p, 'event-2');
        $this->assertSame(1, (int) $service->currentQuery()->first()->branch_group_id);
        $p->moderation_status = 'approved';
        $service->record($p, 'event-3');
        $this->assertCount(0, $service->forPeriod('2026-11-01', '2026-11-30'));
        $p->moderation_status = 'sold';
        $service->record($p, 'event-4');
        $fact = $service->forPeriod('2026-11-01', '2026-11-30')->sole();
        $this->assertSame(2, (int) $fact->branch_group_id);
        $this->assertSame(20000.0, $service->amount($fact, 'TJS', null));
        $this->assertDatabaseCount('kpi_result_facts', 4);
    }

    public function test_partial_month_program_keeps_issued_status_in_monthly_overview(): void
    {
        $this->property();
        $rule = $this->program();
        $rule->update(['date_to' => '2026-11-10']);
        $award = MotivationAchievement::create([
            'rule_id' => $rule->id, 'user_id' => $this->agent->id, 'company_scope' => false,
            'won_at' => now(), 'period_type' => 'month', 'date_from' => '2026-11-01', 'date_to' => '2026-11-10',
            'snapshot_value' => 1, 'status' => 'issued', 'issued_at' => now(),
        ]);
        foreach ([['2026-11-01', '2026-11-30'], ['2026-11-03', '2026-11-05']] as [$from, $to]) {
            $overview = app(\App\Services\MotivationService::class)->buildOverview($this->agent, 'month', $from, $to);
            $this->assertSame($award->id, $overview['cards'][0]['achievement_id']);
            $this->assertSame('issued', $overview['cards'][0]['status']);
            $this->assertNull($overview['next_reward']);
        }
    }

    public function test_reward_requires_preview_verification_approval_and_idempotent_issue(): void
    {
        $this->property();
        $rule = $this->program();
        $preview = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->json('data');
        $this->assertDatabaseCount('motivation_achievements', 0);
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $preview['preview_token'], 'reason' => 'Проверено'])->assertOk();
        $award = MotivationAchievement::sole();
        $url = '/api/motivation/achievements/'.$award->id.'/transition';
        $this->postJson($url, ['action' => 'approve', 'expected_version' => 1])->assertConflict();
        $this->postJson($url, ['action' => 'verify', 'expected_version' => 1])->assertOk();
        $this->postJson($url, ['action' => 'approve', 'expected_version' => 2])->assertUnprocessable();
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Asia/Dushanbe'));
        $this->postJson($url, ['action' => 'approve', 'expected_version' => 2])->assertOk();
        $issue = ['action' => 'issue', 'expected_version' => 3, 'issued_at' => now()->toIso8601String(), 'method' => 'trip', 'proof' => 'Билет № 0123', 'idempotency_key' => '631fa1f3-1622-4efb-a0cb-8707a4be5430'];
        $this->postJson($url, $issue)->assertOk()->assertJsonPath('data.status', 'issued');
        $this->postJson($url, $issue)->assertOk();
        $this->postJson($url, array_replace($issue, ['idempotency_key' => '631fa1f3-1622-4efb-a0cb-8707a4be5431']))->assertConflict();
        $this->assertDatabaseCount('motivation_reward_issues', 1);
        DB::table('properties')->update(['moderation_status' => 'approved']);
        $preview = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->json('data');
        $this->assertSame('issued_review', $preview['changes'][0]['action']);
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $preview['preview_token']])->assertOk();
        $this->assertSame('issued', $award->fresh()->status);
        $this->assertTrue($award->fresh()->meta['review_required']);
        $version = $award->fresh()->meta['version'];
        $this->postJson($url, ['action' => 'resolve_review', 'expected_version' => $version])->assertUnprocessable();
        $this->postJson($url, ['action' => 'resolve_review', 'expected_version' => $version, 'reason' => 'Обратная сделка проверена. Выдача остаётся в истории.'])->assertOk();
        $this->assertSame('issued', $award->fresh()->status);
        $this->assertFalse($award->fresh()->meta['review_required']);
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->assertJsonCount(0, 'data.changes');
        $this->assertDatabaseCount('motivation_reward_issues', 1);
    }

    public function test_amount_program_rejects_preview_when_facts_change_and_manage_is_not_approval(): void
    {
        $this->property();
        $rule = $this->program();
        $token = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->assertOk()->json('data.preview_token');
        DB::table('properties')->update(['moderation_status' => 'approved']);
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $token])->assertConflict();
        $this->assertDatabaseCount('motivation_achievements', 0);
    }

    public function test_cash_program_can_link_individual_kpi_without_inventing_a_shared_target(): void
    {
        $payload = [
            'scope' => 'agent', 'metric_key' => 'result_goal', 'threshold_value' => 1,
            'reward_type' => 'cash', 'name' => 'Премия по личному плану', 'period_type' => 'month',
            'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'is_active' => true,
            'condition' => ['source' => 'kpi_goal', 'kind' => 'sale', 'mode' => 'count_or_amount', 'currency' => 'TJS'],
            'reward_amount' => 500, 'reward_currency' => 'TJS',
        ];
        $id = $this->postJson('/api/motivation/rules', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/motivation/rules', array_replace($payload, ['name' => 'Вторая независимая премия']))->assertCreated();
        $this->patchJson('/api/motivation/rules/'.$id, ['is_active' => false, 'expected_version' => 1])->assertOk();
        $this->patchJson('/api/motivation/rules/'.$id, ['is_active' => true, 'expected_version' => 1])->assertConflict();
        $this->postJson('/api/motivation/rules', array_replace($payload, ['date_from' => '2026-12-02']))->assertUnprocessable();
    }

    public function test_closed_result_is_immutable_until_reviewed_correction_with_history(): void
    {
        $this->postJson('/api/kpi/results/goals', $this->goal())->assertCreated();
        $property = $this->property(['actual_sale_currency' => 'TJS']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Asia/Dushanbe'));
        $this->postJson('/api/kpi/results/close', ['month' => '2026-11', 'user_id' => $this->agent->id])->assertOk();
        DB::table('properties')->where('id', $property->id)->update(['actual_sale_price' => 2500]);
        $this->getJson('/api/kpi/results?month=2026-11&user_id='.$this->agent->id)->assertOk()->assertJsonPath('rows.0.sale.amount', 1000)->assertJsonPath('rows.0.closed', true);
        $data = ['month' => '2026-11', 'user_id' => $this->agent->id, 'preview' => true];
        $preview = $this->postJson('/api/kpi/results/adjust', $data)->assertOk()->assertJsonPath('data.after.sale.amount', 2500)->json('data');
        $this->postJson('/api/kpi/results/adjust', array_replace($data, ['preview' => false, 'preview_token' => $preview['preview_token'], 'reason' => 'Исправлена цена договора']))->assertOk();
        $this->getJson('/api/kpi/results?month=2026-11&user_id='.$this->agent->id)->assertOk()->assertJsonPath('rows.0.sale.amount', 2500)->assertJsonPath('rows.0.period_version', 2);
        $this->assertDatabaseCount('kpi_result_period_revisions', 1);
        $this->postJson('/api/kpi/results/adjust', array_replace($data, ['preview' => false, 'preview_token' => $preview['preview_token'], 'reason' => 'Повтор']))->assertConflict();
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/kpi/results/adjust', $data)->assertForbidden();
    }

    public function test_foreign_group_and_approval_permissions_are_enforced_on_server(): void
    {
        $this->property();
        $rule = $this->program();
        $preview = $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview' => true])->json('data');
        $this->postJson('/api/motivation/recalculate', ['rule_id' => $rule->id, 'preview_token' => $preview['preview_token']])->assertOk();
        $award = MotivationAchievement::sole();
        $operator = $this->user('custom_kpi', 2);
        $operator->role->forceFill(['permissions' => [
            'motivation.manage', 'motivation.verify', 'motivation.approve', 'motivation.achievements.view',
            'motivation.achievements.scope.group', 'kpi.reports.view', 'kpi.reports.scope.group',
        ]])->save();
        Sanctum::actingAs($operator->fresh());
        $this->getJson('/api/motivation/achievements')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/motivation/achievements/'.$award->id.'/transition', ['action' => 'verify', 'expected_version' => 1])->assertForbidden();
        $this->getJson('/api/kpi/results?month=2026-11&user_id='.$this->agent->id)->assertOk()->assertJsonCount(0, 'rows');
        $operator->forceFill(['branch_group_id' => 1])->save();
        $operator->role->forceFill(['permissions' => [
            'motivation.manage', 'motivation.achievements.view', 'motivation.achievements.scope.all',
        ]])->save();
        Sanctum::actingAs($operator->fresh());
        $this->postJson('/api/motivation/achievements/'.$award->id.'/transition', ['action' => 'approve', 'expected_version' => 1])->assertForbidden();
    }
}
