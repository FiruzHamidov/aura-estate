<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiReportFeatureTest extends TestCase
{
    private int $phoneCounter = 960000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('contact_visibility_mode')->default(BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug')->nullable();
            $table->date('report_date');
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('ad_count')->default(0);
            $table->unsignedInteger('meetings_count')->default(0);
            $table->unsignedInteger('shows_count')->default(0);
            $table->unsignedInteger('new_clients_count')->default(0);
            $table->unsignedInteger('new_properties_count')->default(0);
            $table->unsignedInteger('deposits_count')->default(0);
            $table->unsignedInteger('deals_count')->default(0);
            $table->text('comment')->nullable();
            $table->text('plans_for_tomorrow')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_branch_director_sees_only_own_branch_kpi_rows(): void
    {
        [$users] = $this->seedContext();

        Sanctum::actingAs($users['directorA']);

        $response = $this->getJson('/api/kpi-reports?period_type=day&date_from=2026-05-01&date_to=2026-05-01');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);

        foreach ($data as $row) {
            $this->assertSame(1, $row['user']['branch_id']);
            $this->assertArrayHasKey('metrics', $row);
            $this->assertArrayHasKey('calls_count', $row['metrics']);
            $this->assertArrayHasKey('fact_value', $row['metrics']['calls_count']);
            $this->assertArrayHasKey('target_value', $row['metrics']['calls_count']);
            $this->assertArrayHasKey('progress_pct', $row['metrics']['calls_count']);
        }
    }

    public function test_admin_sees_all_branches_kpi_rows(): void
    {
        [$users] = $this->seedContext();

        Sanctum::actingAs($users['admin']);

        $response = $this->getJson('/api/kpi-reports?period_type=day&date_from=2026-05-01&date_to=2026-05-01');
        $response->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_legacy_kpi_dashboard_endpoint_is_supported(): void
    {
        [$users] = $this->seedContext();

        Sanctum::actingAs($users['directorA']);

        $response = $this->getJson('/api/kpi/dashboard?period_type=day&date_from=2026-05-01&date_to=2026-05-01');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_rop_legacy_report_uses_historical_group_filters_and_minimal_employee_label(): void
    {
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        [$users] = $this->seedContext();
        $branch = Branch::first();
        $a = BranchGroup::where('branch_id', $branch->id)->first();
        $b = BranchGroup::create(['name' => 'B', 'branch_id' => $branch->id]);
        $rop = $this->createUser(Role::create(['name' => 'ROP', 'slug' => 'rop']), $branch, 'ROP');
        $rop->supervisedGroups()->attach([$a->id, $b->id]);
        $report = DailyReport::where('calls_count', 30)->firstOrFail();
        \Illuminate\Support\Facades\DB::table('daily_reports')->where('id', $report->id)->update(['branch_group_id' => $a->id]);
        $copy = $report->getAttributes(); unset($copy['id']);
        $copy['report_date'] = '2026-05-02'; $copy['branch_group_id'] = $b->id; $copy['calls_count'] = 7;
        \Illuminate\Support\Facades\DB::table('daily_reports')->insert($copy);
        $otherBranch = Branch::where('id', '!=', $branch->id)->first();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $report->user_id)->update(['branch_id' => $otherBranch->id, 'branch_group_id' => BranchGroup::where('branch_id', $otherBranch->id)->value('id'), 'role_id' => $users['admin']->role_id]);
        (require database_path('migrations/2026_05_01_221000_create_kpi_period_locks_and_adjustment_logs_tables.php'))->up();
        \App\Models\KpiPeriodLock::create(['period_type' => 'month', 'period_key' => '2026-05', 'branch_id' => $branch->id, 'branch_group_id' => $a->id, 'locked_by' => $users['admin']->id, 'locked_at' => now()]);
        Sanctum::actingAs($rop);
        $url = '/api/kpi-reports?period_type=month&date_from=2026-05-01&date_to=2026-05-31&user_id='.$report->user_id;
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $rows = $this->getJson($url)->assertOk()->json('data');
        $lockQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "kpi_period_locks"'));
        $this->assertCount(1, $lockQueries);
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertTrue(collect($rows)->firstWhere('branch_group_id', $a->id)['is_locked']);
        $this->assertFalse(collect($rows)->firstWhere('branch_group_id', $b->id)['is_locked']);
        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'name'], array_keys($rows[0]['user']));
        $this->assertEqualsCanonicalizing([30, 7], array_map(fn ($r) => $r['metrics']['calls_count']['fact_value'], $rows));
        $response = $this->getJson($url.'&branch_group_id='.$a->id)->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch_group_id', $a->id)->assertJsonPath('data.0.role_slug', 'agent')->assertJsonPath('data.0.metrics.calls_count.fact_value', 30);
        foreach (['/api/kpi/weekly?day=2026-05-01', '/api/kpi/monthly?year=2026&month=5'] as $periodUrl) {
            $periodRows = $this->getJson($periodUrl.'&v=2&user_id='.$report->user_id)->assertOk()->json('data');
            $this->assertCount(2, $periodRows);
            $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($periodRows, 'branch_group_id'));
            $this->assertSame(['agent'], array_values(array_unique(array_column($periodRows, 'role'))));
            $this->assertEqualsCanonicalizing([30, 7], array_column($periodRows, 'calls'));
            $this->getJson($periodUrl.'&v=2&user_id='.$report->user_id.'&branch_group_id='.$a->id)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.calls', 30);
        }
        $this->mock(\App\Services\DailyReportService::class, function ($mock) use ($a, $report) {
            $mock->shouldReceive('autoMetrics')->once()->withArgs(fn ($context, $date) => (int) $context->id === (int) $report->user_id && (int) $context->branch_group_id === (int) $a->id && $date === '2026-05-01')->andReturn(['calls_count' => 30]);
        });
        $this->getJson('/api/kpi/dashboard/debug?date=2026-05-01&role=agent&branch_group_id='.$a->id)
            ->assertOk()->assertJsonCount(1, 'data.ranking')->assertJsonPath('data.ranking.0.source_counts.calls_count', 30)
            ->assertJsonPath('data.ranking.0.branch_group_id', $a->id)->assertJsonMissingPath('data.ranking.0.user.role_slug');
        $rop->supervisedGroups()->detach();
        $this->getJson('/api/kpi-reports?date_from=2026-05-01&date_to=2026-05-31')->assertOk()->assertJsonPath('data', []);
    }

    private function seedContext(): array
    {
        $roles = [
            'admin' => Role::create(['name' => 'Admin', 'slug' => 'admin']),
            'branch_director' => Role::create(['name' => 'Branch Director', 'slug' => 'branch_director']),
            'agent' => Role::create(['name' => 'Agent', 'slug' => 'agent']),
        ];

        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $groupA = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A1']);
        $groupB = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'B1']);

        $directorA = $this->createUser($roles['branch_director'], $branchA, 'Director A', $groupA);
        $agentA = $this->createUser($roles['agent'], $branchA, 'Agent A', $groupA);
        $agentA2 = $this->createUser($roles['agent'], $branchA, 'Agent A2', $groupA);
        $agentB = $this->createUser($roles['agent'], $branchB, 'Agent B', $groupB);
        $admin = $this->createUser($roles['admin'], $branchA, 'Admin A', $groupA);

        $this->createReport($agentA, 20, 30, 5, 2, 1, 1, 1);
        $this->createReport($agentA2, 10, 20, 3, 1, 1, 0, 0);
        $this->createReport($agentB, 30, 45, 8, 4, 2, 2, 1);

        return [[
            'directorA' => $directorA,
            'admin' => $admin,
        ]];
    }

    private function createReport(User $user, int $adCount, int $callsCount, int $kabuls, int $shows, int $meetings, int $deposits, int $deals): void
    {
        DailyReport::create([
            'user_id' => $user->id,
            'role_slug' => $user->role->slug,
            'report_date' => '2026-05-01',
            'ad_count' => $adCount,
            'calls_count' => $callsCount,
            'new_clients_count' => $kabuls,
            'shows_count' => $shows,
            'meetings_count' => $meetings,
            'deposits_count' => $deposits,
            'deals_count' => $deals,
            'submitted_at' => now(),
        ]);
    }

    private function createUser(Role $role, Branch $branch, string $name, ?BranchGroup $branchGroup = null): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $branchGroup?->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }
}
