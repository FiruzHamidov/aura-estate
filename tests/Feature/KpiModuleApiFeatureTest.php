<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\CrmTask;
use App\Models\CrmTaskType;
use App\Models\DailyReport;
use App\Models\KpiEarlyRiskAlert;
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

        Schema::create('kpi_plans', function (Blueprint $t) { $t->id(); $t->string('role_slug',64); $t->string('metric_key',64); $t->decimal('daily_plan',14,4)->default(0); $t->decimal('weight',8,4)->default(0); $t->string('comment',500)->nullable(); $t->timestamps(); });
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
        $this->patchJson('/api/kpi-plans', ['role' => 'mop', 'items' => [['metric_key' => 'calls_count', 'daily_plan' => 10, 'weight' => 0.2, 'comment' => 'x']]])->assertOk();
        $this->getJson('/api/kpi/daily?date=2026-05-04')->assertOk();
        $this->getJson('/api/kpi/daily?date=2026-05-04&v=2')->assertOk()->assertJsonStructure([
            'data',
            'meta' => ['period_type', 'quality' => ['duplicate_check_passed', 'completeness_pct', 'source_error']],
        ])->assertJsonPath('meta.period_type', 'day');
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
            ->assertJsonPath('data.0.call', 2)
            ->assertJsonPath('data.0.show', 4)
            ->assertJsonPath('data.0.lead', 5)
            ->assertJsonPath('data.0.deal', 7)
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
}
