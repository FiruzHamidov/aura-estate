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
        $this->assertSame(0, (int) $metrics['sales']['final_value']);

        $response->assertJsonPath('data.0.objects_raw', 0)
            ->assertJsonPath('data.0.shows_raw', 0)
            ->assertJsonPath('data.0.ads_raw', 1)
            ->assertJsonPath('data.0.calls_raw', 2)
            ->assertJsonPath('data.0.sales_raw', 0)
            ->assertJsonPath('data.0.objects_display', '0.00')
            ->assertJsonPath('data.0.sales_display', '0.00');
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

    public function test_mop_forbidden_outside_group_and_for_mop_target(): void
    {
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group1 = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G1']);
        $group2 = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'G2']);

        $mop = User::create(['name' => 'MOP1', 'phone' => '900000411', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group1->id]);
        $otherMop = User::create(['name' => 'MOP2', 'phone' => '900000412', 'role_id' => $mopRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group1->id]);
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

        $this->patchJson('/api/kpi/plans/'.$otherMop->id, $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');
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
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');
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
}
