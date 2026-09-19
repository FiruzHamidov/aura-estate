<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\CrmTask;
use App\Models\CrmTaskType;
use App\Models\DailyReport;
use App\Models\KpiEarlyRiskAlert;
use App\Models\KpiPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KpiModuleApiFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);

        Schema::dropAllTables();
        Schema::create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('slug')->unique(), $t->timestamps()]);
        Schema::create('branches', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('branch_groups', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id'), $t->string('name'), $t->string('contact_visibility_mode')->default('group_only'), $t->timestamps()]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('phone')->unique(); $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active'); $t->string('auth_method')->default('password'); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('daily_reports', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('role_slug')->nullable(); $t->date('report_date');
            $t->unsignedInteger('calls_count')->default(0); $t->unsignedInteger('ad_count')->default(0); $t->unsignedInteger('meetings_count')->default(0);
            $t->unsignedInteger('shows_count')->default(0); $t->unsignedInteger('new_clients_count')->default(0); $t->unsignedInteger('new_properties_count')->default(0);
            $t->unsignedInteger('deposits_count')->default(0); $t->unsignedInteger('deals_count')->default(0);
            $t->text('comment')->nullable(); $t->text('plans_for_tomorrow')->nullable(); $t->timestamp('submitted_at')->nullable(); $t->timestamps();
        });
        Schema::create('crm_task_types', function (Blueprint $t) { $t->id(); $t->string('code', 64)->unique(); $t->string('name', 128); $t->string('group', 64)->default('kpi'); $t->boolean('is_kpi')->default(false); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('task_type_id'); $t->unsignedBigInteger('assignee_id'); $t->unsignedBigInteger('creator_id')->nullable();
            $t->string('title', 255); $t->text('description')->nullable(); $t->string('status', 32)->default('new'); $t->string('result_code', 64)->nullable();
            $t->string('related_entity_type', 32)->nullable(); $t->unsignedBigInteger('related_entity_id')->nullable();
            $t->timestamp('due_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->string('source', 32)->default('manual'); $t->timestamps();
        });

        Schema::create('kpi_period_locks', function (Blueprint $t) { $t->id(); $t->string('period_type', 16); $t->string('period_key', 32); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->unsignedBigInteger('locked_by'); $t->timestamp('locked_at'); $t->timestamps();});
        Schema::create('kpi_adjustment_logs', function (Blueprint $t) { $t->id(); $t->string('period_type',16); $t->string('period_key',32); $t->unsignedBigInteger('entity_id')->nullable(); $t->string('field_name',64); $t->decimal('old_value',14,4)->nullable(); $t->decimal('new_value',14,4)->nullable(); $t->text('reason'); $t->unsignedBigInteger('changed_by'); $t->timestamp('changed_at'); $t->timestamps(); });

        Schema::create('kpi_plans', function (Blueprint $t) {
            $t->id();
            $t->string('role_slug',64);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('metric_key',64);
            $t->decimal('daily_plan',14,4)->default(0);
            $t->decimal('weight',8,4)->default(0);
            $t->string('comment',500)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('auditable_type');
            $t->unsignedBigInteger('auditable_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('event', 100);
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->json('context')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });
        Schema::create('kpi_integration_statuses', function (Blueprint $t) { $t->id(); $t->string('code',64)->unique(); $t->string('name',128); $t->string('status',32)->default('unknown'); $t->timestamp('last_checked_at')->nullable(); $t->json('details')->nullable(); $t->timestamps(); });
        Schema::create('kpi_telegram_report_configs', function (Blueprint $t) { $t->id(); $t->boolean('daily_enabled')->default(false); $t->string('daily_time',5)->default('09:00'); $t->boolean('weekly_enabled')->default(true); $t->unsignedTinyInteger('weekly_day')->default(1); $t->string('weekly_time',5)->default('10:00'); $t->string('timezone',64)->default('Asia/Dushanbe'); $t->timestamps(); });
        Schema::create('kpi_quality_issues', function (Blueprint $t) { $t->id(); $t->string('title',255); $t->string('severity',32)->default('medium'); $t->timestamp('detected_at')->nullable(); $t->string('status',32)->default('open'); $t->json('details')->nullable(); $t->timestamps(); });
        Schema::create('kpi_early_risk_alerts', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->date('alert_date'); $t->string('status',32)->default('acknowledged'); $t->string('message',500)->nullable(); $t->json('meta')->nullable(); $t->timestamps(); });
        Schema::create('kpi_acceptance_runs', function (Blueprint $t) { $t->id(); $t->string('run_type',64)->default('daily'); $t->string('status',32)->default('success'); $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->json('details')->nullable(); $t->timestamps();});
        Schema::create('kpi_rop_plans', function (Blueprint $t) {
            $t->id();
            $t->string('role_slug', 64);
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('month', 7);
            $t->json('items');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) { $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token',64)->unique(); $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); });
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        (require database_path('migrations/2026_09_08_170000_add_kpi_alert_group.php'))->up();
        (require database_path('migrations/2026_09_08_180000_add_kpi_diagnostic_groups.php'))->up();
        (require database_path('migrations/2026_09_08_190000_add_kpi_adjustment_group.php'))->up();
    }

    public function test_daily_report_edit_uses_snapshot_group_after_employee_transfer(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $groups = collect(['A', 'C'])->mapWithKeys(fn ($name) => [$name => BranchGroup::create(['name' => $name, 'branch_id' => $branch->id])]);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = User::create(['name' => 'Transferred agent', 'phone' => '900000011', 'role_id' => $agentRole->id,
            'branch_id' => $branch->id, 'branch_group_id' => $groups['C']->id]);
        $rops = [];
        foreach (['A', 'C'] as $i => $group) {
            $rops[$group] = User::create(['name' => 'ROP '.$group, 'phone' => '90000002'.$i,
                'role_id' => $ropRole->id, 'branch_id' => $branch->id]);
            $rops[$group]->supervisedGroups()->attach($groups[$group]->id);
        }
        $report = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $groups['A']->id,
            'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 3,
            'new_properties_count' => 17, 'deals_count' => 4]);
        Sanctum::actingAs($rops['C']);
        $before = $report->fresh()->getAttributes();
        $this->patchJson('/api/daily-reports/'.$report->id, ['calls_count' => 99])->assertNotFound();
        $this->getJson('/api/kpi/daily/report?date=2026-05-04&employee_id='.$agent->id)->assertNotFound();
        $this->patchJson('/api/kpi/daily/report', ['report_date' => '2026-05-04',
            'employee_id' => $agent->id, 'ads' => 2, 'calls' => 99])->assertNotFound();
        $this->assertSame($before, $report->fresh()->getAttributes());

        Sanctum::actingAs($rops['A']);
        $this->patchJson('/api/daily-reports/'.$report->id, ['calls_count' => 8])->assertOk()
            ->assertJsonPath('calls_count', 8)->assertJsonPath('new_properties_count', 17)
            ->assertJsonPath('deals_count', 4)->assertJsonPath('branch_group_id', $groups['A']->id)
            ->assertJsonPath('user.id', $agent->id)->assertJsonMissingPath('user.phone');
        $this->assertSame('agent', $report->fresh()->role_slug);
        $this->getJson('/api/kpi/daily/report?date=2026-05-04&employee_id='.$agent->id)->assertOk()
            ->assertJsonPath('metrics.objects.fact_value', 17)->assertJsonPath('manual.calls', 8);
        $this->getJson('/api/kpi/daily/report?date=2026-05-03&employee_id='.$agent->id)->assertNotFound();


        Schema::table('daily_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_role')->nullable();
            $table->string('updated_reason')->nullable();
            $table->string('edit_source')->nullable();
        });
        $today = \Carbon\Carbon::now(config('kpi.timezone', 'Asia/Dushanbe'))->toDateString();
        $report->update(['report_date' => $today]);
        $this->patchJson('/api/kpi/daily/report', ['report_date' => $today,
            'employee_id' => $agent->id, 'ads' => 2, 'calls' => 8])->assertOk()
            ->assertJsonPath('metrics.objects.fact_value', 17)->assertJsonPath('manual.calls', 8);
        $this->assertSame((int) $groups['A']->id, (int) $report->fresh()->branch_group_id);

        $groupPlan = KpiPlan::create(['role_slug' => 'agent', 'user_id' => null,
            'branch_id' => $branch->id, 'branch_group_id' => $groups['A']->id,
            'metric_key' => 'objects', 'daily_plan' => 5, 'weight' => 1]);
        KpiPlan::create(['role_slug' => 'agent', 'user_id' => $agent->id,
            'branch_id' => $branch->id, 'branch_group_id' => $groups['C']->id,
            'metric_key' => 'objects', 'daily_plan' => 999, 'weight' => 1]);
        KpiPlan::create(['role_slug' => 'agent', 'user_id' => null,
            'branch_id' => $branch->id, 'branch_group_id' => null,
            'metric_key' => 'shows', 'daily_plan' => 777, 'weight' => 1]);
        $this->getJson('/api/kpi/daily/report?date='.$today.'&employee_id='.$agent->id)->assertOk()
            ->assertJsonPath('metrics.objects.target_value', 5)
            ->assertJsonPath('metrics.shows.target_value', null)
            ->assertJsonPath('meta.debug.plan_resolution.metrics.objects.source_record_id', $groupPlan->id)
            ->assertJsonPath('meta.debug.plan_resolution.metrics.shows.source_record_id', null);
        $this->getJson('/api/daily-reports?report_date='.$today)->assertOk()
            ->assertJsonPath('data.0.metrics.objects.fact_value', 17)
            ->assertJsonPath('data.0.metrics.objects.target_value', 5);

        $otherBranch = Branch::create(['name' => 'Other branch']);
        $otherGroup = BranchGroup::create(['name' => 'D', 'branch_id' => $otherBranch->id]);
        \DB::table('users')->where('id', $agent->id)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => $otherGroup->id]);
        $this->getJson('/api/daily-reports?user_id='.$agent->id.'&report_date='.$today)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $report->id)
            ->assertJsonMissingPath('data.0.user.phone');
        $stranger = User::create(['name' => 'No history', 'phone' => '900000033', 'role_id' => $agentRole->id,
            'branch_id' => $otherBranch->id, 'branch_group_id' => $otherGroup->id]);
        $this->getJson('/api/daily-reports?user_id='.$stranger->id)->assertForbidden();
        $this->getJson('/api/daily-reports?user_id='.$agent->id.'&branch_group_id='.$groups['C']->id)->assertForbidden();

        $rops['A']->supervisedGroups()->detach();
        $this->putJson('/api/daily-reports/'.$report->id, ['calls_count' => 10])->assertNotFound();
        $this->assertSame(8, $report->fresh()->calls_count);
    }

    public function test_period_kpi_keeps_automatic_facts_and_personal_plans_in_their_historical_group(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $groups = [];
        foreach (['A', 'B', 'C'] as $name) $groups[$name] = BranchGroup::create(['name' => $name, 'branch_id' => $branch->id]);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '918000001', 'role_id' => $ropRole->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Transferred', 'phone' => '918000002', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $groups['C']->id]);
        $rop->supervisedGroups()->attach([$groups['A']->id, $groups['B']->id]);
        $type = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call']);
        foreach (['A' => 1, 'B' => 2, 'C' => 3] as $name => $count) {
            $group = $groups[$name];
            \DB::table('daily_reports')->insert(['user_id' => $agent->id, 'branch_group_id' => $group->id, 'role_slug' => 'agent', 'report_date' => '2026-05-01']);
            for ($i = 0; $i < $count; $i++) \DB::table('crm_tasks')->insert(['task_type_id' => $type->id, 'assignee_id' => $agent->id, 'creator_id' => $agent->id, 'branch_group_id' => $group->id, 'title' => 'Historical call', 'status' => 'done', 'completed_at' => '2026-05-01 10:00:00']);
            KpiPlan::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'metric_key' => 'calls', 'daily_plan' => $count * 10, 'effective_from' => '2026-01-01']);
        }
        Sanctum::actingAs($rop);
        $url = '/api/kpi/weekly?v=2&day=2026-05-01&user_id='.$agent->id;
        $rows = collect($this->getJson($url)->assertOk()->assertJsonPath('meta.pagination.total', 2)->json('data'))->keyBy('branch_group_id');
        foreach (['A' => 1, 'B' => 2] as $name => $count) {
            $row = $rows[$groups[$name]->id];
            $this->assertEquals($count, $row['metrics']['calls']['fact_value']);
            $this->assertEquals(round($count * 10 * 7 / 30, 4), $row['metrics']['calls']['target_value']);
            $this->assertSame($groups[$name]->id, $row['metrics']['calls']['plan_source'] === 'personal' ? $row['branch_group_id'] : null);
        }
        $this->getJson($url.'&branch_group_id='.$groups['A']->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.metrics.calls.fact_value', 1);
    }

    public function test_plan_endpoints_scope_the_plan_record_instead_of_current_employee_group(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $c = BranchGroup::create(['name' => 'C', 'branch_id' => $branch->id]);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '911000001', 'role_id' => $ropRole->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Transferred', 'phone' => '911000002', 'role_id' => $agentRole->id,
            'branch_id' => $branch->id, 'branch_group_id' => $c->id]);
        $rop->supervisedGroups()->attach($a->id);
        $plans = [];
        foreach (['a' => $a->id, 'c' => $c->id, 'null' => null] as $key => $group) {
            $plans[$key] = KpiPlan::create(['user_id' => $agent->id, 'role_slug' => 'agent',
                'branch_id' => $branch->id, 'branch_group_id' => $group, 'metric_key' => 'objects',
                'daily_plan' => $key === 'a' ? 5 : 99, 'weight' => 1, 'effective_from' => '2026-05-01']);
        }
        $branchPlan = KpiPlan::create(['user_id' => null, 'role_slug' => 'agent', 'branch_id' => $branch->id,
            'metric_key' => 'shows', 'daily_plan' => 999, 'weight' => 1]);
        Sanctum::actingAs($rop);
        $this->getJson('/api/kpi/plans/list')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plan_id', $plans['a']->id);
        $this->getJson('/api/kpi/plans/'.$plans['a']->id)->assertOk()->assertJsonPath('data.0.id', $plans['a']->id);
        foreach (['c', 'null'] as $key) $this->getJson('/api/kpi/plans/'.$plans[$key]->id)->assertNotFound();
        $this->getJson('/api/kpi/plans/common/'.$branchPlan->id)->assertNotFound();
        \DB::table('users')->where('id', $agent->id)->update(['branch_group_id' => $a->id]);
        $this->getJson('/api/kpi/plans?user_id='.$agent->id.'&date=2026-05-02')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plans['a']->id);
        $foreignBefore = KpiPlan::query()->whereIn('id', [$plans['c']->id, $plans['null']->id])->orderBy('id')->get()->toJson();
        $payload = ['effective_from' => '2026-05-01', 'effective_to' => '2026-05-31',
            'items' => [['metric_key' => 'objects', 'daily_plan' => 23, 'weight' => 1]]];
        $this->putJson('/api/kpi/plans/'.$agent->id, $payload)->assertConflict();
        $response = $this->putJson('/api/kpi/plans/'.$agent->id, $payload + ['conflict_strategy' => 'replace'])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_group_id', $a->id)
            ->assertJsonPath('data.0.daily_plan', 23);
        $newId = $response->json('data.0.id');
        $this->assertSame($foreignBefore, KpiPlan::query()->whereIn('id', [$plans['c']->id, $plans['null']->id])->orderBy('id')->get()->toJson());
        $this->assertDatabaseMissing('kpi_plans', ['id' => $plans['a']->id]);
        $rop->supervisedGroups()->detach();
        $this->getJson('/api/kpi/plans/list')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/kpi/plans/'.$newId)->assertNotFound();
    }

    public function test_rop_plan_pagination_counts_only_assigned_groups_and_limits_sql(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $c = BranchGroup::create(['name' => 'C', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000001', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($a->id);
        $ids = [];
        foreach ([$a, $c, $a, $c, $a] as $group) {
            $plan = \App\Models\KpiRopPlan::create(['role_slug' => 'agent', 'month' => '2001-01', 'branch_id' => $branch->id,
                'branch_group_id' => $group->id, 'items' => [], 'created_by' => $rop->id]);
            if ($group->id === $a->id) $ids[] = $plan->id;
        }
        Sanctum::actingAs($rop);
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) { $queries[] = strtolower($query->sql); });
        $this->getJson('/api/kpi/rop-plans?month=2001-01&per_page=2&page=1')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $ids[2])->assertJsonPath('data.1.id', $ids[1]);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'kpi_rop_plans') && str_contains($sql, 'limit 2')));
        $this->getJson('/api/kpi/rop-plans?month=2001-01&per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('meta.page', 2);
        $this->getJson('/api/kpi/rop-plans?month=2001-01&per_page=101')->assertUnprocessable();
        $rop->supervisedGroups()->detach();
        $this->getJson('/api/kpi/rop-plans?month=2001-01')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_rop_plan_patch_cannot_claim_foreign_source_and_copy_checks_both_groups(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000001', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($a->id);
        $items = [['metric_key' => 'calls', 'plan_value' => 10, 'weight' => 1]];
        $foreign = \App\Models\KpiRopPlan::create(['role_slug' => 'agent', 'month' => '2000-01', 'branch_id' => $branch->id, 'branch_group_id' => $b->id, 'items' => $items, 'created_by' => $rop->id]);
        Sanctum::actingAs($rop);
        $this->patchJson('/api/kpi/rop-plans/'.$foreign->id, ['branch_group_id' => $a->id, 'items' => $items])->assertForbidden();
        $this->assertEquals($b->id, $foreign->fresh()->branch_group_id);
        $this->postJson('/api/kpi/rop-plans/'.$foreign->id.'/copy', ['branch_group_id' => $a->id, 'month' => '2000-02'])->assertForbidden();
        $response = $this->postJson('/api/kpi/rop-plans', ['role' => 'agent', 'month' => '2000-01', 'items' => $items])->assertCreated()->assertJsonPath('branch_id', $branch->id)->assertJsonPath('branch_group_id', $a->id);
        $id = $response->json('id');
        $this->getJson('/api/kpi/rop-plans?month=2000-01')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/kpi/rop-plans/'.$id.'/copy', ['month' => '2000-02', 'branch_group_id' => $b->id])->assertForbidden();
        $this->postJson('/api/kpi/rop-plans/'.$id.'/copy', ['month' => '2000-02'])->assertCreated()->assertJsonPath('branch_group_id', $a->id);
        $rop->supervisedGroups()->attach($b->id);
        $this->patchJson('/api/kpi/rop-plans/'.$id, ['branch_group_id' => $b->id])->assertUnprocessable();
        $this->assertDatabaseCount('kpi_rop_plans', 3);
        $logs = \App\Models\CrmAuditLog::whereIn('event', ['kpi_rop_plan_created', 'kpi_rop_plan_copied'])->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertEquals($rop->id, $logs[0]->actor_id);
        $this->assertEquals($id, $logs[1]->context['source_id']);
        $this->assertEquals($a->id, $logs[1]->context['branch_group_id']);
        $this->patchJson('/api/kpi/rop-plans/'.$id, ['month' => '2000-03'])->assertOk();
        $audit = \App\Models\CrmAuditLog::where('event', 'kpi_rop_plan_updated')->firstOrFail();
        $this->assertSame('2000-01', $audit->old_values['month']);
        $this->assertSame('2000-03', $audit->new_values['month']);
        $failAudit = true;
        \App\Models\CrmAuditLog::creating(function ($log) use (&$failAudit) {
            if ($failAudit && str_starts_with($log->event, 'kpi_rop_plan_')) throw new \RuntimeException('Simulated plan audit failure');
        });
        try {
            $this->postJson('/api/kpi/rop-plans/'.$id.'/copy', ['month' => '2000-04'])->assertServerError();
            $this->patchJson('/api/kpi/rop-plans/'.$id, ['month' => '2000-05'])->assertServerError();
            $this->assertDatabaseCount('kpi_rop_plans', 3);
            $this->assertSame('2000-03', \App\Models\KpiRopPlan::findOrFail($id)->month);
        } finally { $failAudit = false; }

    }

    public function test_daily_v2_write_uses_report_group_and_rolls_back_mixed_scope_batch(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000011', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '912000012', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $b->id]);
        $rop->supervisedGroups()->attach($a->id);
        $own = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $a->id, 'role_slug' => 'agent', 'report_date' => '2000-01-01', 'calls_count' => 3, 'new_properties_count' => 9, 'submitted_at' => '2000-01-01 12:00:00']);
        $foreign = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $b->id, 'role_slug' => 'agent', 'report_date' => '2000-01-02', 'calls_count' => 99]);
        Sanctum::actingAs($rop);
        $row = ['employee_id' => $agent->id, 'date' => '2000-01-01', 'calls' => 7, 'role' => 'admin'];
        $this->postJson('/api/kpi/daily?v=2', ['rows' => [$row, array_replace($row, ['date' => '2000-01-02'])]])->assertNotFound();
        $this->assertEquals(3, $own->fresh()->calls_count);
        $this->assertEquals(99, $foreign->fresh()->calls_count);
        $this->postJson('/api/kpi/daily?v=2', ['rows' => [$row]])->assertCreated()->assertJsonPath('data.0.role', 'agent');
        $this->assertEquals(7, $own->fresh()->calls_count);
        $this->assertEquals(9, $own->fresh()->new_properties_count);
        $this->assertSame('2000-01-01 12:00:00', $own->fresh()->submitted_at->format('Y-m-d H:i:s'));
        $this->postJson('/api/kpi-period-locks', ['period_type' => 'month', 'period_key' => '2000-01'])->assertCreated();
        $this->postJson('/api/kpi/daily?v=2', ['rows' => [array_replace($row, ['calls' => 11])]])->assertUnprocessable();
        $this->assertEquals(7, $own->fresh()->calls_count);
        $rop->supervisedGroups()->sync([$b->id]);
        $this->postJson('/api/kpi/daily?v=2', ['rows' => [$row]])->assertNotFound();
        $this->postJson('/api/kpi/daily?v=2', ['rows' => [array_replace($row, ['date' => '2000-01-03'])]])->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 2);
    }

    public function test_kpi_period_lock_metadata_uses_assigned_group_and_iso_week(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000021', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($a->id);
        Sanctum::actingAs($rop);
        foreach (['day' => '2026-05-01', 'week' => '2026-W18'] as $type => $key) {
            $url = $type === 'day' ? '/api/kpi/daily?date=2026-05-01&v=2' : '/api/kpi/weekly?year=2026&week=18&v=2';
            foreach ([$b->id, null] as $groupId) {
                \App\Models\KpiPeriodLock::create(['period_type' => $type, 'period_key' => $key, 'branch_id' => $branch->id, 'branch_group_id' => $groupId, 'locked_by' => $rop->id, 'locked_at' => now()]);
            }
            $this->getJson($url)->assertOk()->assertJsonPath('meta.locked', false);
            $this->postJson('/api/kpi-period-locks', ['period_type' => $type, 'period_key' => $key])->assertCreated();
            $this->getJson($url)->assertOk()->assertJsonPath('meta.locked', true);
        }
    }

    public function test_adjustment_changes_only_snapshot_group_and_requires_its_period_lock(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000031', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '912000032', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $b->id]);
        $rop->supervisedGroups()->attach($a->id);
        $own = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $a->id, 'role_slug' => 'agent', 'report_date' => '2000-01-01', 'calls_count' => 3]);
        $foreign = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $b->id, 'role_slug' => 'agent', 'report_date' => '2000-01-02', 'calls_count' => 99]);
        \App\Models\KpiPeriodLock::create(['period_type' => 'month', 'period_key' => '2000-01', 'branch_id' => $branch->id, 'branch_group_id' => $b->id, 'locked_by' => $rop->id, 'locked_at' => now()]);
        Sanctum::actingAs($rop);
        $payload = ['period_type' => 'month', 'period_key' => '2000-01', 'entity_id' => $agent->id, 'field_name' => 'calls', 'new_value' => 7, 'reason' => 'Correct historical count'];
        $entities = $this->getJson('/api/kpi/adjustments/entities?period_type=month&period_key=2000-01')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['id' => $agent->id, 'name' => 'Agent'], $entities->json('data.0'));
        $this->getJson('/api/kpi/adjustments/entities?period_type=month&period_key=2000-02')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/kpi/adjustments', $payload)->assertUnprocessable();
        $this->assertEquals(3, $own->fresh()->calls_count);
        $this->postJson('/api/kpi-period-locks', ['period_type' => 'month', 'period_key' => '2000-01'])->assertCreated()->assertJsonPath('branch_group_id', $a->id);
        $extra = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $a->id, 'role_slug' => 'agent', 'report_date' => '2000-01-03', 'calls_count' => 5]);
        $beforeReports = \Illuminate\Support\Facades\DB::table('daily_reports')->orderBy('id')->get()->toJson();
        $failAudit = true;
        \App\Models\KpiAdjustmentLog::creating(function () use (&$failAudit) {
            if ($failAudit) throw new \RuntimeException('Simulated adjustment journal failure');
        });
        try {
            foreach (['set_first_day', 'distribute_evenly'] as $mode) {
                $this->postJson('/api/kpi/adjustments', $payload + ['distribution_mode' => $mode])->assertServerError();
                $this->assertSame($beforeReports, \Illuminate\Support\Facades\DB::table('daily_reports')->orderBy('id')->get()->toJson());
                $this->assertDatabaseCount('kpi_adjustment_logs', 0);
            }
        } finally {
            $failAudit = false;
            $extra->delete();
        }
        $this->postJson('/api/kpi/adjustments', $payload)->assertCreated()->assertJsonPath('old_value', 3)->assertJsonPath('new_value', 7)->assertJsonPath('branch_group_id', $a->id);
        $this->assertEquals(7, $own->fresh()->calls_count);
        $this->assertEquals(99, $foreign->fresh()->calls_count);
        $this->getJson('/api/kpi/adjustments')->assertOk()->assertJsonCount(1, 'data');
        $history = $this->getJson('/api/kpi/adjustments?entity_id='.$agent->id)->assertOk();
        $this->assertSame([['id' => $agent->id, 'name' => 'Agent']], $history->json('entities'));
        $rop->supervisedGroups()->attach($b->id);
        $this->getJson('/api/kpi/adjustments?branch_group_id='.$b->id)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0)->assertJsonCount(0, 'entities');
        $this->getJson('/api/kpi/adjustments?branch_group_id='.$a->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1);
        $rop->supervisedGroups()->sync([$b->id]);
        $this->getJson('/api/kpi/adjustments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_kpi_diagnostics_hide_foreign_and_unclassified_rows_from_rop(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000041', 'role_id' => $ropRole->id, 'branch_id' => $branch->id]);
        $admin = User::create(['name' => 'Admin', 'phone' => '912000042', 'role_id' => $adminRole->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($a->id);
        foreach ([$a->id, $b->id, null] as $groupId) {
            \App\Models\KpiQualityIssue::create(['branch_group_id' => $groupId, 'title' => 'Issue', 'status' => 'open', 'severity' => 'high', 'detected_at' => '2026-05-01 12:00:00']);
            \App\Models\KpiAcceptanceRun::create(['branch_group_id' => $groupId, 'run_type' => 'daily', 'status' => 'success']);
        }
        Schema::create('properties', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('branch_group_id'); $table->softDeletes(); });
        $propertyId = \Illuminate\Support\Facades\DB::table('properties')->insertGetId(['branch_id' => $branch->id, 'branch_group_id' => $b->id]);
        $issue = \App\Models\KpiQualityIssue::where('branch_group_id', $a->id)->firstOrFail();
        $issue->update(['title' => 'Private property snapshot', 'details' => ['property_id' => $propertyId, 'owner_phone' => 'private-phone', 'metric_key' => 'sales']]);
        Sanctum::actingAs($rop);
        $projected = $this->getJson('/api/kpi/quality/issues')->assertOk();
        $projected->assertJsonPath('data.0.title', 'KPI quality issue')->assertJsonMissing(['owner_phone' => 'private-phone']);
        $this->assertArrayNotHasKey('property_id', $projected->json('data.0.details'));
        $this->assertSame('Private property snapshot', $issue->fresh()->title);
        $run = \App\Models\KpiAcceptanceRun::where('branch_group_id', $a->id)->firstOrFail();
        $run->update(['details' => ['nested' => ['property_id' => $propertyId, 'owner_phone' => 'private-phone']]]);
        $alert = KpiEarlyRiskAlert::create(['branch_group_id' => $a->id, 'user_id' => $rop->id,
            'alert_date' => '2026-05-01', 'status' => 'open', 'message' => 'private-phone',
            'meta' => ['nested' => ['property_id' => $propertyId, 'owner_phone' => 'private-phone']]]);
        $this->getJson('/api/kpi/acceptance-runs')->assertOk()->assertJsonPath('data.0.details_unavailable', true)->assertJsonMissing(['owner_phone' => 'private-phone']);
        $this->getJson('/api/kpi/early-risk-alerts')->assertOk()->assertJsonPath('data.0.message', 'Связанная запись недоступна')->assertJsonMissing(['owner_phone' => 'private-phone']);
        $this->assertSame('private-phone', $alert->fresh()->message);
        $this->assertSame($propertyId, $run->fresh()->details['nested']['property_id']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/kpi/quality/issues')->assertOk()->assertJsonFragment(['owner_phone' => 'private-phone']);
        foreach ([[$rop, 1], [$admin, 3]] as [$actor, $count]) {
            Sanctum::actingAs($actor);
            foreach (['/api/kpi/quality/issues', '/api/kpi/ops/quality/issues', '/api/kpi/acceptance-runs', '/api/kpi/ops/acceptance-runs'] as $path) {
                $this->getJson($path)->assertOk()->assertJsonCount($count, 'data');
            }
        }
        Sanctum::actingAs($rop);
        foreach (['/api/kpi/daily?date=2026-05-01&v=2', '/api/kpi/weekly?year=2026&week=18&v=2'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonPath('meta.quality.issues_count', 1);
        }
        $rop->supervisedGroups()->attach($b->id);
        $this->getJson('/api/kpi/acceptance-runs?branch_group_id='.$b->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_group_id', $b->id);
        foreach (['/api/kpi/daily?date=2026-05-01&v=2', '/api/kpi/weekly?year=2026&week=18&v=2'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonPath('meta.quality.issues_count', 2);
            $this->getJson($path.'&branch_group_id='.$b->id)->assertOk()->assertJsonPath('meta.quality.issues_count', 1);
        }
        $this->getJson('/api/kpi/quality/issues?branch_group_id='.$b->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_group_id', $b->id);
        $rop->supervisedGroups()->detach();
        Sanctum::actingAs($rop);
        $this->getJson('/api/kpi/quality/issues')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/kpi/acceptance-runs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_preparation_reports_alert_history_without_guessing_its_group(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000051', 'role_id' => $role->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $alert = KpiEarlyRiskAlert::create(['user_id' => $rop->id, 'alert_date' => '2000-01-01', 'status' => 'open', 'message' => 'Unclassified']);
        foreach ([[], ['--apply' => true]] as $options) {
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('rop-groups:prepare', $options));
            $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $report['unclassified_history']['kpi_early_risk_alerts']);
            $this->assertContains($rop->id, $report['rops_without_groups']);
            $this->assertFalse($report['rop_assignments'][0]['valid']);
            $this->assertNull($alert->fresh()->branch_group_id);
            $this->assertSame(0, $rop->supervisedGroups()->count());
        }
        $other = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        Schema::create('clients', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_group_id'); $table->unsignedBigInteger('responsible_agent_id')->nullable(); });
        Schema::create('leads', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_group_id'); $table->unsignedBigInteger('responsible_agent_id')->nullable(); $table->unsignedBigInteger('client_id'); });
        $clientId = \Illuminate\Support\Facades\DB::table('clients')->insertGetId(['branch_group_id' => $other->id]);
        $leadId = \Illuminate\Support\Facades\DB::table('leads')->insertGetId(['branch_group_id' => $group->id, 'client_id' => $clientId]);
        $rop->supervisedGroups()->attach($group->id);
        \Illuminate\Support\Facades\Artisan::call('rop-groups:prepare');
        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['rop_assignments'][0]['valid']);
        $this->assertCount(1, $report['cross_group_links']);
        $this->assertEquals($leadId, $report['cross_group_links'][0]['id']);
        $this->assertEquals($other->id, $report['cross_group_links'][0]['parent_group_id']);
        $this->assertEquals($group->id, $report['rop_assignments'][0]['branch_group_id']);
    }

    public function test_early_risk_alerts_use_snapshot_group_for_reads_and_status_changes(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000061', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '912000062', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $a->id]);
        $rop->supervisedGroups()->attach($a->id);
        $own = KpiEarlyRiskAlert::create(['user_id' => $agent->id, 'alert_date' => now('Asia/Dushanbe')->toDateString(), 'status' => 'open', 'message' => 'A']);
        $this->assertEquals($a->id, $own->branch_group_id);
        User::whereKey($agent->id)->update(['branch_group_id' => $b->id]);
        $foreign = KpiEarlyRiskAlert::create(['user_id' => $agent->id, 'alert_date' => now('Asia/Dushanbe')->toDateString(), 'status' => 'open', 'message' => 'B']);
        $unknown = KpiEarlyRiskAlert::create(['user_id' => $agent->id, 'alert_date' => '2000-01-01', 'status' => 'open', 'message' => 'Unknown']);
        $this->assertNull($unknown->branch_group_id);
        Sanctum::actingAs($rop);
        $this->getJson('/api/kpi/early-risk-alerts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->patchJson('/api/kpi/early-risk-alerts/status', ['alert_id' => $own->id, 'status' => 'closed'])->assertOk();
        foreach ([$foreign, $unknown] as $alert) {
            $this->patchJson('/api/kpi/early-risk-alerts/status', ['alert_id' => $alert->id, 'status' => 'closed'])->assertNotFound();
            $this->assertSame('open', $alert->fresh()->status);
        }
        $rop->supervisedGroups()->attach($b->id);
        $this->getJson('/api/kpi/early-risk-alerts?branch_group_id='.$a->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $rop->supervisedGroups()->detach();
        $this->getJson('/api/kpi/early-risk-alerts')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/kpi/early-risk-alerts/status', ['alert_id' => $own->id, 'status' => 'escalated'])->assertNotFound();
        $this->assertSame('closed', $own->fresh()->status);
    }

    public function test_apply_common_plan_uses_selected_group_without_branch_fallback(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000071', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '912000072', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $a->id]);
        $rop->supervisedGroups()->attach([$a->id, $b->id]);
        Sanctum::actingAs($rop);
        foreach ([$a->id => 7, $b->id => 99] as $groupId => $value) {
            KpiPlan::create(['role_slug' => 'agent', 'branch_id' => $branch->id, 'branch_group_id' => $groupId,
                'metric_key' => 'objects', 'daily_plan' => $value, 'weight' => 1, 'effective_from' => '2026-05-01']);
        }
        $payload = ['role' => 'agent', 'branch_group_id' => $a->id, 'effective_from' => '2026-05-01', 'user_ids' => [$agent->id]];
        $this->postJson('/api/kpi/plans/common/apply-to-users', $payload)->assertOk()->assertJsonPath('success_count', 1);
        $this->assertDatabaseHas('kpi_plans', ['user_id' => $agent->id, 'branch_group_id' => $a->id, 'daily_plan' => 7]);
        $before = KpiPlan::orderBy('id')->get()->toJson();
        $rop->supervisedGroups()->detach($a->id);
        try {
            app(\App\Services\KpiModuleService::class)->applyCommonPlanToUsers($rop, $payload);
            $this->fail('Revoked group must not be copied');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame($before, KpiPlan::orderBy('id')->get()->toJson());
    }

    public function test_bulk_plan_scope_rejects_an_employee_in_another_assigned_group(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000081', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '912000082', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $b->id]);
        $rop->supervisedGroups()->attach([$a->id, $b->id]);
        Sanctum::actingAs($rop);
        $payload = ['effective_from' => '2026-05-01', 'items' => [['metric_key' => 'objects', 'daily_plan' => 5, 'weight' => 1]],
            'scope' => ['branch_group_id' => $a->id, 'roles' => ['agent']]];
        $service = app(\App\Services\KpiModuleService::class);
        foreach ([['branch_group_id' => $a->id, 'roles' => ['agent']], ['branch_group_id' => $b->id, 'roles' => ['mop']]] as $scope) {
            try {
                $service->upsertUserPlans($rop, $agent->id, array_replace($payload, ['scope' => $scope]));
                $this->fail('Target must match selected group and role under lock');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
                $this->assertSame(403, $error->getResponse()->getStatusCode());
            }
            $this->assertDatabaseCount('kpi_plans', 0);
        }
        $service->upsertUserPlans($rop, $agent->id, array_replace($payload, ['scope' => ['branch_group_id' => $b->id, 'roles' => ['agent']]]));
        $this->assertDatabaseHas('kpi_plans', ['user_id' => $agent->id, 'branch_group_id' => $b->id, 'daily_plan' => 5]);
    }

    public function test_common_plan_write_rechecks_actor_and_rolls_back_replacement_on_failure(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['name' => 'A', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000091', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($group->id);
        Sanctum::actingAs($rop);
        $service = app(\App\Services\KpiModuleService::class);
        $payload = ['role' => 'agent', 'branch_group_id' => $group->id, 'effective_from' => '2026-05-01',
            'items' => [['metric_key' => 'objects', 'daily_plan' => 5, 'weight' => 1]]];
        $service->upsertCommonPlans($rop, $payload);
        $this->assertDatabaseHas('kpi_plans', ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'daily_plan' => 5]);
        $before = KpiPlan::orderBy('id')->get()->toJson();
        $armed = true;
        KpiPlan::creating(function () use (&$armed) {
            if ($armed) throw new \RuntimeException('Simulated persistence failure');
        });
        try {
            $service->upsertCommonPlans($rop, $payload);
            $this->fail('Persistence failure must propagate');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated persistence failure', $error->getMessage());
        } finally { $armed = false; }
        $this->assertSame($before, KpiPlan::orderBy('id')->get()->toJson());
        $rop->load('role');
        User::whereKey($rop->id)->update(['role_id' => $agentRole->id]);
        try {
            $service->upsertCommonPlans($rop, $payload);
            $this->fail('Stale ROP role must not authorize writing');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
            $this->assertSame(403, $error->getResponse()->getStatusCode());
        }
        $this->assertSame($before, KpiPlan::orderBy('id')->get()->toJson());
    }

    public function test_legacy_role_plans_require_a_group_and_never_overwrite_other_plan_contexts(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $groups = collect(['A', 'B', 'C'])->mapWithKeys(fn ($name) => [$name => BranchGroup::create(['name' => $name, 'branch_id' => $branch->id])]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'ROP', 'phone' => '912000001', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach([$groups['A']->id, $groups['B']->id]);
        $base = ['role_slug' => 'agent', 'branch_id' => $branch->id, 'metric_key' => 'objects', 'daily_plan' => 5, 'weight' => 1];
        $own = KpiPlan::create($base + ['branch_group_id' => $groups['A']->id]);
        $foreign = KpiPlan::create(array_replace($base, ['branch_group_id' => $groups['C']->id, 'daily_plan' => 999]));
        $dated = KpiPlan::create(array_replace($base, ['branch_group_id' => $groups['A']->id, 'daily_plan' => 888, 'effective_from' => '2026-05-01']));
        $before = KpiPlan::whereIn('id', [$foreign->id, $dated->id])->orderBy('id')->get()->toJson();
        Sanctum::actingAs($rop);
        $this->getJson('/api/kpi-plans?role=agent')->assertUnprocessable();
        $this->getJson('/api/kpi-plans?role=agent&branch_group_id='.$groups['C']->id)->assertForbidden();
        $response = $this->getJson('/api/kpi-plans?role=agent&branch_group_id='.$groups['A']->id)->assertOk();
        $this->assertEquals(5, collect($response->json('data'))->firstWhere('metric_key', 'objects')['daily_plan']);
        $payload = ['role' => 'agent', 'items' => [['metric_key' => 'objects', 'daily_plan' => 23, 'weight' => 1]]];
        $this->patchJson('/api/kpi-plans', $payload)->assertUnprocessable();
        $this->patchJson('/api/kpi-plans', $payload + ['branch_group_id' => $groups['C']->id])->assertForbidden();
        $this->patchJson('/api/kpi-plans', $payload + ['branch_group_id' => $groups['A']->id])->assertOk();
        $this->assertEquals(23, $own->fresh()->daily_plan);
        $audit = \App\Models\CrmAuditLog::where('event', 'kpi_role_plan_upserted')->where('auditable_id', $own->id)->firstOrFail();
        $this->assertSame((int) $rop->id, (int) $audit->actor_id);
        $this->assertEquals(5, $audit->old_values['daily_plan']);
        $this->assertEquals(23, $audit->new_values['daily_plan']);
        $this->assertEquals($groups['A']->id, $audit->context['branch_group_id']);

        $this->assertSame($before, KpiPlan::whereIn('id', [$foreign->id, $dated->id])->orderBy('id')->get()->toJson());
    }

    public function test_kpi_module_endpoints_work_with_fallbacks(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000001', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000002', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 4, 'ad_count' => 2]);
        $type = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        CrmTask::create(['task_type_id' => $type->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'Call', 'status' => 'done']);
        KpiEarlyRiskAlert::create(['user_id' => $agent->id, 'alert_date' => '2026-05-04', 'status' => 'acknowledged', 'message' => 'risk']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi-plans')->assertOk()->assertJsonStructure(['data']);
        $this->patchJson('/api/kpi-plans', ['role' => 'mop', 'items' => [['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.2, 'comment' => 'x']]])->assertOk();
        $this->getJson('/api/kpi/daily?date=2026-05-04')->assertOk();
        $this->getJson('/api/kpi/daily?date=2026-05-04&v=2')->assertOk()->assertJsonStructure([
            'data',
            'meta' => ['period_type', 'quality' => ['duplicate_check_passed', 'completeness_pct', 'source_error']],
        ])->assertJsonPath('meta.period_type', 'day')
            ->assertJsonPath('meta.version', '2');
        $this->getJson('/api/kpi/weekly?year=2026&week=19')->assertOk();
        $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2')->assertOk()->assertJsonStructure([
            'data',
            'meta' => ['period_type'],
        ])->assertJsonPath('meta.period_type', 'week');
        $this->getJson('/api/kpi/monthly?year=2026&month=5')->assertOk();
        $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2')->assertOk();
        $this->getJson('/api/kpi/metric-mapping')->assertOk()->assertJsonStructure([
            'data' => ['metric_keys', 'mapping'],
        ]);
        $this->getJson('/api/kpi/dashboard?date=2026-05-04')->assertOk();
        $this->getJson('/api/kpi/dashboard?date=2026-05-04&role=agent')->assertOk();
        $this->getJson('/api/kpi/dashboard/debug?date=2026-05-04&role=agent')->assertOk()->assertJsonStructure([
            'data' => ['summary', 'ranking', 'applied_filters', 'timezone', 'period_bounds'],
        ]);

        $this->getJson('/api/kpi/ops/integrations/status')->assertOk();
        $this->patchJson('/api/kpi/ops/telegram/config', ['daily_enabled' => true, 'daily_time' => '08:30'])->assertOk();
        $this->getJson('/api/kpi/ops/telegram/config')->assertOk();
        $this->getJson('/api/kpi/ops/quality/issues')->assertOk();
        $this->getJson('/api/kpi/ops/early-risk-alerts?date=2026-05-04')->assertOk();
        $this->patchJson('/api/kpi/ops/early-risk-alerts/status', ['alert_id' => 1, 'status' => 'closed'])->assertOk()->assertJson(['success' => true]);

        $this->getJson('/api/kpi/ops/period-contract?period_type=day&date_from=2026-05-04&date_to=2026-05-04')->assertOk();
        $this->getJson('/api/kpi/ops/acceptance-runs')->assertOk();

        $this->getJson('/api/crm/tasks/kpi-daily-summary?date=2026-05-04')->assertOk();
        $this->getJson('/api/crm/tasks/kpi-weekly-summary?year=2026&week=19')->assertOk();
    }

    public function test_post_kpi_daily_v2_upserts_rows_and_get_v2_returns_saved_values(): void
    {
        $this->useMigratedDailyReportsTable();
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000101', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000102', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        // Two real sales; other dates, agents and non-sale statuses do not count.
        foreach ([
            [],
            ['sold_at' => '2026-05-05 13:00:00'],
            ['sold_at' => '2026-05-06 12:00:00'],
            ['sale_user_id' => $admin->id],
            ['moderation_status' => 'approved'],
        ] as $overrides) {
            \DB::table('properties')->insert(array_merge([
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'sale_user_id' => $agent->id,
                'moderation_status' => 'sold',
                'sold_at' => '2026-05-05 12:00:00',
                'created_at' => '2026-05-04 10:00:00',
                'updated_at' => '2026-05-04 10:00:00',
            ], $overrides));
        }

        Sanctum::actingAs($admin);

        $payload = [
            'rows' => [[
                'date' => '2026-05-05',
                'role' => 'agent',
                'employee_id' => $agent->id,
                'employee_name' => $agent->name,
                'group_name' => 'G1',
                'advertisement' => 1,
                'call' => 2,
                'kabul' => 3,
                'show' => 4,
                'lead' => 5,
                'deposit' => 6,
                'deal' => 7,
                'comment' => 'ok',
            ]],
        ];

        $this->postJson('/api/kpi/daily?v=2', $payload, ['X-KPI-Version' => '2'])
            ->assertCreated()
            ->assertJsonPath('data.0.employee_id', $agent->id)
            ->assertJsonPath('data.0.objects', 5)
            ->assertJsonPath('data.0.shows', 4)
            ->assertJsonPath('data.0.ads', 1)
            ->assertJsonPath('data.0.calls', 2)
            ->assertJsonPath('data.0.sales', 7);

        $this->assertDatabaseHas('daily_reports', [
            'user_id' => $agent->id,
            'calls_count' => 2,
            'new_properties_count' => 5,
            'deposits_count' => 6,
            'deals_count' => 7,
        ]);

        $response = $this->getJson('/api/kpi/daily?date=2026-05-05&v=2&agent_id='.$agent->id)
            ->assertOk();

        $metrics = $response->json('data.0.metrics');
        if ($metrics === null) {
            $this->fail('Unexpected response: '.json_encode($response->json()));
        }
        $this->assertSame(0, (int) $metrics['objects']['final_value']);
        $this->assertSame(0, (int) $metrics['shows']['final_value']);
        $this->assertSame(1, (int) $metrics['ads']['final_value']);
        $this->assertSame(2, (int) $metrics['calls']['final_value']);
        $this->assertSame('system', $metrics['sales']['source']);
        $this->assertSame(2, (int) $metrics['sales']['fact_value']);
        $this->assertSame(0, (int) $metrics['sales']['manual_value']);
        $this->assertSame(2, (int) $metrics['sales']['final_value']);

        $response->assertJsonPath('data.0.objects_raw', 0)
            ->assertJsonPath('data.0.shows_raw', 0)
            ->assertJsonPath('data.0.ads_raw', 1)
            ->assertJsonPath('data.0.calls_raw', 2)
            ->assertJsonPath('data.0.sales_raw', 2)
            ->assertJsonPath('data.0.objects_display', '0.00')
            ->assertJsonPath('data.0.sales_display', '2.00');

        $payload['rows'][0]['call'] = 9;
        $payload['rows'][0]['deal'] = 99;
        $this->postJson('/api/kpi/daily?v=2', $payload)->assertCreated();
        $this->assertSame(1, DailyReport::query()->count(), DailyReport::query()->get()->toJson());
        $this->assertDatabaseHas('daily_reports', ['user_id' => $agent->id, 'calls_count' => 9, 'deals_count' => 99]);
        $this->getJson('/api/kpi/daily?date=2026-05-05&v=2&agent_id='.$agent->id)
            ->assertOk()
            ->assertJsonPath('data.0.metrics.calls.final_value', 9)
            ->assertJsonPath('data.0.metrics.sales.final_value', 2)
            ->assertJsonPath('data.0.metrics.sales.manual_value', 0);
    }

    public static function storedReportDates(): array
    {
        return ['date' => ['2026-05-05'], 'cast midnight' => ['2026-05-05 00:00:00']];
    }

    #[DataProvider('storedReportDates')]
    public function test_daily_v2_retry_preserves_other_users_and_dates(string $storedDate): void
    {
        $this->useMigratedDailyReportsTable();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000111', 'role_id' => $adminRole->id, 'branch_id' => $branch->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000112', 'role_id' => $agentRole->id, 'branch_id' => $branch->id]);
        $reportId = \DB::table('daily_reports')->insertGetId([
            'user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => $storedDate, 'calls_count' => 1,
        ]);
        \DB::table('daily_reports')->insert([
            ['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-06', 'calls_count' => 80],
            ['user_id' => $admin->id, 'role_slug' => 'admin', 'report_date' => $storedDate, 'calls_count' => 90],
        ]);
        $othersBefore = \DB::table('daily_reports')->where('id', '!=', $reportId)->orderBy('id')->get()->toJson();

        Sanctum::actingAs($admin);
        foreach ([41, 42, 42] as $calls) {
            $this->postJson('/api/kpi/daily?v=2', ['rows' => [[
                'date' => '2026-05-05', 'role' => 'agent', 'employee_id' => $agent->id, 'calls' => $calls,
            ]]])->assertCreated()->assertJsonPath('data.0.calls', $calls);
            $this->assertDatabaseCount('daily_reports', 3);
            $this->assertDatabaseHas('daily_reports', ['id' => $reportId, 'user_id' => $agent->id, 'calls_count' => $calls]);
            $this->assertSame('2026-05-05', DailyReport::findOrFail($reportId)->report_date->toDateString());
            $this->assertSame($othersBefore, \DB::table('daily_reports')->where('id', '!=', $reportId)->orderBy('id')->get()->toJson());
        }
    }

    public function test_weekly_v2_returns_all_scope_employees_with_daily_report_stats_and_zero_kpi_rows(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000701', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent 1', 'phone' => '900000702', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern 1', 'phone' => '900000703', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-04',
            'calls_count' => 5,
            'submitted_at' => now(),
        ]);
        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-05',
            'calls_count' => 3,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'week');

        $rows = collect((array) $response->json('data'))->keyBy('employee_id');

        $this->assertTrue($rows->has($agent->id), 'Agent row must exist in weekly scope.');
        $this->assertTrue($rows->has($intern->id), 'Intern row must exist in weekly scope even without reports.');

        $agentRow = (array) $rows->get($agent->id);
        $internRow = (array) $rows->get($intern->id);

        $this->assertSame(2, (int) ($agentRow['submitted_days_count'] ?? -1));
        $this->assertSame(7, (int) ($agentRow['required_days_count'] ?? 0));
        $this->assertIsArray($agentRow['missing_report_dates'] ?? null);
        $this->assertFalse((bool) ($agentRow['sunday_submitted'] ?? true));

        $this->assertSame(0.0, (float) ($internRow['objects'] ?? -1));
        $this->assertSame(0.0, (float) ($internRow['shows'] ?? -1));
        $this->assertSame(0.0, (float) ($internRow['ads'] ?? -1));
        $this->assertSame(0.0, (float) ($internRow['calls'] ?? -1));
        $this->assertSame(0.0, (float) ($internRow['sales'] ?? -1));
        $this->assertSame(0, (int) ($internRow['submitted_days_count'] ?? -1));
        $this->assertSame(7, (int) ($internRow['required_days_count'] ?? 0));
    }

    public function test_kpi_period_endpoints_exclude_inactive_by_default(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900010001', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);
        $activeAgent = User::create(['name' => 'Active Agent', 'phone' => '900010002', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);
        $inactiveAgent = User::create(['name' => 'Inactive Agent', 'phone' => '900010003', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'inactive']);

        DailyReport::create(['user_id' => $activeAgent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 1, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $inactiveAgent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 1, 'submitted_at' => now()]);

        Sanctum::actingAs($admin);

        $dailyRows = collect((array) $this->getJson('/api/kpi/daily?date=2026-05-04&v=2')->assertOk()->json('data'));
        $weeklyRows = collect((array) $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2')->assertOk()->json('data'));
        $monthlyRows = collect((array) $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2')->assertOk()->json('data'));

        $this->assertTrue($dailyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $activeAgent->id));
        $this->assertFalse($dailyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));

        $this->assertTrue($weeklyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $activeAgent->id));
        $this->assertFalse($weeklyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));

        $this->assertTrue($monthlyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $activeAgent->id));
        $this->assertFalse($monthlyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));
    }

    public function test_kpi_period_endpoints_allow_include_inactive_for_privileged_role(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900010101', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);
        $activeAgent = User::create(['name' => 'Active Agent', 'phone' => '900010102', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);
        $inactiveAgent = User::create(['name' => 'Inactive Agent', 'phone' => '900010103', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'inactive']);

        DailyReport::create(['user_id' => $activeAgent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 1, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $inactiveAgent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'calls_count' => 1, 'submitted_at' => now()]);

        Sanctum::actingAs($admin);

        $dailyRows = collect((array) $this->getJson('/api/kpi/daily?date=2026-05-04&v=2&include_inactive=1')->assertOk()->json('data'));
        $weeklyRows = collect((array) $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2&include_inactive=1')->assertOk()->json('data'));
        $monthlyRows = collect((array) $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&include_inactive=1')->assertOk()->json('data'));

        $this->assertTrue($dailyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));
        $this->assertTrue($weeklyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));
        $this->assertTrue($monthlyRows->contains(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $inactiveAgent->id));
    }

    public function test_kpi_period_endpoints_forbid_include_inactive_for_non_privileged_role(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $mop = User::create(['name' => 'MOP', 'phone' => '900010201', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);

        Sanctum::actingAs($mop);

        $this->getJson('/api/kpi/daily?date=2026-05-04&v=2&include_inactive=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_INCLUDE_INACTIVE');

        $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2&include_inactive=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_INCLUDE_INACTIVE');

        $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&include_inactive=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_INCLUDE_INACTIVE');
    }

    public function test_kpi_day_week_month_include_clients_count_in_rows(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900010301', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);
        $agent = User::create(['name' => 'Agent', 'phone' => '900010302', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'active']);

        // The saved snapshot is intentionally stale: live CRM data added after
        // submission must still be reflected in daily/weekly/monthly responses.
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-04', 'new_clients_count' => 1, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-05', 'new_clients_count' => 1, 'submitted_at' => now()]);

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('responsible_agent_id')->nullable();
            $t->timestamps();
        });
        foreach (['2026-05-04 08:00:00', '2026-05-04 09:00:00', '2026-05-04 10:00:00', '2026-05-05 08:00:00', '2026-05-05 09:00:00'] as $createdAt) {
            \DB::table('clients')->insert([
                'created_by' => $agent->id,
                'responsible_agent_id' => $agent->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        Sanctum::actingAs($admin);

        $dailyRow = collect((array) $this->getJson('/api/kpi/daily?date=2026-05-04&v=2')->assertOk()->json('data'))
            ->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($dailyRow);
        $this->assertSame(3.0, (float) ($dailyRow['clients'] ?? -1));

        $weeklyRow = collect((array) $this->getJson('/api/kpi/weekly?year=2026&week=19&v=2')->assertOk()->json('data'))
            ->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($weeklyRow);
        $this->assertSame(5.0, (float) ($weeklyRow['clients'] ?? -1));

        $monthlyRow = collect((array) $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2')->assertOk()->json('data'))
            ->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($monthlyRow);
        $this->assertSame(5.0, (float) ($monthlyRow['clients'] ?? -1));
    }

    public function test_effective_plan_returns_common_when_personal_absent(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000201', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000202', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 30,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/plans?user_id='.$agent->id.'&date=2026-05-06')
            ->assertOk()
            ->assertJsonPath('source', 'common')
            ->assertJsonPath('data.0.metric', 'calls')
            ->assertJsonPath('data.0.source', 'common');
    }

    public function test_my_daily_progress_strict_contract_uses_canonical_metrics(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001001', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-16',
            'ad_count' => 8,
            'calls_count' => 24,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/kpi/daily/my-progress?date=2026-05-16')
            ->assertOk()
            ->assertJsonPath('date', '2026-05-16')
            ->assertJsonPath('timezone', 'Asia/Dushanbe')
            ->assertJsonPath('submitted_daily_report', true)
            ->assertJsonStructure([
                'overall_progress_pct',
                'status',
                'metrics' => ['objects', 'shows', 'ads', 'calls', 'sales'],
            ]);
    }

    public function test_weekly_and_monthly_strict_return_empty_rows_with_meta_period_key(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001101', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/kpi/weekly?day=2026-05-16')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'week')
            ->assertJsonPath('meta.period_key', '2026-W20')
            ->assertJsonPath('rows', []);

        $this->getJson('/api/kpi/monthly?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'month')
            ->assertJsonPath('meta.period_key', '2026-05')
            ->assertJsonPath('rows', []);
    }

    public function test_common_read_includes_unified_source_and_meta_source(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000250', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 20,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $this->getJson('/api/kpi/plans/common?role=agent&date=2026-05-06&branch_id='.$branch->id.'&branch_group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('source', 'common')
            ->assertJsonPath('meta.source', 'common')
            ->assertJsonStructure(['plans' => [['updated_at']]]);
    }

    public function test_kpi_plan_list_and_details_endpoints_return_grouped_contract(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000251', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000252', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'metric_key' => 'calls',
            'daily_plan' => 10,
            'weight' => 0.5,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);
        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'metric_key' => 'sales',
            'daily_plan' => 1,
            'weight' => 0.5,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);

        $list = $this->getJson('/api/kpi/plans/list?type=personal&user_id='.$agent->id.'&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.type', 'personal')
            ->assertJsonPath('data.0.items_count', 2);

        $planId = (int) $list->json('data.0.plan_id');
        $this->getJson('/api/kpi/plans/'.$planId)
            ->assertOk()
            ->assertJsonPath('meta.exists', true)
            ->assertJsonPath('meta.source', 'personal')
            ->assertJsonCount(2, 'plans');
    }

    public function test_upsert_common_plan_validates_weights_and_writes_audit(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000301', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $payload = [
            'role' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'items' => [
                ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
                ['metric' => 'shows', 'daily_plan' => 2, 'weight' => 0.2],
                ['metric' => 'ads', 'daily_plan' => 10, 'weight' => 0.2],
                ['metric' => 'calls', 'daily_plan' => 30, 'weight' => 0.2],
                ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
            ],
        ];

        $this->putJson('/api/kpi/plans/common', $payload)
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->assertDatabaseHas('kpi_plans', [
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
        ]);

        $this->assertDatabaseHas('crm_audit_logs', [
            'event' => 'kpi_common_plan_upserted',
            'actor_id' => $admin->id,
        ]);

        $payload['items'][0]['weight'] = 0.1;
        $this->putJson('/api/kpi/plans/common', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED');
    }

    public function test_mop_can_save_personal_plan_for_agent_and_intern_in_own_group(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $mop = User::create(['name' => 'MOP', 'phone' => '900000401', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000402', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern', 'phone' => '900000403', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($mop);

        $payload = [
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.5],
                ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.5],
            ],
        ];

        $this->patchJson('/api/kpi/plans/'.$agent->id, $payload)->assertOk();
        $this->patchJson('/api/kpi/plans/'.$intern->id, $payload)->assertOk();
    }

    public function test_mop_forbidden_outside_group(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group1 = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $group2 = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);

        $mop = User::create(['name' => 'MOP1', 'phone' => '900000411', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group1->id]);
        $foreignAgent = User::create(['name' => 'Agent2', 'phone' => '900000413', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group2->id]);

        Sanctum::actingAs($mop);

        $payload = [
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.5],
                ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.5],
            ],
        ];

        $this->patchJson('/api/kpi/plans/'.$foreignAgent->id, $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');
    }

    public function test_mop_can_save_personal_plan_for_mop_in_own_group(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $mop = User::create(['name' => 'MOP1', 'phone' => '900000414', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $otherMop = User::create(['name' => 'MOP2', 'phone' => '900000415', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($mop);

        $payload = [
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.5],
                ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.5],
            ],
        ];

        $this->patchJson('/api/kpi/plans/'.$otherMop->id, $payload)->assertOk();
    }

    public function test_rop_can_save_agent_and_mop_in_assigned_group_but_not_intern(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $rop = User::create(['name' => 'ROP', 'phone' => '900000421', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $mop = User::create(['name' => 'MOP', 'phone' => '900000422', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000423', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern', 'phone' => '900000424', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        $rop->supervisedGroups()->sync([$rop->branch_group_id]);
        Sanctum::actingAs($rop);
        $payload = [
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.5],
                ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.5],
            ],
        ];

        $this->patchJson('/api/kpi/plans/'.$agent->id, $payload)->assertOk();
        $this->patchJson('/api/kpi/plans/'.$mop->id, $payload)->assertOk();
        $this->patchJson('/api/kpi/plans/'.$intern->id, $payload)->assertForbidden();
    }

    public function test_effective_plan_prefers_personal_then_common_for_mop_and_agent_scope_fallback(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000431', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $mop = User::create(['name' => 'MOP', 'phone' => '900000432', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000433', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        KpiPlan::query()->create([
            'role_slug' => 'mop',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 50,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $this->getJson('/api/kpi/plans?user_id='.$mop->id.'&date=2026-05-06')
            ->assertOk()
            ->assertJsonPath('source', 'common')
            ->assertJsonPath('plans.0.metric_key', 'calls');

        KpiPlan::query()->create([
            'role_slug' => 'mop',
            'user_id' => $mop->id,
            'metric_key' => 'calls',
            'daily_plan' => 60,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $this->getJson('/api/kpi/plans?user_id='.$mop->id.'&date=2026-05-06')
            ->assertOk()
            ->assertJsonPath('source', 'personal')
            ->assertJsonPath('plans.0.daily_plan', 60);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => null,
            'metric_key' => 'calls',
            'daily_plan' => 30,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $this->getJson('/api/kpi/plans?user_id='.$agent->id.'&date=2026-05-06')
            ->assertOk()
            ->assertJsonPath('source', 'common')
            ->assertJsonPath('plans.0.daily_plan', 30);
    }

    public function test_personal_plan_period_conflict_returns_409(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000441', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000442', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $payload = [
            'effective_from' => '2026-05-01',
            'effective_to' => '2026-05-31',
            'items' => [
                ['metric_key' => 'calls', 'daily_plan' => 10, 'weight' => 0.5],
                ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.5],
            ],
        ];

        $this->patchJson('/api/kpi/plans/'.$agent->id, $payload)->assertOk();
        $this->patchJson('/api/kpi/plans/'.$agent->id, $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'KPI_PLAN_PERIOD_CONFLICT');
    }

    public function test_bulk_upsert_returns_per_row_result_and_supports_decimal_values(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $otherGroup = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);

        $mop = User::create(['name' => 'MOP', 'phone' => '900000451', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000452', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern', 'phone' => '900000453', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $foreignAgent = User::create(['name' => 'Foreign Agent', 'phone' => '900000454', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $otherGroup->id]);

        Sanctum::actingAs($mop);

        $response = $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => null],
            'rows' => [
                [
                    'user_id' => $agent->id,
                    'items' => [
                        ['metric' => 'objects', 'daily_plan' => 1.4, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'sales', 'daily_plan' => 0.8, 'weight' => 0.2, 'comment' => 'x'],
                    ],
                ],
                [
                    'user_id' => $intern->id,
                    'items' => [
                        ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.1, 'comment' => 'x'],
                        ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2, 'comment' => 'x'],
                    ],
                ],
                [
                    'user_id' => $foreignAgent->id,
                    'items' => [
                        ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2, 'comment' => 'x'],
                        ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2, 'comment' => 'x'],
                    ],
                ],
            ],
        ])->assertOk();

        $response->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 2)
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.code', 'KPI_VALIDATION_FAILED')
            ->assertJsonPath('results.2.code', 'KPI_FORBIDDEN_SCOPE');

        $this->assertDatabaseHas('kpi_plans', [
            'user_id' => $agent->id,
            'metric_key' => 'objects',
            'daily_plan' => '1.4000',
        ]);
    }

    public function test_bulk_upsert_keeps_conflict_error_by_default(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000455', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000456', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $items = [
            ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
            ['metric' => 'shows', 'daily_plan' => 2, 'weight' => 0.2],
            ['metric' => 'ads', 'daily_plan' => 10, 'weight' => 0.2],
            ['metric' => 'calls', 'daily_plan' => 30, 'weight' => 0.2],
            ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
        ];

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [['user_id' => $agent->id, 'items' => $items]],
        ])->assertOk()->assertJsonPath('success_count', 1);

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-02',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [['user_id' => $agent->id, 'items' => $items]],
        ])->assertOk()
            ->assertJsonPath('success_count', 0)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.code', 'KPI_PLAN_PERIOD_CONFLICT');
    }

    public function test_bulk_upsert_replace_if_conflict_replaces_existing_personal_plan_idempotently(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000457', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000458', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $seedItems = [
            ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
            ['metric' => 'shows', 'daily_plan' => 2, 'weight' => 0.2],
            ['metric' => 'ads', 'daily_plan' => 10, 'weight' => 0.2],
            ['metric' => 'calls', 'daily_plan' => 30, 'weight' => 0.2],
            ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
        ];

        $replaceItems = [
            ['metric' => 'objects', 'daily_plan' => 20, 'weight' => 0.2],
            ['metric' => 'shows', 'daily_plan' => 40, 'weight' => 0.2],
            ['metric' => 'ads', 'daily_plan' => 220, 'weight' => 0.2],
            ['metric' => 'calls', 'daily_plan' => 600, 'weight' => 0.2],
            ['metric' => 'sales', 'daily_plan' => 20, 'weight' => 0.2],
        ];

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [['user_id' => $agent->id, 'items' => $seedItems]],
        ])->assertOk()->assertJsonPath('success_count', 1);

        $payload = [
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'replace_if_conflict' => true,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [['user_id' => $agent->id, 'items' => $replaceItems]],
        ];

        $this->postJson('/api/kpi/plans/bulk-upsert', $payload)
            ->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('results.0.ok', true);

        $this->postJson('/api/kpi/plans/bulk-upsert', $payload)
            ->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('results.0.ok', true);

        $this->assertSame(5, KpiPlan::query()->where('user_id', $agent->id)->count());
        $this->assertSame(
            '600.0000',
            (string) KpiPlan::query()
                ->where('user_id', $agent->id)
                ->where('metric_key', 'calls')
                ->whereDate('effective_from', '2026-05-06')
                ->value('daily_plan')
        );
    }

    public function test_bulk_upsert_personal_plan_is_visible_in_list_with_branch_group_filter(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000601', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000602', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $items = [
            ['metric' => 'objects', 'daily_plan' => 20, 'weight' => 0.2],
            ['metric' => 'shows', 'daily_plan' => 40, 'weight' => 0.2],
            ['metric' => 'ads', 'daily_plan' => 220, 'weight' => 0.2],
            ['metric' => 'calls', 'daily_plan' => 600, 'weight' => 0.2],
            ['metric' => 'sales', 'daily_plan' => 20, 'weight' => 0.2],
        ];

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'replace_if_conflict' => true,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [['user_id' => $agent->id, 'items' => $items]],
        ])->assertOk()->assertJsonPath('success_count', 1);

        $listResponse = $this->getJson('/api/kpi/plans/list?type=personal&branch_group_id='.$group->id.'&page=1&per_page=20')
            ->assertOk();

        $foundUserIds = collect($listResponse->json('data'))->pluck('user_id')->filter()->values()->all();
        $this->assertContains($agent->id, $foundUserIds);
    }

    public function test_mop_cannot_manage_common_plan_even_in_own_group(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $otherGroup = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);
        $mop = User::create(['name' => 'MOP', 'phone' => '900000455', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($mop);

        $payload = [
            'role' => 'mop',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
                ['metric' => 'shows', 'daily_plan' => 2, 'weight' => 0.2],
                ['metric' => 'ads', 'daily_plan' => 10, 'weight' => 0.2],
                ['metric' => 'calls', 'daily_plan' => 30, 'weight' => 0.2],
                ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
            ],
        ];

        $this->putJson('/api/kpi/plans/common', $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_ROLE_ACTION');

        $payload['branch_group_id'] = $otherGroup->id;
        $this->putJson('/api/kpi/plans/common', $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_ROLE_ACTION');
    }

    public function test_mop_can_read_common_plan_for_own_group(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $mop = User::create(['name' => 'MOP', 'phone' => '900000458', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 40,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);

        Sanctum::actingAs($mop);

        $this->getJson('/api/kpi/plans/common?role=agent&date=2026-05-06&branch_id='.$branch->id.'&branch_group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('data.0.metric_key', 'calls');
    }

    public function test_common_plans_supports_roles_array_and_returns_flat_rows_with_role_field(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000658', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 20,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);
        KpiPlan::query()->create([
            'role_slug' => 'intern',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'shows',
            'daily_plan' => 3,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/plans/common?roles[]=agent&roles[]=intern&date=2026-05-06&branch_id='.$branch->id.'&branch_group_id='.$group->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role', 'agent')
            ->assertJsonPath('data.1.role', 'intern');
    }

    public function test_rop_can_create_and_update_common_plan_in_own_scope(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900000459', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        $rop->supervisedGroups()->sync([$rop->branch_group_id]);
        Sanctum::actingAs($rop);

        $payload = [
            'role' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'items' => [
                ['metric' => 'objects', 'daily_plan' => 1.4, 'weight' => 0.2],
                ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2],
                ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2],
                ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2],
                ['metric' => 'sales', 'daily_plan' => 0.8, 'weight' => 0.2],
            ],
        ];

        $this->putJson('/api/kpi/plans/common', $payload)->assertOk();

        $payload['items'][3]['daily_plan'] = 45;
        $this->patchJson('/api/kpi/plans/common', $payload)->assertOk();

        $this->assertDatabaseHas('kpi_plans', [
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => '45.0000',
        ]);
    }

    public function test_rop_bulk_upsert_handles_50_plus_users_successfully(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900000460', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        $users = collect(range(1, 55))->map(function (int $i) use ($agentRole, $branch, $group) {
            return User::create([
                'name' => 'Agent '.$i,
                'phone' => '901'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'role_id' => $agentRole->id,
                'branch_id' => $branch->id,
                'branch_group_id' => $group->id,
            ]);
        });

        $rop->supervisedGroups()->sync([$rop->branch_group_id]);
        Sanctum::actingAs($rop);

        $rows = $users->map(fn (User $user) => [
            'user_id' => $user->id,
            'items' => [
                ['metric' => 'objects', 'daily_plan' => 1.4, 'weight' => 0.2, 'comment' => ''],
                ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2, 'comment' => ''],
                ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2, 'comment' => ''],
                ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2, 'comment' => ''],
                ['metric' => 'sales', 'daily_plan' => 0.8, 'weight' => 0.2, 'comment' => ''],
            ],
        ])->values()->all();

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => $rows,
        ])->assertOk()
            ->assertJsonPath('success_count', 55)
            ->assertJsonPath('failed_count', 0);
    }

    public function test_rop_bulk_upsert_rejects_inaccessible_employee_before_any_writes(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900000760', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000761', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern', 'phone' => '900000762', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        $rop->supervisedGroups()->sync([$rop->branch_group_id]);
        Sanctum::actingAs($rop);

        $payloadItems = [
            ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
            ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2],
            ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2],
            ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2],
            ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
        ];

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'intern', 'roles' => ['agent']],
            'rows' => [
                ['user_id' => $agent->id, 'items' => $payloadItems],
                ['user_id' => $intern->id, 'items' => $payloadItems],
            ],
        ])->assertForbidden()->assertJsonPath('code', 'RBAC_GROUP_SCOPE_VIOLATION');
        $this->assertDatabaseCount('kpi_plans', 0);
    }

    public function test_rop_bulk_plan_late_group_conflict_rolls_back_earlier_valid_rows(): void
    {
        $branch = Branch::create(['name' => 'Main']);
        $a = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'A']);
        $b = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'B']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900012001', 'role_id' => $ropRole->id, 'branch_id' => $branch->id]);
        $agentA = User::create(['name' => 'A', 'phone' => '900012002', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $a->id]);
        $agentB = User::create(['name' => 'B', 'phone' => '900012003', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $b->id]);
        $rop->supervisedGroups()->attach([$a->id, $b->id]);
        Sanctum::actingAs($rop);
        $items = array_map(fn ($metric) => ['metric' => $metric, 'daily_plan' => 1, 'weight' => 0.2], ['objects', 'shows', 'ads', 'calls', 'sales']);
        $payload = ['effective_from' => '2026-05-06', 'scope' => ['branch_group_id' => $a->id, 'roles' => ['agent']],
            'rows' => [['user_id' => $agentA->id, 'items' => $items], ['user_id' => $agentB->id, 'items' => $items]]];
        $this->postJson('/api/kpi/plans/bulk-upsert', $payload)->assertForbidden();
        $this->assertDatabaseCount('kpi_plans', 0);
        $payload['rows'] = [$payload['rows'][0]];
        $this->postJson('/api/kpi/plans/bulk-upsert', $payload)->assertOk()->assertJsonPath('success_count', 1);
        $this->assertGreaterThan(0, \DB::table('kpi_plans')->count());
    }

    public function test_mop_bulk_upsert_rejects_foreign_scope_at_request_level(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $otherGroup = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);

        $mop = User::create(['name' => 'MOP', 'phone' => '900000456', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000457', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($mop);

        $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $otherGroup->id, 'role' => null],
            'rows' => [[
                'user_id' => $agent->id,
                'items' => [
                    ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
                    ['metric' => 'shows', 'daily_plan' => 3, 'weight' => 0.2],
                    ['metric' => 'ads', 'daily_plan' => 12, 'weight' => 0.2],
                    ['metric' => 'calls', 'daily_plan' => 40, 'weight' => 0.2],
                    ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
                ],
            ]],
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');
    }

    public function test_eligible_users_returns_paginated_data_and_enforces_mop_scope(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $group2 = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);

        $mop = User::create(['name' => 'MOP', 'phone' => '900000461', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        User::create(['name' => 'Ivan Agent', 'phone' => '900000462', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        User::create(['name' => 'Petr Agent', 'phone' => '900000463', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group2->id]);

        Sanctum::actingAs($mop);

        $this->getJson('/api/kpi/plans/eligible-users?role=agent&q=Ivan&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ivan Agent');

        $this->getJson('/api/kpi/plans/eligible-users?role=mop')
            ->assertOk();
    }

    public function test_apply_common_plan_to_users_creates_personal_plans(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000471', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent1 = User::create(['name' => 'Agent1', 'phone' => '900000472', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent2 = User::create(['name' => 'Agent2', 'phone' => '900000473', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/kpi/plans/common', [
            'role' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
                ['metric' => 'shows', 'daily_plan' => 2, 'weight' => 0.2],
                ['metric' => 'ads', 'daily_plan' => 10, 'weight' => 0.2],
                ['metric' => 'calls', 'daily_plan' => 30, 'weight' => 0.2],
                ['metric' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
            ],
        ])->assertOk();

        $this->postJson('/api/kpi/plans/common/apply-to-users', [
            'role' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => '2026-05-06',
            'effective_to' => null,
            'user_ids' => [$agent1->id, $agent2->id],
        ])->assertOk()
            ->assertJsonPath('success_count', 2)
            ->assertJsonPath('failed_count', 0);

        $this->assertDatabaseHas('kpi_plans', ['user_id' => $agent1->id, 'metric_key' => 'calls', 'daily_plan' => '30.0000']);
        $this->assertDatabaseHas('kpi_plans', ['user_id' => $agent2->id, 'metric_key' => 'calls', 'daily_plan' => '30.0000']);
    }

    public function test_metric_mapping_contains_stable_keys_and_russian_labels(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000481', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/metric-mapping')
            ->assertOk()
            ->assertJsonPath('data.metric_keys.0', 'objects')
            ->assertJsonPath('data.metric_keys.1', 'shows')
            ->assertJsonPath('data.metric_keys.2', 'ads')
            ->assertJsonPath('data.metric_keys.3', 'calls')
            ->assertJsonPath('data.metric_keys.4', 'sales')
            ->assertJsonPath('data.mapping.objects.label', 'Объекты')
            ->assertJsonPath('data.mapping.sales.description', 'Количество завершённых сделок за период.');
    }

    public function test_personal_and_common_read_filter_out_non_whitelist_metrics(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000901', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000902', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'metric_key' => 'calls',
            'daily_plan' => 10,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);
        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'metric_key' => 'legacy_metric',
            'daily_plan' => 999,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $personal = $this->getJson('/api/kpi/plans?user_id='.$agent->id.'&date=2026-05-06')->assertOk();
        $this->assertSame(['calls'], collect($personal->json('plans'))->pluck('metric_key')->values()->all());

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'objects',
            'daily_plan' => 1,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);
        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'foo',
            'daily_plan' => 1,
            'weight' => 1,
            'effective_from' => '2026-05-01',
        ]);

        $common = $this->getJson('/api/kpi/plans/common?role=agent&date=2026-05-06&branch_id='.$branch->id.'&branch_group_id='.$group->id)->assertOk();
        $this->assertSame(['objects'], collect($common->json('plans'))->pluck('metric_key')->values()->all());
    }

    public function test_personal_upsert_rejects_unknown_metric_with_422(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000903', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000904', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/kpi/plans/'.$agent->id, [
            'effective_from' => '2026-05-01',
            'items' => [
                ['metric' => 'legacy_metric', 'daily_plan' => 1, 'weight' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED');

        $this->assertArrayHasKey('items.0.metric', (array) $response->json('details.errors'));
    }

    public function test_bulk_upsert_unknown_metric_returns_row_level_kpi_validation_failed(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000905', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000906', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/kpi/plans/bulk-upsert', [
            'effective_from' => '2026-05-06',
            'scope' => ['branch_id' => $branch->id, 'branch_group_id' => $group->id, 'role' => 'agent'],
            'rows' => [[
                'user_id' => $agent->id,
                'items' => [
                    ['metric_key' => 'legacy_metric', 'daily_plan' => 1, 'weight' => 1],
                ],
            ]],
        ])->assertOk()
            ->assertJsonPath('success_count', 0)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.code', 'KPI_VALIDATION_FAILED');

        $this->assertArrayHasKey('items.0.metric_key', (array) $response->json('results.0.details.errors'));
    }

    public function test_rop_plans_crud_and_copy_with_period_conflict(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900000907', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $rop->supervisedGroups()->sync([$rop->branch_group_id]);
        Sanctum::actingAs($rop);

        $payload = [
            'role' => 'agent',
            'month' => '2026-06',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'items' => [
                ['metric_key' => 'objects', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'shows', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'ads', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'calls', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'sales', 'plan_value' => 10, 'weight' => 0.2],
            ],
        ];

        $created = $this->postJson('/api/kpi/rop-plans', $payload)
            ->assertCreated()
            ->assertJsonPath('month', '2026-06')
            ->assertJsonPath('meta.source', 'rop_plan');

        $id = (int) $created->json('id');

        $this->getJson('/api/kpi/rop-plans?month=2026-06&role=agent&branch_id='.$branch->id.'&branch_group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('meta.exists', true)
            ->assertJsonPath('plans.0.id', $id);

        $this->patchJson('/api/kpi/rop-plans/'.$id, [
            'items' => [
                ['metric_key' => 'objects', 'plan_value' => 20, 'weight' => 0.2],
                ['metric_key' => 'shows', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'ads', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'calls', 'plan_value' => 10, 'weight' => 0.2],
                ['metric_key' => 'sales', 'plan_value' => 10, 'weight' => 0.2],
            ],
        ])->assertOk()->assertJsonPath('items.0.metric_key', 'objects');

        $copy = $this->postJson('/api/kpi/rop-plans/'.$id.'/copy', ['month' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('month', '2026-07');
        $this->assertNotSame($id, (int) $copy->json('id'));

        $this->postJson('/api/kpi/rop-plans', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'KPI_PLAN_PERIOD_CONFLICT');
    }

    public function test_rop_plans_reject_unknown_metric_and_keep_original_error_field_name(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900000908', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/kpi/rop-plans', [
            'role' => 'agent',
            'month' => '2026-06',
            'items' => [
                ['metric' => 'legacy_metric', 'plan_value' => 10, 'weight' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED');

        $this->assertArrayHasKey('items.0.metric', (array) $response->json('details.errors'));
    }

    public function test_mop_cannot_create_rop_plan(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $mop = User::create(['name' => 'MOP', 'phone' => '900000909', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($mop);

        $this->postJson('/api/kpi/rop-plans', [
            'role' => 'agent',
            'month' => '2026-06',
            'items' => [
                ['metric_key' => 'calls', 'plan_value' => 10, 'weight' => 1],
            ],
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_ROLE_ACTION');
    }

    public static function planMonths(): array
    {
        return [
            '31 days' => [2026, 5, 31],
            '30 days' => [2026, 6, 30],
            '28 days' => [2026, 2, 28],
            'leap February' => [2028, 2, 29],
        ];
    }

    #[DataProvider('planMonths')]
    public function test_monthly_v2_uses_personal_plan_and_exposes_plan_source_per_metric(int $year, int $month, int $days): void
    {
        (require database_path('migrations/2026_05_09_162458_add_plan_period_to_kpi_plans.php'))->up();
        $date = sprintf('%04d-%02d-01', $year, $month);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001901', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001902', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/kpi/plans/common', [
            'role' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'effective_from' => $date,
            'items' => [['metric_key' => 'calls', 'monthly_plan' => 10, 'weight' => 1]],
        ])->assertOk();

        $url = '/api/kpi/monthly?year='.$year.'&month='.$month.'&agent_id='.$agent->id.'&v=2&debug_plan_trace=1';
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.metrics.calls.plan_source', 'common')
            ->assertJsonPath('data.0.metrics.calls.target_value', 10);

        $this->putJson('/api/kpi/plans/'.$agent->id, [
            'effective_from' => $date,
            'items' => [['metric_key' => 'calls', 'monthly_plan' => 25, 'weight' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.0.monthly_plan', 25)
            ->assertJsonPath('data.0.plan_period', 'month');
        $this->assertDatabaseHas('kpi_plans', [
            'user_id' => $agent->id, 'metric_key' => 'calls', 'daily_plan' => 25, 'plan_period' => 'month',
        ]);

        $response = $this->getJson($url)
            ->assertOk();

        $row = collect((array) $response->json('data'))
            ->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame('personal', (string) ($row['metrics']['calls']['plan_source'] ?? ''));
        $this->assertSame(25, (int) ($row['metrics']['calls']['target_value'] ?? 0));

        $samples = collect((array) $response->json('meta.debug.plan_source_samples'));
        $sample = $samples->first(fn (array $s) => (int) ($s['employee_id'] ?? 0) === $agent->id && (string) ($s['metric'] ?? '') === 'calls');
        $this->assertNotNull($sample);
        $this->assertSame('personal', (string) ($sample['plan_source'] ?? ''));
        $this->assertSame(25, (int) ($sample['plan_daily_value'] ?? 0));

        $this->getJson('/api/kpi/daily?v=2&date='.$date.'&agent_id='.$agent->id)
            ->assertOk()
            ->assertJsonPath('data.0.metrics.calls.plan_source', 'personal')
            ->assertJsonPath('data.0.metrics.calls.target_value', round(25 / $days, 4));
    }

    public function test_weekly_v2_role_filter_applies_before_pagination_for_admin(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $mopRole = Role::create(['name' => 'Mop', 'slug' => 'mop']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001911', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        User::create(['name' => 'Agent', 'phone' => '900001912', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        User::create(['name' => 'Mop', 'phone' => '900001913', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly?year=2026&week=19&role=agent&v=2&page=1&per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.last_page', 1);

        $roles = collect((array) $response->json('data'))->pluck('role')->unique()->values()->all();
        $this->assertSame(['agent'], $roles);
    }

    public function test_weekly_daily_v2_returns_only_selected_day_data(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001921', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001922', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'calls_count' => 5, 'ad_count' => 2, 'shows_count' => 1, 'new_properties_count' => 3, 'deals_count' => 1, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-15', 'calls_count' => 99, 'ad_count' => 99, 'shows_count' => 99, 'new_properties_count' => 99, 'deals_count' => 99, 'submitted_at' => now()]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly-daily?v=2&day=2026-05-16')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'day')
            ->assertJsonPath('meta.period_key', '2026-05-16');

        $row = collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame(5.0, (float) ($row['calls'] ?? 0));
        $this->assertSame(2.0, (float) ($row['ads'] ?? 0));
        $this->assertNotSame(104.0, (float) ($row['calls'] ?? 0));
    }

    public function test_weekly_daily_v2_breakdown_contains_iso_date_field(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001925', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001926', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'calls_count' => 3, 'submitted_at' => now()]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly-daily?v=2&day=2026-05-16&include_breakdown=1')
            ->assertOk();

        $row = collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-05-16', (string) data_get($row, 'breakdown_by_day.0.date'));
        $this->assertSame('2026-05-16', (string) data_get($row, 'breakdown_by_day.0.period_key'));
    }

    public function test_weekly_daily_v2_supports_branch_group_and_agent_filters(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branchA = Branch::create(['name' => 'A']);
        $branchB = Branch::create(['name' => 'B']);
        $groupA = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'GA']);
        $groupB = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'GB']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001931', 'role_id' => $adminRole->id, 'branch_id' => $branchA->id, 'branch_group_id' => $groupA->id]);
        $agentA = User::create(['name' => 'Agent A', 'phone' => '900001932', 'role_id' => $agentRole->id, 'branch_id' => $branchA->id, 'branch_group_id' => $groupA->id]);
        $agentB = User::create(['name' => 'Agent B', 'phone' => '900001933', 'role_id' => $agentRole->id, 'branch_id' => $branchB->id, 'branch_group_id' => $groupB->id]);

        DailyReport::create(['user_id' => $agentA->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'calls_count' => 4, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $agentB->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'calls_count' => 7, 'submitted_at' => now()]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/weekly-daily?v=2&day=2026-05-16&branch_id='.$branchA->id.'&branch_group_id='.$groupA->id.'&agent_id='.$agentA->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.employee_id', $agentA->id);
    }

    public function test_weekly_daily_v2_rejects_invalid_day_format(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001941', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/weekly-daily?v=2&day=16-05-2026')
            ->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED');
    }

    public function test_weekly_daily_v2_returns_empty_rows_with_valid_pagination(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001951', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001952', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'calls_count' => 2, 'submitted_at' => now()]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly-daily?v=2&day=2026-05-17&agent_id='.$agent->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->assertSame([], (array) $response->json('data'));
    }

    public function test_weekly_v2_day_filter_returns_row_for_agent_without_reports(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001961', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001962', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly?day=2026-05-16&agent_id='.$agent->id.'&v=2&include_breakdown=0')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'week')
            ->assertJsonPath('meta.period_key', '2026-W20')
            ->assertJsonPath('meta.timezone', 'Asia/Dushanbe')
            ->assertJsonPath('meta.pagination.total', 1);

        $row = collect((array) $response->json('rows'))->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame($agent->id, (int) ($row['employee_id'] ?? 0));
        $this->assertSame('Agent', (string) ($row['employee_name'] ?? ''));
        $this->assertSame(0.0, (float) data_get($row, 'metrics.objects.fact_value'));
        $this->assertSame(0.0, (float) data_get($row, 'metrics.objects.final_value'));
        $this->assertIsNumeric(data_get($row, 'metrics.objects.target_value'));
        $this->assertSame('system', (string) data_get($row, 'metrics.objects.plan_source'));
        $this->assertArrayNotHasKey('breakdown_by_day', (array) $row);
    }

    public function test_weekly_v2_day_filter_supports_user_id_without_reports(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001963', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001964', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/weekly?day=2026-05-16&user_id='.$agent->id.'&v=2&include_breakdown=0')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'week')
            ->assertJsonPath('meta.period_key', '2026-W20')
            ->assertJsonPath('meta.timezone', 'Asia/Dushanbe')
            ->assertJsonPath('meta.pagination.total', 1);

        $row = collect((array) $response->json('rows'))->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) data_get($row, 'metrics.objects.final_value'));
        $this->assertIsNumeric(data_get($row, 'metrics.objects.target_value'));
    }

    public function test_monthly_v2_filter_returns_row_for_agent_without_reports(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001965', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001966', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/monthly?month=5&year=2026&agent_id='.$agent->id.'&v=2&include_breakdown=0')
            ->assertOk()
            ->assertJsonPath('meta.period_type', 'month')
            ->assertJsonPath('meta.period_key', '2026-05')
            ->assertJsonPath('meta.timezone', 'Asia/Dushanbe')
            ->assertJsonPath('meta.pagination.total', 1);

        $row = collect((array) $response->json('rows'))->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) data_get($row, 'metrics.objects.fact_value'));
        $this->assertSame(0.0, (float) data_get($row, 'metrics.objects.final_value'));
        $this->assertIsNumeric(data_get($row, 'metrics.objects.target_value'));
        $this->assertSame('system', (string) data_get($row, 'metrics.objects.plan_source'));
        $this->assertArrayNotHasKey('breakdown_by_day', (array) $row);
    }

    public function test_daily_v2_requires_date_and_uses_strict_metric_keys_for_rop_list(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002001', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/kpi/daily?v=2')->assertStatus(422);
    }

    public function test_daily_v2_system_and_manual_sources_and_missing_report_contract(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002011', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002012', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $callType = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        $adType = CrmTaskType::create(['code' => 'AD_PUBLICATION', 'name' => 'Ad', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        CrmTask::create(['task_type_id' => $callType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'c1', 'status' => 'done', 'completed_at' => '2026-05-16 08:00:00']);
        CrmTask::create(['task_type_id' => $adType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'a1', 'status' => 'done', 'completed_at' => '2026-05-16 09:00:00']);
        \DB::table('properties')->insert(['created_by' => $agent->id, 'agent_id' => $agent->id, 'moderation_status' => 'new', 'created_at' => '2026-05-16 10:00:00', 'updated_at' => '2026-05-16 10:00:00']);
        \DB::table('bookings')->insert(['agent_id' => $agent->id, 'start_time' => '2026-05-16 11:00:00', 'created_at' => '2026-05-16 11:00:00', 'updated_at' => '2026-05-16 11:00:00']);

        $noReport = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $rowNoReport = (array) $noReport->json('data.0');
        $this->assertFalse((bool) ($rowNoReport['submitted_daily_report'] ?? true));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.objects.final_value'));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.shows.final_value'));
        $this->assertSame('mixed', (string) data_get($rowNoReport, 'metrics.ads.source'));
        $this->assertSame('mixed', (string) data_get($rowNoReport, 'metrics.calls.source'));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.ads.fact_value'));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.calls.fact_value'));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.ads.final_value'));
        $this->assertSame(1, (int) data_get($rowNoReport, 'metrics.calls.final_value'));
        $this->assertSame(0, (int) data_get($rowNoReport, 'metrics.sales.final_value'));

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'deals_count' => 2, 'submitted_at' => now()]);
        $withReport = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $rowWithReport = (array) $withReport->json('data.0');
        $this->assertTrue((bool) ($rowWithReport['submitted_daily_report'] ?? false));
        $this->assertSame('system', (string) data_get($rowWithReport, 'metrics.sales.source'));
        $this->assertSame(0, (int) data_get($rowWithReport, 'metrics.sales.final_value'));
        $this->assertSame(0, (int) data_get($rowWithReport, 'metrics.sales.manual_value'));
        $this->assertSame('mixed', (string) data_get($rowWithReport, 'metrics.calls.source'));

        \DB::table('properties')->insert([
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-05-16 12:00:00',
            'created_at' => '2026-05-15 10:00:00',
            'updated_at' => '2026-05-16 12:00:00',
        ]);
        $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)
            ->assertOk()
            ->assertJsonPath('data.0.metrics.sales.source', 'system')
            ->assertJsonPath('data.0.metrics.sales.fact_value', 1)
            ->assertJsonPath('data.0.metrics.sales.final_value', 1)
            ->assertJsonPath('data.0.metrics.sales.manual_value', 0);
        $this->assertDatabaseHas('daily_reports', ['user_id' => $agent->id, 'deals_count' => 2]);
    }

    public function test_daily_v2_plan_fallback_target_zero_and_status_calculation(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002021', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002022', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        Config::set('kpi.v2.targets.calls', 0);
        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'objects',
            'daily_plan' => 3,
            'weight' => 0.2,
            'effective_from' => '2026-05-01',
        ]);
        \DB::table('properties')->insert(['created_by' => $agent->id, 'agent_id' => $agent->id, 'moderation_status' => 'new', 'created_at' => '2026-05-16 10:00:00', 'updated_at' => '2026-05-16 10:00:00']);

        $common = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $commonRow = (array) $common->json('data.0');
        $this->assertSame('common', (string) data_get($commonRow, 'metrics.objects.plan_source'));
        $this->assertSame(0.0, (float) data_get($commonRow, 'metrics.calls.progress_pct'));

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'objects',
            'daily_plan' => 5,
            'weight' => 0.2,
            'effective_from' => '2026-05-01',
        ]);
        $personal = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $personalRow = (array) $personal->json('data.0');
        $this->assertSame('personal', (string) data_get($personalRow, 'metrics.objects.plan_source'));
        $this->assertIsNumeric($personalRow['overall_progress_pct'] ?? null);
        $this->assertContains((string) ($personalRow['status'] ?? ''), ['done', 'control', 'weak', 'risk', 'urgent']);
    }

    public function test_daily_v2_objects_count_uses_agent_id_only_for_legacy_rows_without_creator(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002026', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002027', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $other = User::create(['name' => 'Other', 'phone' => '900002028', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        \DB::table('properties')->insert([
            [
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'moderation_status' => 'new',
                'created_at' => '2026-05-16 10:00:00',
                'updated_at' => '2026-05-16 10:00:00',
            ],
            [
                'created_by' => $other->id,
                'agent_id' => $agent->id,
                'moderation_status' => 'new',
                'created_at' => '2026-05-16 11:00:00',
                'updated_at' => '2026-05-16 11:00:00',
            ],
            [
                'created_by' => null,
                'agent_id' => $agent->id,
                'moderation_status' => 'new',
                'created_at' => '2026-05-16 12:00:00',
                'updated_at' => '2026-05-16 12:00:00',
            ],
        ]);

        $response = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $row = (array) $response->json('data.0');

        $this->assertSame(2, (int) data_get($row, 'metrics.objects.final_value'));
    }

    public function test_daily_v2_objects_use_created_at_not_sold_at_for_closed_properties(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002033', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002034', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        \DB::table('properties')->insert([
            [
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'sale_user_id' => $agent->id,
                'moderation_status' => 'sold',
                'sold_at' => '2026-05-16 12:00:00',
                'created_at' => '2026-05-15 10:00:00',
                'updated_at' => '2026-05-16 12:00:00',
            ],
            [
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'sale_user_id' => null,
                'moderation_status' => 'approved',
                'sold_at' => null,
                'created_at' => '2026-05-16 10:00:00',
                'updated_at' => '2026-05-16 10:00:00',
            ],
            [
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'sale_user_id' => $agent->id,
                'moderation_status' => 'sold_by_owner',
                'sold_at' => '2026-05-16 13:00:00',
                'created_at' => '2026-05-15 10:00:00',
                'updated_at' => '2026-05-16 13:00:00',
            ],
            [
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'sale_user_id' => $agent->id,
                'moderation_status' => 'rented',
                'sold_at' => '2026-05-16 14:00:00',
                'created_at' => '2026-05-15 10:00:00',
                'updated_at' => '2026-05-16 14:00:00',
            ],
        ]);

        $response = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $row = (array) $response->json('data.0');

        $this->assertSame(1, (int) data_get($row, 'metrics.objects.final_value'));
        $this->assertSame(1, (int) data_get($row, 'metrics.sales.final_value'));
    }

    public static function salesMappingSources(): array
    {
        return ['system' => ['system'], 'legacy manual' => ['manual'], 'legacy mixed' => ['mixed']];
    }

    #[DataProvider('salesMappingSources')]
    public function test_daily_v2_keeps_sales_system_only_despite_mapping_override(string $source): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002031', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002032', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $mapping = (array) config('kpi.v2.metric_mapping', []);
        $mapping['sales']['source_type'] = $source;
        Config::set('kpi.v2.metric_mapping', $mapping);

        \DB::table('properties')->insert([
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-05-16 12:00:00',
            'created_at' => '2026-05-16 10:00:00',
            'updated_at' => '2026-05-16 12:00:00',
        ]);
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'deals_count' => 3, 'submitted_at' => now()]);

        $response = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $row = (array) $response->json('data.0');
        $this->assertSame('system', (string) data_get($row, 'metrics.sales.source'));
        $this->assertSame(1, (int) data_get($row, 'metrics.sales.fact_value'));
        $this->assertSame(0, (int) data_get($row, 'metrics.sales.manual_value'));
        $this->assertSame(1, (int) data_get($row, 'metrics.sales.final_value'));
        $this->assertDatabaseHas('daily_reports', ['user_id' => $agent->id, 'deals_count' => 3]);
    }

    public function test_weekly_v2_ads_calls_prefer_completed_crm_task_facts(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002041', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002042', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $callType = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        $adType = CrmTaskType::create(['code' => 'AD_CREATE', 'name' => 'Ad', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        foreach (['2026-05-12 08:00:00', '2026-05-13 08:00:00'] as $completedAt) {
            CrmTask::create(['task_type_id' => $callType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'Call', 'status' => 'done', 'completed_at' => $completedAt]);
        }
        CrmTask::create(['task_type_id' => $adType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'Ad', 'status' => 'done', 'completed_at' => '2026-05-12 09:00:00']);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-12', 'ad_count' => 10, 'calls_count' => 5, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-13', 'ad_count' => 0, 'calls_count' => 0, 'submitted_at' => now()]);

        $response = $this->getJson('/api/kpi/weekly?year=2026&week=20&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertSame('mixed', (string) data_get($row, 'metrics.ads.source'));
        $this->assertSame('mixed', (string) data_get($row, 'metrics.calls.source'));
        $this->assertSame(1, (int) data_get($row, 'metrics.ads.fact_value'));
        $this->assertSame(2, (int) data_get($row, 'metrics.calls.fact_value'));
        $this->assertSame(10, (int) data_get($row, 'metrics.ads.manual_value'));
        $this->assertSame(5, (int) data_get($row, 'metrics.calls.manual_value'));
        $this->assertSame(1, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(2, (int) data_get($row, 'metrics.calls.final_value'));
    }

    public function test_weekly_v2_all_period_uses_full_scoped_history_without_weekly_missing_dates(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002071', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002072', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent->forceFill(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'])->save();
        Sanctum::actingAs($admin);

        $callType = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        CrmTask::create([
            'task_type_id' => $callType->id,
            'assignee_id' => $agent->id,
            'creator_id' => $admin->id,
            'title' => 'Historical call',
            'status' => 'done',
            'completed_at' => '2026-05-12 08:00:00',
        ]);
        \DB::table('properties')->insert([
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-05-13 09:00:00',
            'created_at' => '2026-05-01 10:00:00',
            'updated_at' => '2026-05-13 09:00:00',
        ]);

        $currentWeek = $this->getJson('/api/kpi/weekly?v=2&agent_id='.$agent->id)->assertOk();
        $this->assertSame(0, (int) data_get($currentWeek->json('data.0'), 'metrics.calls.final_value'));

        $allPeriod = $this->getJson('/api/kpi/weekly?v=2&all_period=1&agent_id='.$agent->id)->assertOk();
        $row = (array) $allPeriod->json('data.0');

        $allPeriod->assertJsonPath('meta.period_type', 'range')
            ->assertJsonPath('meta.period_key', 'all')
            ->assertJsonPath('meta.date_from', '2026-01-01');
        $this->assertSame(1, (int) data_get($row, 'metrics.calls.final_value'));
        $this->assertSame(1, (int) data_get($row, 'metrics.sales.final_value'));
        $this->assertArrayNotHasKey('missing_report_dates', $row);
    }

    public function test_monthly_v2_ads_calls_use_completed_crm_tasks_without_manual_reports(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002051', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002052', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $callType = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        $adType = CrmTaskType::create(['code' => 'AD_PUBLICATION', 'name' => 'Ad', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true]);
        CrmTask::create(['task_type_id' => $callType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'Call', 'status' => 'done', 'completed_at' => '2026-05-12 08:00:00']);
        CrmTask::create(['task_type_id' => $adType->id, 'assignee_id' => $agent->id, 'creator_id' => $admin->id, 'title' => 'Ad', 'status' => 'done', 'completed_at' => '2026-05-13 09:00:00']);

        $response = $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertSame(1, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(1, (int) data_get($row, 'metrics.calls.final_value'));
    }

    public function test_daily_v2_ads_calls_fall_back_to_manual_values_without_crm_facts(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002061', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002062', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-16',
            'ad_count' => 4,
            'calls_count' => 7,
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $row = (array) $response->json('data.0');

        $this->assertSame('mixed', (string) data_get($row, 'metrics.ads.source'));
        $this->assertSame('mixed', (string) data_get($row, 'metrics.calls.source'));
        $this->assertSame(0, (int) data_get($row, 'metrics.ads.fact_value'));
        $this->assertSame(0, (int) data_get($row, 'metrics.calls.fact_value'));
        $this->assertSame(4, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(7, (int) data_get($row, 'metrics.calls.final_value'));
    }

    public function test_monthly_v2_formula_caps_metric_contribution_and_returns_breakdown_fields(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002061', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002062', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        foreach ([
            ['metric_key' => 'objects', 'daily_plan' => 15, 'weight' => 0.2],
            ['metric_key' => 'shows', 'daily_plan' => 10, 'weight' => 0.2],
            ['metric_key' => 'ads', 'daily_plan' => 480, 'weight' => 0.2],
            ['metric_key' => 'calls', 'daily_plan' => 720, 'weight' => 0.2],
            ['metric_key' => 'sales', 'daily_plan' => 2, 'weight' => 0.2],
        ] as $plan) {
            KpiPlan::query()->create([
                'role_slug' => 'agent',
                'user_id' => $agent->id,
                'branch_id' => $branch->id,
                'branch_group_id' => $group->id,
                'metric_key' => $plan['metric_key'],
                'daily_plan' => $plan['daily_plan'],
                'weight' => $plan['weight'],
                'effective_from' => '2026-05-01',
            ]);
        }

        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-16',
            'calls_count' => 45,
            'ad_count' => 206,
            'deals_count' => 1,
            'submitted_at' => now(),
        ]);

        for ($i = 0; $i < 27; $i++) {
            \DB::table('properties')->insert([
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'moderation_status' => 'new',
                'created_at' => '2026-05-10 10:00:00',
                'updated_at' => '2026-05-10 10:00:00',
            ]);
        }
        for ($i = 0; $i < 10; $i++) {
            \DB::table('bookings')->insert([
                'agent_id' => $agent->id,
                'start_time' => '2026-05-11 11:00:00',
                'created_at' => '2026-05-11 11:00:00',
                'updated_at' => '2026-05-11 11:00:00',
            ]);
        }

        $response = $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);

        $this->assertSame(720, (int) data_get($row, 'metrics.calls.target_value'));
        $this->assertSame(45, (int) data_get($row, 'metrics.calls.final_value'));
        $this->assertSame(480, (int) data_get($row, 'metrics.ads.target_value'));
        $this->assertSame(206, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(0.2, (float) data_get($row, 'metrics.calls.weight_used'));
        $this->assertSame(0.2, (float) data_get($row, 'metrics.ads.weight_used'));
        $this->assertIsNumeric(data_get($row, 'metrics.calls.contribution_pct'));
        $this->assertIsNumeric(data_get($row, 'metrics.ads.contribution_pct'));
        $this->assertIsNumeric(data_get($row, 'overall_progress_pct'));
        $this->assertLessThan(80.0, (float) data_get($row, 'kpi_percent'));
        $this->assertContains((string) data_get($row, 'status'), ['risk', 'urgent', 'weak', 'control']);
    }

    public function test_monthly_v2_hard_gate_caps_kpi_and_prevents_done_status_on_critical_underperformance(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002071', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002072', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        Config::set('kpi.v2.formula.cap_metric_progress_at_100', false);
        Config::set('kpi.v2.formula.hard_gate.enabled', true);
        Config::set('kpi.v2.formula.hard_gate.max_kpi_percent', 79.9);
        Config::set('kpi.v2.formula.hard_gate.metrics.calls', 60.0);
        Config::set('kpi.v2.formula.hard_gate.metrics.ads', 60.0);

        foreach ([
            ['metric_key' => 'objects', 'daily_plan' => 1, 'weight' => 0.2],
            ['metric_key' => 'shows', 'daily_plan' => 1, 'weight' => 0.2],
            ['metric_key' => 'ads', 'daily_plan' => 100, 'weight' => 0.2],
            ['metric_key' => 'calls', 'daily_plan' => 100, 'weight' => 0.2],
            ['metric_key' => 'sales', 'daily_plan' => 1, 'weight' => 0.2],
        ] as $plan) {
            KpiPlan::query()->create([
                'role_slug' => 'agent',
                'user_id' => $agent->id,
                'branch_id' => $branch->id,
                'branch_group_id' => $group->id,
                'metric_key' => $plan['metric_key'],
                'daily_plan' => $plan['daily_plan'],
                'weight' => $plan['weight'],
                'effective_from' => '2026-05-01',
            ]);
        }

        DailyReport::create([
            'user_id' => $agent->id,
            'role_slug' => 'agent',
            'report_date' => '2026-05-16',
            'calls_count' => 10,
            'ad_count' => 10,
            'deals_count' => 20,
            'submitted_at' => now(),
        ]);
        for ($i = 0; $i < 20; $i++) {
            \DB::table('properties')->insert([
                'created_by' => $agent->id,
                'agent_id' => $agent->id,
                'moderation_status' => 'new',
                'created_at' => '2026-05-08 10:00:00',
                'updated_at' => '2026-05-08 10:00:00',
            ]);
            \DB::table('bookings')->insert([
                'agent_id' => $agent->id,
                'start_time' => '2026-05-08 11:00:00',
                'created_at' => '2026-05-08 11:00:00',
                'updated_at' => '2026-05-08 11:00:00',
            ]);
        }

        $response = $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);

        $this->assertLessThan(80.0, (float) data_get($row, 'kpi_percent'));
        $this->assertNotSame('done', (string) data_get($row, 'status'));
        $this->assertTrue((bool) data_get($row, 'kpi_trace.hard_gate.triggered'));
    }

    private function useMigratedDailyReportsTable(): void
    {
        Schema::drop('daily_reports');
        foreach ([
            '2026_04_27_110000_create_daily_reports_table.php',
            '2026_05_01_210000_add_kpi_manual_fields_to_daily_reports_table.php',
            '2026_05_05_123000_extend_daily_reports_for_sales_and_finalize.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function createDailyKpiSystemTables(): void
    {
        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agent_id')->nullable();
                $t->timestamp('start_time')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('agent_id')->nullable();
                $t->string('moderation_status')->default('new');
                $t->timestamp('sold_at')->nullable();
                $t->unsignedBigInteger('sale_user_id')->nullable();
                $t->timestamps();
            });
        }
    }
}
