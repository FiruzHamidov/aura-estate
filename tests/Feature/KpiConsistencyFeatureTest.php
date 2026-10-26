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

class KpiConsistencyFeatureTest extends TestCase
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

    private function employeeReportProperties(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable(); $table->unsignedBigInteger('sale_agent_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable(); $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable(); $table->string('moderation_status')->nullable();
            $table->string('offer_type')->default('sale'); $table->integer('rooms')->nullable();
            $table->timestamp('sold_at')->nullable(); $table->timestamp('deposit_received_at')->nullable(); $table->timestamps();
        });
    }

    public function test_unified_employee_report_counts_same_complete_rows_and_preserves_canonical_kpi(): void
    {
        [, $author, $branch, $group] = $this->fixture();
        $seller = User::create(['name' => 'Seller', 'phone' => '900990003', 'role_id' => $author->role_id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $this->employeeReportProperties();
        $this->report($author, '2026-10-10', ['calls_count' => 30]);
        $this->report($author, '2026-10-11', ['calls_count' => 9]);
        $this->completedCall($author, '2026-10-11');
        foreach ([
            ['moderation_status' => 'approved', 'contract_type_id' => 1],
            ['moderation_status' => 'deleted', 'contract_type_id' => null],
            ['moderation_status' => 'sold', 'sale_agent_id' => $seller->id, 'sold_at' => '2026-10-12 10:00:00', 'created_at' => '2026-08-01 10:00:00', 'contract_type_id' => 2],
            ['moderation_status' => 'rented', 'sale_agent_id' => $seller->id, 'sold_at' => '2026-10-12 10:00:00', 'created_at' => '2026-08-01 10:00:00', 'contract_type_id' => 3],
            ['moderation_status' => 'deposit', 'deposit_received_at' => '2026-10-12 10:00:00', 'created_at' => '2026-08-01 10:00:00', 'contract_type_id' => 1],
            ['moderation_status' => 'sold', 'sale_agent_id' => $seller->id, 'sold_at' => null],
        ] as $values) {
            \DB::table('properties')->insert($values + ['created_by' => $author->id, 'agent_id' => $author->id,
                'created_at' => '2026-10-10 10:00:00', 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        }
        $url = '/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31&interval=month';
        $result = $this->getJson($url.'&per_page=1&page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.employees_count', 2)->assertJsonPath('totals.total_properties', 5)
            ->assertJsonPath('totals.objects', 3)->assertJsonPath('totals.calls', 31)
            ->assertJsonPath('totals.sales', 1)->assertJsonPath('totals.rented', 1)
            ->assertJsonPath('totals.by_status.deleted', 1)->assertJsonPath('totals.contracts.unspecified', 1);
        $canonical = $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10')->assertOk();
        foreach ($result->json('data') as $row) {
            $expected = collect($canonical->json('data'))->firstWhere('employee_id', $row['employee_id']);
            foreach (['objects', 'shows', 'clients', 'calls', 'ads', 'sales', 'kpi_percent'] as $key) $this->assertEquals($expected[$key], $row[$key]);
            $this->assertSame($row['total_properties'], array_sum($row['by_status']));
            $this->assertSame($row['total_properties'], array_sum($row['contracts']));
        }
        $this->getJson($url.'&agent_id='.$seller->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.total_properties', 2)->assertJsonPath('totals.sales', 1)->assertJsonPath('totals.rented', 1);
        $this->getJson($url.'&moderation_status=approved')->assertOk()
            ->assertJsonPath('totals.total_properties', 1)->assertJsonPath('totals.sales', 0)->assertJsonPath('totals.calls', 31);
        $this->getJson('/api/reports/employees')->assertOk()->assertJsonPath('totals.total_properties', 6)
            ->assertJsonPath('totals.sales', 2)->assertJsonPath('meta.quality.undated_sales_count', 1);
    }

    public function test_unified_report_uses_dushanbe_boundaries_and_keeps_zero_activity_people(): void
    {
        [, $agent, $branch, $group] = $this->fixture();
        Config::set('app.timezone', 'UTC');
        $this->employeeReportProperties();
        User::create(['name' => 'Zero', 'phone' => '900990003', 'role_id' => $agent->role_id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        foreach (['2026-09-30 18:59:59', '2026-09-30 19:00:00', '2026-10-31 18:59:59', '2026-10-31 19:00:00'] as $time) {
            \DB::table('properties')->insert(['moderation_status' => 'approved', 'created_by' => $agent->id, 'agent_id' => $agent->id, 'created_at' => $time]);
        }
        $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('totals.total_properties', 2)->assertJsonPath('totals.objects', 2);
        $this->getJson('/api/reports/employees?date_from=2026-10-31&date_to=2026-10-01')->assertUnprocessable();
    }

    public function test_unified_report_merges_former_employee_contexts_without_repeating_targets(): void
    {
        [, $agent, $branch, $oldGroup] = $this->fixture();
        $this->employeeReportProperties();
        $secondGroup = BranchGroup::create(['name' => 'Second', 'branch_id' => $branch->id]);
        $newGroup = BranchGroup::create(['name' => 'New', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'Supervisor', 'phone' => '900990009', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach([$oldGroup->id, $secondGroup->id]);
        $this->report($agent, '2026-10-10', ['calls_count' => 8]);
        $agent->update(['branch_group_id' => $secondGroup->id]);
        $this->report($agent, '2026-10-11', ['calls_count' => 9]);
        $agent->update(['branch_group_id' => $newGroup->id]);
        $this->report($agent, '2026-10-12', ['calls_count' => 100]);
        Sanctum::actingAs($rop);
        $result = $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31&interval=month&agent_id='.$agent->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.calls', 17)->assertJsonPath('totals.calls', 17);
        $this->assertEquals(31 * config('kpi.v2.targets.calls'), $result->json('data.0.metrics.calls.target_value'));
        $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31&interval=month&branch_group_id='.$oldGroup->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.calls', 8);
        $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31&branch_group_id='.$newGroup->id)->assertForbidden();
        $agent->update(['status' => 'inactive']);
        $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unified_report_denies_unauthorized_reader_and_inactive_scope(): void
    {
        [, $agent] = $this->fixture();
        $this->employeeReportProperties();
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        $agent->update(['role_id' => $role->id]);
        Sanctum::actingAs($agent->fresh());
        $this->getJson('/api/reports/employees')->assertForbidden();
    }

    public function test_unified_report_respects_branch_hidden_and_inactive_scopes(): void
    {
        [$admin, $agent, $branch, $group] = $this->fixture();
        $this->employeeReportProperties();
        Schema::table('branches', fn (Blueprint $table) => $table->boolean('is_hidden')->default(false));
        $foreign = Branch::create(['name' => 'Other']);
        $hidden = Branch::create(['name' => 'Hidden']);
        \DB::table('branches')->where('id', $hidden->id)->update(['is_hidden' => true]);
        foreach ([[$foreign, 'active'], [$hidden, 'active'], [$branch, 'inactive']] as $index => [$location, $status]) {
            User::create(['name' => 'Employee '.$index, 'phone' => '90099001'.$index, 'role_id' => $agent->role_id,
                'branch_id' => $location->id, 'branch_group_id' => $location->id === $branch->id ? $group->id : null, 'status' => $status]);
        }
        $url = '/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'&include_inactive=1')->assertOk()->assertJsonCount(3, 'data');
        $role = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $admin->update(['role_id' => $role->id]);
        Sanctum::actingAs($admin->fresh());
        $response = $this->getJson($url)->assertOk();
        $this->assertEquals([$agent->id], collect($response->json('data'))->pluck('employee_id')->all());
        $this->getJson($url.'&branch_id='.$foreign->id)->assertForbidden();
    }

    public function test_unified_report_does_not_include_people_without_targets_in_average(): void
    {
        [, $agent] = $this->fixture();
        $this->employeeReportProperties();
        Config::set('kpi.v2.targets', array_fill_keys(['objects', 'shows', 'ads', 'calls', 'sales'], 0));
        $this->getJson('/api/reports/employees?date_from=2026-10-01&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('data.0.status', 'no_plan')->assertJsonPath('data.0.kpi_percent', null)
            ->assertJsonPath('totals.kpi_percent', null)->assertJsonPath('meta.scored_employees_count', 0)
            ->assertJsonPath('totals.calls', 0);
    }

    public static function alignedReportCases(): array
    {
        $cases = [];
        foreach (['day', 'week', 'next_week', 'month', 'all'] as $period) {
            foreach (['all', 'first', 'second'] as $group) {
                foreach ([false, true] as $inactive) {
                    foreach (['Asia/Dushanbe', 'UTC'] as $timezone) {
                        $cases[$period.'-'.$group.'-'.(int) $inactive.'-'.$timezone] = [$period, $group, $inactive, $timezone];
                    }
                }
            }
        }
        return $cases;
    }

    #[DataProvider('alignedReportCases')]
    public function test_cards_employees_and_drilldown_share_dates_groups_and_inactive_filter(string $period, string $groupFilter, bool $inactive, string $timezone): void
    {
        [$admin, $agent, $branch, $group] = $this->fixture();
        Config::set('app.timezone', $timezone);
        $this->employeeReportProperties();
        $other = BranchGroup::create(['name' => 'Other group', 'branch_id' => $branch->id]);
        $former = User::create(['name' => 'Former', 'phone' => '900990099', 'role_id' => $agent->role_id,
            'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'status' => 'inactive']);
        $admin->update(['name' => 'Office']); // A real owner outside the KPI subject roster.
        $storage = fn ($time) => \Illuminate\Support\Carbon::parse($time, 'Asia/Dushanbe')->setTimezone($timezone)->toDateTimeString();
        $records = [
            1 => ['approved', $agent->id, $group->id, '2026-10-17 00:00:00'],
            2 => ['sold', $agent->id, $group->id, '2026-10-20 12:00:00'],
            3 => ['sold', $agent->id, $other->id, '2026-10-20 12:00:00'],
            4 => ['approved', $former->id, $group->id, '2026-10-17 12:00:00'],
            5 => ['rented', $former->id, $group->id, '2026-10-20 12:00:00'],
            6 => ['sold_by_owner', $admin->id, $group->id, '2026-10-20 12:00:00'],
            7 => ['deposit', $agent->id, $group->id, '2026-10-20 12:00:00'],
            8 => ['deleted', $agent->id, $group->id, '2026-10-17 12:00:00'],
            10 => [null, $agent->id, $group->id, '2026-10-17 12:00:00'],
            11 => ['sold', null, $group->id, '2026-10-20 12:00:00'],
            12 => ['approved', $agent->id, $group->id, '2026-10-16 23:59:59'],
            13 => ['approved', $agent->id, $group->id, '2026-10-24 23:59:59'],
            14 => ['approved', $agent->id, $group->id, '2026-10-25 00:00:00'],
        ];
        foreach ($records as $id => [$status, $owner, $groupId, $event]) {
            $closed = in_array($status, ['sold', 'rented', 'sold_by_owner'], true);
            \DB::table('properties')->insert(['id' => $id, 'moderation_status' => $status,
                'created_by' => $owner, 'agent_id' => $owner, 'sale_agent_id' => $closed ? $owner : null,
                'branch_id' => $branch->id, 'branch_group_id' => $groupId, 'rooms' => 2,
                'created_at' => $storage($closed || $status === 'deposit' ? '2026-09-01 12:00:00' : $event),
                'sold_at' => $closed ? $storage($event) : null,
                'deposit_received_at' => $status === 'deposit' ? $storage($event) : null]);
        }
        $expected = match ($period) {
            'day' => [1, 4, 8, 10],
            'week' => [1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 13],
            'next_week' => [2, 3, 5, 6, 7, 11, 13, 14],
            default => array_keys($records),
        };
        if (! $inactive) $expected = array_values(array_diff($expected, [4, 5]));
        if ($groupFilter === 'first') $expected = array_values(array_diff($expected, [3]));
        if ($groupFilter === 'second') $expected = array_values(array_intersect($expected, [3]));
        $query = ['include_inactive' => (int) $inactive];
        if ($period !== 'all') {
            [$query['date_from'], $query['date_to']] = match ($period) {
                'month' => ['2026-10-01', '2026-10-31'],
                'day' => ['2026-10-17', '2026-10-17'], 'week' => ['2026-10-17', '2026-10-24'],
                default => ['2026-10-19', '2026-10-25'],
            };
        }
        if ($groupFilter !== 'all') $query['branch_group_id'] = $groupFilter === 'first' ? $group->id : $other->id;
        $queryString = '?'.http_build_query($query);
        $report = $this->getJson('/api/reports/employees'.$queryString)->assertOk()
            ->assertJsonPath('totals.total_properties', count($expected))->json();
        $this->getJson('/api/reports/properties/summary'.$queryString)->assertOk()->assertExactJson($report['summary']);
        $this->getJson('/api/properties/count'.$queryString.'&report_context=1')->assertOk()->assertJsonPath('count', count($expected));
        $this->assertSame(count($expected), $report['summary']['total']);
        $this->assertSame(count($expected), array_sum(array_column($report['rooms'], 'cnt')));
        $this->assertEqualsCanonicalizing($expected, collect($report['data'])->pluck('properties')->flatten(1)->pluck('id')->all());
        foreach ($report['data'] as $row) {
            $this->assertSame($row['total_properties'], count($row['properties']));
            $this->assertSame($row['total_properties'], array_sum($row['by_status']));
            $this->assertSame($row['sales'], $row['by_status']['sold']);
            if ($row['employee_id'] === $admin->id || $row['employee_id'] === 0) $this->assertNull($row['kpi_percent']);
        }
        foreach (['unknown', 'deposit', 'deleted', 'sold', 'rented', 'sold_by_owner', 'approved'] as $status) {
            $this->getJson('/api/properties/count'.$queryString.'&report_context=1&moderation_status='.$status)
                ->assertOk()->assertJsonPath('count', $report['totals']['by_status'][$status]);
        }
    }

    public function test_report_branch_owner_and_explicit_sale_dates_match_details(): void
    {
        [, $author, $branch, $group] = $this->fixture();
        $this->employeeReportProperties();
        $otherBranch = Branch::create(['name' => 'Other']);
        $seller = User::create(['name' => 'Seller', 'phone' => '900990003', 'role_id' => $author->role_id,
            'branch_id' => $otherBranch->id]);
        foreach (['2026-10-17 23:59:59', '2026-10-18 00:00:00', '2026-10-23 23:59:59', '2026-10-24 00:00:00'] as $id => $soldAt) {
            \DB::table('properties')->insert(['id' => $id + 1, 'created_by' => $author->id, 'sale_agent_id' => $seller->id,
                'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'moderation_status' => 'sold',
                'created_at' => '2026-09-01 12:00:00', 'sold_at' => $soldAt]);
        }
        $range = '?date_from=2026-10-01&date_to=2026-10-31&sold_at_from=2026-10-18&sold_at_to=2026-10-23';
        foreach (['&branch_id='.$otherBranch->id => 2, '&branch_id='.$branch->id => 0,
            '&agent_id='.$seller->id => 2, '&agent_id='.$author->id => 0] as $filter => $count) {
            $this->getJson('/api/reports/employees'.$range.$filter)->assertOk()
                ->assertJsonPath('summary.total', $count)->assertJsonPath('totals.sales', $count);
            $this->getJson('/api/properties/count'.$range.$filter.'&report_context=1')->assertOk()->assertJsonPath('count', $count);
        }
    }

    public function test_group_scoped_drilldown_includes_deleted_and_unknown_but_excludes_foreign_group(): void
    {
        [$actor, $agent, $branch, $group] = $this->fixture();
        $this->employeeReportProperties();
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        $actor->role->forceFill(['permissions' => ['property_reports.view', 'property_reports.scope.group',
            'properties.list.scope.group', 'properties.internal.read']])->save();
        $other = BranchGroup::create(['name' => 'Other', 'branch_id' => $branch->id]);
        foreach ([['deleted', $group->id], [null, $group->id], ['approved', $other->id]] as [$status, $groupId]) {
            \DB::table('properties')->insert(['created_by' => $agent->id, 'agent_id' => $agent->id,
                'branch_id' => $branch->id, 'branch_group_id' => $groupId, 'moderation_status' => $status,
                'created_at' => '2026-10-20 12:00:00']);
        }
        Sanctum::actingAs($actor->fresh());
        $this->getJson('/api/reports/employees')->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('totals.total_properties', 2)
            ->assertJsonPath('totals.kpi_percent', null)->assertJsonPath('totals.calls', null);
        $this->getJson('/api/properties/count?report_context=1')->assertOk()->assertJsonPath('count', 2);
        $this->getJson('/api/properties/count')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/properties/count?report_context=1&moderation_status=__no_match__')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/properties/count?report_context=1&branch_group_id='.$other->id)->assertOk()->assertJsonPath('count', 0);
    }

    private function fixture(string $name = 'Agent'): array
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $agentRole = Role::firstOrCreate(['slug' => 'agent'], ['name' => 'Agent']);
        $branch = Branch::create(['name' => 'Main']);
        $group = BranchGroup::create(['name' => 'G', 'branch_id' => $branch->id]);
        $admin = User::create(['name' => 'Admin', 'phone' => '900990001', 'role_id' => $adminRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $agent = User::create(['name' => $name, 'phone' => '900990002', 'role_id' => $agentRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        Sanctum::actingAs($admin);
        return [$admin, $agent, $branch, $group];
    }

    private function plan(User $agent, string $key, float $target, float $weight, string $from, ?string $to = null): void
    {
        KpiPlan::create(['user_id' => $agent->id, 'role_slug' => 'agent', 'branch_id' => $agent->branch_id,
            'branch_group_id' => $agent->branch_group_id, 'metric_key' => $key, 'daily_plan' => $target,
            'weight' => $weight, 'effective_from' => $from, 'effective_to' => $to]);
    }

    private function report(User $agent, string $date, array $values = []): DailyReport
    {
        return DailyReport::create($values + ['user_id' => $agent->id, 'branch_group_id' => $agent->branch_group_id, 'role_slug' => 'agent', 'report_date' => $date]);
    }

    private function completedCall(User $agent, string $date): void
    {
        $type = CrmTaskType::firstOrCreate(['code' => 'CALL'], ['name' => 'Call']);
        CrmTask::create(['task_type_id' => $type->id, 'assignee_id' => $agent->id, 'title' => 'Call', 'status' => 'done', 'completed_at' => $date.' 10:00:00']);
    }

    public function test_mixed_sources_sum_daily_choices_and_include_days_without_reports(): void
    {
        [, $agent] = $this->fixture();
        $this->report($agent, '2026-10-01', ['calls_count' => 30]);
        $this->report($agent, '2026-10-02', ['calls_count' => 9]);
        $this->completedCall($agent, '2026-10-02');
        $this->completedCall($agent, '2026-10-03');
        $response = $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10&include_breakdown=1')->assertOk();
        $response->assertJsonPath('data.0.metrics.calls.final_value', 32)
            ->assertJsonPath('data.0.metrics.calls.fact_value', 2)
            ->assertJsonPath('data.0.metrics.calls.manual_value', 39)
            ->assertJsonPath('meta.totals.calls', 32)->assertJsonCount(3, 'data.0.breakdown_by_day');
        $this->assertEquals(32, collect($response->json('data.0.breakdown_by_day'))->sum('metrics.calls.final_value'));
    }

    public function test_targets_follow_mid_month_and_cross_month_effective_plans(): void
    {
        [, $agent] = $this->fixture();
        $this->plan($agent, 'calls', 300, .2, '2026-09-01', '2026-09-30');
        $this->plan($agent, 'calls', 310, .2, '2026-10-01', '2026-10-15');
        $this->plan($agent, 'calls', 620, .2, '2026-10-16', '2026-10-31');
        $this->getJson('/api/kpi/daily?v=2&date=2026-10-20')->assertOk()->assertJsonPath('data.0.metrics.calls.target_value', 20);
        $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10')->assertOk()->assertJsonPath('data.0.metrics.calls.target_value', 470);
        $this->getJson('/api/kpi/weekly?v=2&date_from=2026-09-01&date_to=2026-10-31')->assertOk()->assertJsonPath('data.0.metrics.calls.target_value', 770);
    }

    public function test_each_metric_inherits_target_and_weight_independently(): void
    {
        [, $agent, $branch, $group] = $this->fixture();
        KpiPlan::create(['role_slug' => 'agent', 'branch_id' => $branch->id, 'branch_group_id' => $group->id, 'metric_key' => 'objects', 'daily_plan' => 31, 'weight' => .2, 'effective_from' => '2026-10-01']);
        KpiPlan::create(['role_slug' => 'agent', 'branch_id' => $branch->id, 'metric_key' => 'calls', 'daily_plan' => 310, 'weight' => .6, 'effective_from' => '2026-10-01']);
        $response = $this->getJson('/api/kpi/daily?v=2&date=2026-10-10')->assertOk()
            ->assertJsonPath('data.0.metrics.calls.target_value', 10)
            ->assertJsonPath('data.0.metrics.calls.plan_source', 'common')
            ->assertJsonPath('data.0.metrics.shows.target_value', 2);
        $this->assertEqualsWithDelta(.6 / 1.4, $response->json('data.0.metrics.calls.weight_used'), .00001);
        $this->assertEqualsWithDelta(1, collect($response->json('data.0.metrics'))->sum('weight_used'), .00001);
    }

    public function test_custom_weights_change_score_and_explicit_zero_weight_is_respected(): void
    {
        [, $agent] = $this->fixture();
        foreach (['objects' => .0, 'shows' => .1, 'ads' => .1, 'calls' => .7, 'sales' => .1] as $key => $weight) {
            $this->plan($agent, $key, 31, $weight, '2026-10-01');
        }
        $this->report($agent, '2026-10-10', ['calls_count' => 1]);
        $this->getJson('/api/kpi/daily?v=2&date=2026-10-10')->assertOk()
            ->assertJsonPath('data.0.metrics.calls.weight_used', .7)
            ->assertJsonPath('data.0.metrics.objects.weight_used', 0)
            ->assertJsonPath('data.0.kpi_percent', 70);
    }

    public function test_totals_and_monthly_ranking_are_global_and_roles_filter_before_pagination(): void
    {
        [, $weak, $branch, $group] = $this->fixture('A weak');
        $strong = User::create(['name' => 'Z strong', 'phone' => '900990003', 'role_id' => $weak->role_id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $intern = User::create(['name' => 'AAA hidden', 'phone' => '900990004', 'role_id' => $internRole->id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
        foreach ([$weak, $strong, $intern] as $index => $user) $this->report($user, '2026-10-10', ['calls_count' => [10, 100, 1000][$index]]);
        $first = $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10&per_page=1&page=1')->assertOk()
            ->assertJsonPath('data.0.employee_id', $strong->id)->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.totals.calls', 110)->assertJsonPath('meta.totals.employees_count', 2);
        $second = $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.employee_id', $weak->id);
        $this->assertSame($first->json('meta.totals'), $second->json('meta.totals'));
        $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10&roles[]=mop')->assertOk()
            ->assertJsonPath('meta.pagination.total', 0)->assertJsonPath('meta.totals.calls', 0);
    }

    public function test_daily_report_daily_table_weekly_daily_and_dashboard_share_metrics(): void
    {
        [, $agent] = $this->fixture();
        $this->report($agent, '2026-10-10', ['calls_count' => 30]);
        $this->completedCall($agent, '2026-10-10');
        $daily = $this->getJson('/api/kpi/daily?v=2&date=2026-10-10')->assertOk();
        $weeklyDaily = $this->getJson('/api/kpi/weekly-daily?day=2026-10-10')->assertOk();
        $dashboard = $this->getJson('/api/kpi/dashboard?date=2026-10-10')->assertOk();
        $report = $this->getJson('/api/kpi/daily/report?date=2026-10-10&employee_id='.$agent->id)->assertOk();
        foreach (['objects', 'shows', 'ads', 'calls', 'sales'] as $key) {
            foreach (['final_value', 'target_value', 'weight_used'] as $field) {
                $expected = $daily->json('data.0.metrics.'.$key.'.'.$field);
                $this->assertEquals($expected, $weeklyDaily->json('data.0.metrics.'.$key.'.'.$field));
                $this->assertEquals($expected, $dashboard->json('data.ranking.0.metrics.'.$key.'.'.$field));
                $this->assertEquals($expected, $report->json('metrics.'.$key.'.'.$field));
            }
        }
        $this->assertEquals($daily->json('meta.totals'), $dashboard->json('data.summary.totals'));
    }

    public function test_explicitly_finalized_report_keeps_the_same_snapshot_in_all_kpi_views(): void
    {
        [, $agent] = $this->fixture();
        Schema::table('daily_reports', fn (Blueprint $table) => $table->boolean('is_finalized')->default(false));
        $this->report($agent, '2026-10-10', ['new_properties_count' => 17, 'calls_count' => 30, 'is_finalized' => true]);
        $this->completedCall($agent, '2026-10-10');
        $this->getJson('/api/kpi/daily?v=2&date=2026-10-10')->assertOk()
            ->assertJsonPath('data.0.metrics.objects.final_value', 17)->assertJsonPath('data.0.metrics.calls.final_value', 30);
        $this->getJson('/api/kpi/monthly?v=2&year=2026&month=10')->assertOk()
            ->assertJsonPath('data.0.metrics.objects.final_value', 17)->assertJsonPath('data.0.metrics.calls.final_value', 30);
    }
    public function test_global_totals_for_53_employees_keep_source_queries_bounded(): void
    {
        [, $agent, $branch, $group] = $this->fixture();
        $url = '/api/kpi/monthly?v=2&year=2026&month=10&per_page=50';
        $this->getJson($url)->assertOk();
        \DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $baseline = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        foreach (range(1, 52) as $index) {
            $extra = User::create(['name' => 'Extra '.$index, 'phone' => '901'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'role_id' => $agent->role_id, 'branch_id' => $branch->id, 'branch_group_id' => $group->id]);
            $this->report($extra, '2026-10-10', ['calls_count' => 1]);
        }
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->getJson($url)->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.pagination.total', 53)->assertJsonPath('meta.totals.calls', 52);
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();
        $this->assertLessThanOrEqual($baseline + 10, count($queries), 'Global totals must preload sources/plans instead of querying per employee or day.');
    }

    public function test_all_period_includes_imported_old_facts_and_explicitly_reports_undated_sales(): void
    {
        [$admin, $agent] = $this->fixture();
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable(); $table->string('moderation_status'); $table->timestamp('sold_at')->nullable(); $table->timestamps();
        });
        \DB::table('properties')->insert([
            ['created_by' => $agent->id, 'agent_id' => $agent->id, 'sale_user_id' => $agent->id, 'moderation_status' => 'sold', 'sold_at' => '2024-01-02 23:30:00', 'created_at' => '2024-01-01 23:30:00'],
            ['created_by' => $agent->id, 'agent_id' => $agent->id, 'sale_user_id' => $agent->id, 'moderation_status' => 'sold', 'sold_at' => null, 'created_at' => '2024-01-01 23:30:00'],
        ]);
        $this->getJson('/api/kpi/weekly?v=2&all_period=1&include_breakdown=1')->assertOk()
            ->assertJsonPath('meta.date_from', '2024-01-01')->assertJsonPath('meta.totals.objects', 2)
            ->assertJsonPath('meta.totals.sales', 2)->assertJsonPath('meta.quality.undated_sales_count', 1)
            ->assertJsonCount(2, 'data.0.breakdown_by_day');
        $this->getJson('/api/kpi/weekly?v=2&date_from=2024-01-01&date_to=2024-01-31')->assertOk()
            ->assertJsonPath('meta.totals.sales', 1)->assertJsonPath('meta.quality.undated_sales_count', 0);
        $this->getJson('/api/kpi/daily?v=2&date=2024-01-01')->assertOk()->assertJsonPath('meta.totals.objects', 2);
        $this->getJson('/api/kpi/daily?v=2&date=2024-01-02')->assertOk()->assertJsonPath('meta.totals.sales', 1);
    }

    public function test_global_iso_week_lock_is_consistent_on_row_and_meta(): void
    {
        [$admin, $agent] = $this->fixture();
        \App\Models\KpiPeriodLock::create(['period_type' => 'week', 'period_key' => '2026-W41', 'locked_by' => $admin->id, 'locked_at' => now()]);
        foreach (['', '&branch_id='.$agent->branch_id.'&branch_group_id='.$agent->branch_group_id] as $scope) {
            $this->getJson('/api/kpi/weekly?v=2&day=2026-10-10'.$scope)->assertOk()
                ->assertJsonPath('meta.locked', true)->assertJsonPath('data.0.locked', true);
        }
    }
    public function test_former_team_daily_and_monthly_rows_use_the_same_saved_facts(): void
    {
        [, $agent, $branch, $oldGroup] = $this->fixture();
        $newGroup = BranchGroup::create(['name' => 'New group', 'branch_id' => $branch->id]);
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $rop = User::create(['name' => 'Old supervisor', 'phone' => '900990009', 'role_id' => $role->id, 'branch_id' => $branch->id]);
        $rop->supervisedGroups()->attach($oldGroup->id);
        $this->report($agent, '2026-10-10', ['calls_count' => 8, 'new_properties_count' => 17]);
        $agent->update(['branch_group_id' => $newGroup->id]);
        $type = CrmTaskType::create(['code' => 'CALL', 'name' => 'Call']);
        \DB::table('crm_tasks')->insert(['task_type_id' => $type->id, 'assignee_id' => $agent->id,
            'branch_group_id' => $oldGroup->id, 'title' => 'Later CRM correction', 'status' => 'done', 'completed_at' => '2026-10-10 10:00:00']);
        Sanctum::actingAs($rop);
        foreach (['/api/kpi/daily?v=2&date=2026-10-10', '/api/kpi/monthly?v=2&year=2026&month=10'] as $url) {
            $this->getJson($url.'&user_id='.$agent->id)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.metrics.calls.final_value', 8)
                ->assertJsonPath('data.0.metrics.objects.final_value', 17);
        }
        $this->getJson('/api/kpi/daily/report?date=2026-10-10&employee_id='.$agent->id)->assertOk()
            ->assertJsonPath('metrics.calls.final_value', 8)->assertJsonPath('metrics.objects.final_value', 17);
    }
    public function test_transferred_employee_uses_report_date_plan_context_in_every_daily_view(): void
    {
        [, $agent, $branch, $oldGroup] = $this->fixture();
        $newGroup = BranchGroup::create(['name' => 'New', 'branch_id' => $branch->id]);
        foreach ([[$oldGroup, 31], [$newGroup, 62]] as [$group, $target]) {
            KpiPlan::create(['role_slug' => 'agent', 'branch_id' => $branch->id, 'branch_group_id' => $group->id,
                'metric_key' => 'objects', 'daily_plan' => $target, 'weight' => .2, 'effective_from' => '2026-10-01']);
        }
        $this->report($agent, '2026-10-10', ['new_properties_count' => 17]);
        $agent->update(['branch_group_id' => $newGroup->id]);
        $this->getJson('/api/kpi/daily/report?date=2026-10-10&employee_id='.$agent->id)->assertOk()
            ->assertJsonPath('metrics.objects.target_value', 1)->assertJsonPath('metrics.objects.final_value', 17);
        $this->getJson('/api/kpi/daily/my-progress?date=2026-10-10&user_id='.$agent->id)->assertOk()
            ->assertJsonPath('metrics.objects.target_value', 1)->assertJsonPath('metrics.objects.final_value', 17);
        $this->getJson('/api/kpi/daily?v=2&date=2026-10-10&user_id='.$agent->id)->assertOk()
            ->assertJsonPath('data.0.metrics.objects.target_value', 1)->assertJsonPath('data.0.metrics.objects.final_value', 17);
        $this->getJson('/api/kpi/daily?v=2&date=2026-10-11&user_id='.$agent->id)->assertOk()
            ->assertJsonPath('data.0.metrics.objects.target_value', 2);
    }
    public function test_daily_report_list_batches_live_sources_and_matches_single_day_metrics(): void
    {
        [, $agent] = $this->fixture();
        $this->report($agent, '2026-10-01', ['calls_count' => 30]);
        $url = '/api/daily-reports?per_page=50';
        $this->getJson($url)->assertOk();
        \DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $baseline = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        for ($offset = 1; $offset < 50; $offset++) {
            $date = \Carbon\Carbon::parse('2026-10-01')->subDays($offset)->toDateString();
            $this->report($agent, $date, ['calls_count' => 9]);
        }
        $this->completedCall($agent, '2026-09-30');
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $list = $this->getJson($url)->assertOk()->assertJsonCount(50, 'data');
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $this->assertLessThanOrEqual($baseline + 10, $queryCount, 'Daily report lists must preload live facts, scopes and plans.');
        foreach (['2026-10-01', '2026-09-30', '2026-09-29'] as $date) {
            $row = collect($list->json('data'))->firstWhere('report_date', $date);
            $daily = $this->getJson('/api/kpi/daily?v=2&date='.$date.'&user_id='.$agent->id)->assertOk();
            foreach (['objects', 'shows', 'ads', 'calls', 'sales'] as $metric) {
                $this->assertEquals($daily->json('data.0.metrics.'.$metric.'.final_value'), $row['metrics'][$metric]['final_value']);
                $this->assertEquals($daily->json('data.0.metrics.'.$metric.'.target_value'), $row['metrics'][$metric]['target_value']);
            }
        }
    }
}
