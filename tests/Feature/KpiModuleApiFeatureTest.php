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
            $t->string('status')->default('active'); $t->string('auth_method')->default('password'); $t->timestamps();
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
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);

        $admin = User::create(['name' => 'Admin', 'phone' => '900000101', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000102', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

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

        $response = $this->getJson('/api/kpi/daily?date=2026-05-05&v=2')
            ->assertOk();

        $metrics = $response->json('data.0.metrics');
        if ($metrics === null) {
            $this->fail('Unexpected response: '.json_encode($response->json()));
        }
        $this->assertSame(0, (int) $metrics['objects']['final_value']);
        $this->assertSame(0, (int) $metrics['shows']['final_value']);
        $this->assertSame(1, (int) $metrics['ads']['final_value']);
        $this->assertSame(2, (int) $metrics['calls']['final_value']);
        $this->assertSame(7, (int) $metrics['sales']['final_value']);

        $response->assertJsonPath('data.0.objects_raw', 0)
            ->assertJsonPath('data.0.shows_raw', 0)
            ->assertJsonPath('data.0.ads_raw', 1)
            ->assertJsonPath('data.0.calls_raw', 2)
            ->assertJsonPath('data.0.sales_raw', 7)
            ->assertJsonPath('data.0.objects_display', '0.00')
            ->assertJsonPath('data.0.sales_display', '7.00');
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

    public function test_rop_can_save_agent_mop_intern_in_own_branch(): void
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
        $this->patchJson('/api/kpi/plans/'.$intern->id, $payload)->assertOk();
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

    public function test_bulk_upsert_supports_scope_roles_multiselect_and_keeps_partial_success(): void
    {
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900000760', 'role_id' => $ropRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900000761', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $intern = User::create(['name' => 'Intern', 'phone' => '900000762', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

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
        ])->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.code', 'KPI_FORBIDDEN_SCOPE')
            ->assertJsonPath('results.1.details.role', 'intern');
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

    public function test_monthly_v2_uses_personal_plan_and_exposes_plan_source_per_metric(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900001901', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900001902', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);

        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 10,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);
        KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $agent->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'metric_key' => 'calls',
            'daily_plan' => 25,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/monthly?year=2026&month=5&role=agent&v=2&debug_plan_trace=1')
            ->assertOk();

        $row = collect((array) $response->json('data'))
            ->firstWhere('employee_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame('personal', (string) ($row['metrics']['calls']['plan_source'] ?? ''));
        $this->assertSame(775, (int) ($row['metrics']['calls']['target_value'] ?? 0));

        $samples = collect((array) $response->json('meta.debug.plan_source_samples'));
        $sample = $samples->first(fn (array $s) => (int) ($s['employee_id'] ?? 0) === $agent->id && (string) ($s['metric'] ?? '') === 'calls');
        $this->assertNotNull($sample);
        $this->assertSame('personal', (string) ($sample['plan_source'] ?? ''));
        $this->assertSame(25, (int) ($sample['plan_daily_value'] ?? 0));
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
        $this->assertSame(0, (int) data_get($rowNoReport, 'metrics.ads.final_value'));
        $this->assertSame(0, (int) data_get($rowNoReport, 'metrics.calls.final_value'));
        $this->assertSame(0, (int) data_get($rowNoReport, 'metrics.sales.final_value'));

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-16', 'deals_count' => 2, 'submitted_at' => now()]);
        $withReport = $this->getJson('/api/kpi/daily?v=2&date=2026-05-16&agent_id='.$agent->id)->assertOk();
        $rowWithReport = (array) $withReport->json('data.0');
        $this->assertTrue((bool) ($rowWithReport['submitted_daily_report'] ?? false));
        $this->assertSame('manual', (string) data_get($rowWithReport, 'metrics.sales.source'));
        $this->assertSame(2, (int) data_get($rowWithReport, 'metrics.sales.final_value'));
        $this->assertSame('manual', (string) data_get($rowWithReport, 'metrics.calls.source'));
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

    public function test_daily_v2_supports_mixed_source_with_manual_override_rule(): void
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
        $mapping['sales']['source_type'] = 'mixed';
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
        $this->assertSame('mixed', (string) data_get($row, 'metrics.sales.source'));
        $this->assertSame(1, (int) data_get($row, 'metrics.sales.fact_value'));
        $this->assertSame(3, (int) data_get($row, 'metrics.sales.manual_value'));
        $this->assertSame(3, (int) data_get($row, 'metrics.sales.final_value'));
    }

    public function test_weekly_v2_ads_calls_come_from_manual_daily_reports_or_zero(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002041', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002042', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-12', 'ad_count' => 10, 'calls_count' => 5, 'submitted_at' => now()]);
        DailyReport::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'report_date' => '2026-05-13', 'ad_count' => 0, 'calls_count' => 0, 'submitted_at' => now()]);

        $response = $this->getJson('/api/kpi/weekly?year=2026&week=20&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertSame('manual', (string) data_get($row, 'metrics.ads.source'));
        $this->assertSame('manual', (string) data_get($row, 'metrics.calls.source'));
        $this->assertSame(10, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(5, (int) data_get($row, 'metrics.calls.final_value'));
    }

    public function test_monthly_v2_ads_calls_are_zero_when_no_manual_reports(): void
    {
        $this->createDailyKpiSystemTables();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $admin = User::create(['name' => 'Admin', 'phone' => '900002051', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => 'Agent', 'phone' => '900002052', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/kpi/monthly?year=2026&month=5&v=2&agent_id='.$agent->id)->assertOk();
        $row = (array) collect((array) $response->json('data'))->firstWhere('employee_id', $agent->id);
        $this->assertSame(0, (int) data_get($row, 'metrics.ads.final_value'));
        $this->assertSame(0, (int) data_get($row, 'metrics.calls.final_value'));
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
