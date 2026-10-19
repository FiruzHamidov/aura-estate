<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiDailyMyReportStrictApiTest extends TestCase
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
            $t->id();
            $t->string('name');
            $t->string('phone')->unique();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->string('auth_method')->default('password');
            $t->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('role_slug')->nullable();
            $t->date('report_date');
            $t->unsignedInteger('calls_count')->default(0);
            $t->unsignedInteger('ad_count')->default(0);
            $t->unsignedInteger('meetings_count')->default(0);
            $t->unsignedInteger('shows_count')->default(0);
            $t->unsignedInteger('new_clients_count')->default(0);
            $t->unsignedInteger('new_properties_count')->default(0);
            $t->unsignedInteger('deposits_count')->default(0);
            $t->unsignedInteger('deals_count')->default(0);
            $t->decimal('sales_count', 8, 4)->default(0);
            $t->text('comment')->nullable();
            $t->text('plans_for_tomorrow')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'report_date']);
        });
        Schema::create('kpi_plans', function (Blueprint $t) {
            $t->id();
            $t->string('role_slug')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('metric_key');
            $t->decimal('daily_plan', 10, 2)->default(0);
            $t->decimal('weight', 10, 2)->default(1);
            $t->string('plan_period')->default('month');
            $t->text('comment')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('sale_user_id')->nullable();
            $t->string('moderation_status')->default('approved');
            $t->timestamp('sold_at')->nullable();
            $t->timestamps();
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->timestamp('start_time')->nullable();
            $t->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
    }

    public function test_read_and_submit_strict_daily_my_report_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 20:05:00', 'Asia/Dushanbe'));

        $rop = $this->makeUser('rop');
        Sanctum::actingAs($rop);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 7,
            'calls' => 13,
            'comment' => 'done',
            'plans_for_tomorrow' => 'more calls',
        ])->assertOk()
            ->assertJsonPath('report_date', '2026-05-05')
            ->assertJsonPath('metrics.objects.source', 'system')
            ->assertJsonPath('metrics.shows.source', 'system')
            ->assertJsonPath('metrics.ads.source', 'mixed')
            ->assertJsonPath('metrics.calls.source', 'mixed')
            ->assertJsonPath('metrics.sales.source', 'system')
            ->assertJsonPath('manual.ads', 7)
            ->assertJsonPath('manual.calls', 13)
            ->assertJsonPath('manual.comment', 'done')
            ->assertJsonPath('manual.plans_for_tomorrow', 'more calls');

        $this->getJson('/api/kpi/daily/my-report?date=2026-05-05')
            ->assertOk()
            ->assertJsonStructure([
                'report_date',
                'metrics' => ['objects', 'shows', 'ads', 'calls', 'sales'],
                'manual' => ['ads', 'calls', 'comment', 'plans_for_tomorrow'],
                'submitted',
                'submitted_at',
                'meta' => ['locked'],
            ]);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 9,
            'calls' => 14,
            'comment' => 'updated',
            'plans_for_tomorrow' => 'updated plan',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_SUBMITTED_EDIT_FORBIDDEN');

        $this->assertTrue(
            DailyReport::query()
                ->where('user_id', $rop->id)
                ->whereDate('report_date', '2026-05-05')
                ->where('ad_count', 7)
                ->where('calls_count', 13)
                ->where('comment', 'done')
                ->exists()
        );
    }

    public function test_submit_with_unknown_kpi_key_returns_kpi_validation_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));

        Sanctum::actingAs($this->makeUser('rop'));

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 1,
            'calls' => 2,
            'deals' => 3,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED')
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('details.errors.deals.0', 'This metric is not writable for this endpoint.')
            ->assertJsonStructure(['trace_id']);
    }

    public function test_today_can_be_saved_as_draft_before_evening_and_submitted_after_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));

        $agent = $this->makeUser('agent');
        Sanctum::actingAs($agent);

        $this->putJson('/api/kpi/daily/my-report/draft', [
            'report_date' => '2026-05-05',
            'ads' => 4,
            'calls' => 8,
            'comment' => 'draft',
            'plans_for_tomorrow' => 'plan',
        ])->assertOk()
            ->assertJsonPath('report_state', 'draft')
            ->assertJsonPath('can_save_draft', true)
            ->assertJsonPath('can_submit', false)
            ->assertJsonPath('submitted', false);

        $draft = DailyReport::query()->where('user_id', $agent->id)->firstOrFail();
        $this->assertNull($draft->submitted_at);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 4,
            'calls' => 8,
            'comment' => 'draft',
            'plans_for_tomorrow' => 'plan',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_REPORT_SUBMIT_TOO_EARLY')
            ->assertJsonPath('details.submit_available_at', '2026-05-05T20:00:00+05:00');

        Carbon::setTestNow(Carbon::parse('2026-05-05 20:00:00', 'Asia/Dushanbe'));

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 5,
            'calls' => 9,
            'comment' => 'final',
            'plans_for_tomorrow' => 'final plan',
        ])->assertOk()
            ->assertJsonPath('report_state', 'submitted_live')
            ->assertJsonPath('can_save_draft', false)
            ->assertJsonPath('can_submit', false)
            ->assertJsonPath('submitted', true)
            ->assertJsonPath('manual.ads', 5)
            ->assertJsonPath('manual.calls', 9);

        $this->assertSame($draft->id, DailyReport::query()->where('user_id', $agent->id)->value('id'));
    }

    public function test_submit_historical_date_and_role_restrictions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 00:01:00', 'Asia/Dushanbe'));

        Sanctum::actingAs($this->makeUser('agent'));
        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 1,
            'calls' => 2,
        ])->assertOk()
            ->assertJsonPath('report_date', '2026-05-05')
            ->assertJsonPath('manual.ads', 1)
            ->assertJsonPath('manual.calls', 2);

        $this->postJson('/api/kpi/daily/my-report', [
            'report_date' => '2026-05-05',
            'ads' => 2,
            'calls' => 3,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_SUBMITTED_EDIT_FORBIDDEN');

        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/kpi/daily/my-report?date=2026-05-06')
            ->assertOk();
    }

    public function test_my_report_without_plan_uses_the_same_system_targets_as_kpi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00', 'Asia/Dushanbe'));

        $agent = $this->makeUser('agent');
        Sanctum::actingAs($agent);

        $this->getJson('/api/kpi/daily/my-report?date=2026-05-11')
            ->assertOk()
            ->assertJsonPath('metrics.objects.target_value', 1)
            ->assertJsonPath('metrics.shows.target_value', 2)
            ->assertJsonPath('metrics.ads.target_value', 20)
            ->assertJsonPath('metrics.calls.target_value', 30)
            ->assertJsonPath('metrics.sales.target_value', 31)
            ->assertJsonPath('meta.debug.plan_resolution.employee_id', $agent->id)
            ->assertJsonPath('meta.debug.plan_resolution.date', '2026-05-11')
            ->assertJsonPath('meta.debug.plan_resolution.metrics.objects.resolved_from', 'system')
            ->assertJsonPath('meta.debug.plan_resolution.metrics.objects.target_value', 1);
    }

    public function test_my_report_returns_auto_metrics_and_debug_breakdown_for_requested_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00', 'Asia/Dushanbe'));

        $agent = $this->makeUser('agent');
        $other = $this->makeUser('agent');
        Sanctum::actingAs($agent);

        $objectId = \DB::table('properties')->insertGetId([
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'moderation_status' => 'approved',
            'created_at' => '2026-07-06 10:00:00',
            'updated_at' => '2026-07-06 10:00:00',
        ]);
        $eveningObjectId = \DB::table('properties')->insertGetId([
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'moderation_status' => 'approved',
            'created_at' => '2026-07-06 20:00:00',
            'updated_at' => '2026-07-06 20:00:00',
        ]);
        \DB::table('properties')->insert([
            'created_by' => $other->id,
            'agent_id' => $other->id,
            'moderation_status' => 'approved',
            'created_at' => '2026-07-06 10:00:00',
            'updated_at' => '2026-07-06 10:00:00',
        ]);

        $bookingId = \DB::table('bookings')->insertGetId([
            'agent_id' => $agent->id,
            'start_time' => '2026-07-06 09:00:00',
            'created_at' => '2026-07-06 09:00:00',
            'updated_at' => '2026-07-06 09:00:00',
        ]);
        \DB::table('bookings')->insert([
            'agent_id' => $other->id,
            'start_time' => '2026-07-06 09:00:00',
            'created_at' => '2026-07-06 09:00:00',
            'updated_at' => '2026-07-06 09:00:00',
        ]);

        $soldId = \DB::table('properties')->insertGetId([
            'created_by' => $other->id,
            'agent_id' => $other->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'sold',
            'sold_at' => '2026-07-06 12:00:00',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-06 12:00:00',
        ]);
        \DB::table('properties')->insert([
            'created_by' => $other->id,
            'agent_id' => $other->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'rented',
            'sold_at' => '2026-07-06 13:00:00',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-06 13:00:00',
        ]);
        \DB::table('properties')->insert([
            'created_by' => $other->id,
            'agent_id' => $other->id,
            'sale_user_id' => $agent->id,
            'moderation_status' => 'sold_by_owner',
            'sold_at' => '2026-07-06 14:00:00',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-06 14:00:00',
        ]);

        $this->getJson('/api/kpi/daily/my-report?date=2026-07-06')
            ->assertOk()
            ->assertJsonPath('report_date', '2026-07-06')
            ->assertJsonPath('metrics.objects.fact', 2)
            ->assertJsonPath('metrics.shows.fact', 1)
            ->assertJsonPath('metrics.sales.fact', 1)
            ->assertJsonPath('auto.new_properties_count', 2)
            ->assertJsonPath('auto.shows_count', 1)
            ->assertJsonPath('auto.deals_count', 1)
            ->assertJsonPath('meta.debug.auto_metrics.employee_id', $agent->id)
            ->assertJsonPath('meta.debug.auto_metrics.date', '2026-07-06')
            ->assertJsonPath('meta.debug.auto_metrics.timezone', 'Asia/Dushanbe')
            ->assertJsonPath('meta.debug.auto_metrics.object_ids', [$objectId, $eveningObjectId])
            ->assertJsonPath('meta.debug.auto_metrics.booking_ids', [$bookingId])
            ->assertJsonPath('meta.debug.auto_metrics.sales_property_ids', [$soldId])
            ->assertJsonPath('meta.debug.auto_metrics.metrics.objects.fact', 2)
            ->assertJsonPath('meta.debug.auto_metrics.metrics.shows.fact', 1)
            ->assertJsonPath('meta.debug.auto_metrics.metrics.sales.fact', 1);
    }

    public function test_old_reports_follow_live_facts_until_explicitly_frozen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 12:00:00', 'Asia/Dushanbe'));
        Schema::table('daily_reports', fn (Blueprint $t) => $t->boolean('is_finalized')->default(false));
        Schema::create('kpi_period_locks', function (Blueprint $t) {
            $t->id(); $t->string('period_type'); $t->string('period_key');
            $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
        });
        $agent = $this->makeUser('agent');
        Sanctum::actingAs($agent);
        $report = DailyReport::create([
            'user_id' => $agent->id, 'branch_group_id' => $agent->branch_group_id, 'role_slug' => 'agent',
            'report_date' => '2026-05-05', 'new_properties_count' => 17, 'calls_count' => 30,
            'submitted_at' => '2026-05-05 20:00:00',
        ]);
        \DB::table('properties')->insert([
            'created_by' => $agent->id, 'agent_id' => $agent->id, 'moderation_status' => 'approved',
            'created_at' => '2026-05-05 23:30:00', 'updated_at' => '2026-05-05 23:30:00',
        ]);
        $url = '/api/kpi/daily/my-report?date=2026-05-05';
        $this->getJson($url)->assertOk()->assertJsonPath('metrics.objects.final_value', 1)
            ->assertJsonPath('metrics.calls.final_value', 30)->assertJsonPath('metrics.calls.fact_value', 0);
        $report->update(['is_finalized' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('metrics.objects.final_value', 17);
        $report->update(['is_finalized' => false]);
        // The lock reader must refresh between requests; ISO week locks apply to a daily report.
        \DB::table('kpi_period_locks')->insert(['period_type' => 'week', 'period_key' => '2026-W19',
            'branch_id' => $agent->branch_id, 'branch_group_id' => $agent->branch_group_id]);
        $this->getJson($url)->assertOk()->assertJsonPath('metrics.objects.final_value', 17)
            ->assertJsonPath('meta.locked', true);
    }

    public function test_daily_report_and_kpi_share_source_priority_and_daily_plan_units(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 12:00:00', 'Asia/Dushanbe'));
        $agent = $this->makeUser('agent');
        Sanctum::actingAs($agent);
        Schema::create('crm_task_types', function (Blueprint $t) { $t->id(); $t->string('code'); });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('task_type_id'); $t->unsignedBigInteger('assignee_id');
            $t->string('status'); $t->timestamp('completed_at');
        });
        $type = \DB::table('crm_task_types')->insertGetId(['code' => 'CALL']);
        \DB::table('crm_tasks')->insert(['task_type_id' => $type, 'assignee_id' => $agent->id,
            'status' => 'done', 'completed_at' => '2026-05-05 10:00:00']);
        $report = DailyReport::create(['user_id' => $agent->id, 'branch_group_id' => $agent->branch_group_id,
            'role_slug' => 'agent', 'report_date' => '2026-05-05', 'calls_count' => 30]);
        \App\Models\KpiPlan::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'metric_key' => 'calls',
            'daily_plan' => 310, 'weight' => 1, 'effective_from' => '2026-05-01']);
        $daily = $this->getJson('/api/kpi/daily/my-report?date=2026-05-05')->assertOk()
            ->assertJsonPath('manual.calls', 30)->assertJsonPath('metrics.calls.final_value', 1)
            ->assertJsonPath('metrics.calls.fact_value', 1)->assertJsonPath('metrics.calls.target_value', 10);
        $kpi = app(\App\Services\KpiModuleService::class)->canonicalDailyMetricsBundle(
            $agent, '2026-05-05', $report, app(\App\Services\DailyReportService::class)->autoMetrics($agent, '2026-05-05')
        );
        $this->assertEquals($kpi['metrics'], $daily->json('metrics'));
    }

    public function test_clients_are_counted_once_and_local_model_dates_do_not_shift_at_night(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 00:30:00', 'Asia/Dushanbe'));
        $creator = $this->makeUser('agent');
        $responsible = $this->makeUser('agent');
        Schema::create('clients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('responsible_agent_id')->nullable(); $t->timestamps();
        });
        \DB::table('clients')->insert([
            ['created_by' => $creator->id, 'responsible_agent_id' => $responsible->id, 'created_at' => '2026-05-05 23:30:00'],
            ['created_by' => null, 'responsible_agent_id' => $responsible->id, 'created_at' => '2026-05-05 23:30:00'],
        ]);
        \DB::table('properties')->insert(['created_by' => $creator->id, 'agent_id' => $creator->id,
            'sale_user_id' => $creator->id, 'moderation_status' => 'sold',
            'created_at' => '2026-05-05 23:30:00', 'sold_at' => '2026-05-05 23:30:00']);
        \DB::table('bookings')->insert(['agent_id' => $creator->id, 'start_time' => '2026-05-05 19:30:00']);
        $service = app(\App\Services\DailyReportService::class);
        $yesterday = $service->autoMetrics($creator, '2026-05-05');
        $today = $service->autoMetrics($creator, '2026-05-06');
        $this->assertSame(1, $yesterday['new_clients_count']);
        $this->assertSame(1, $service->autoMetrics($responsible, '2026-05-05')['new_clients_count']);
        $this->assertSame(1, $yesterday['new_properties_count']);
        $this->assertSame(1.0, $yesterday['sales_count']);
        $this->assertSame(0, $today['new_clients_count']);
        $this->assertSame(0, $today['new_properties_count']);
        $this->assertSame(0.0, $today['sales_count']);
        $this->assertSame(0, $yesterday['shows_count']);
        $this->assertSame(1, $today['shows_count']);
    }

    public function test_daily_auto_metrics_exclude_hidden_branch_records_like_kpi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 12:00:00', 'Asia/Dushanbe'));
        Schema::table('branches', fn (Blueprint $t) => $t->boolean('is_hidden')->default(false));
        $agent = $this->makeUser('agent');
        Sanctum::actingAs($agent);
        $hidden = Branch::create(['name' => 'Hidden']);
        \DB::table('branches')->where('id', $hidden->id)->update(['is_hidden' => true]);
        foreach ([$hidden->id, $agent->branch_id] as $branchId) {
            \DB::table('properties')->insert(['created_by' => $agent->id, 'agent_id' => $agent->id,
                'branch_id' => $branchId, 'created_at' => '2026-05-05 12:00:00']);
        }
        $this->getJson('/api/kpi/daily/my-report?date=2026-05-05')->assertOk()
            ->assertJsonPath('auto.new_properties_count', 1)
            ->assertJsonPath('metrics.objects.final_value', 1);
    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);
        $branch = Branch::firstOrCreate(['name' => 'A']);
        $group = BranchGroup::firstOrCreate(['branch_id' => $branch->id, 'name' => 'A1'], ['contact_visibility_mode' => 'group_only']);

        $user = User::create([
            'name' => $roleSlug.' user',
            'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
        if ($roleSlug === 'rop') $user->supervisedGroups()->attach($group->id);
        return $user;
    }
}
