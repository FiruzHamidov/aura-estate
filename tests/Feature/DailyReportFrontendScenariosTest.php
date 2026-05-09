<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyReportFrontendScenariosTest extends TestCase
{
    private int $phoneCounter = 960000000;

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
        Schema::create('kpi_plans', function (Blueprint $t) {
            $t->id();
            $t->string('role_slug', 32);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->string('metric_key', 64);
            $t->decimal('daily_plan', 10, 4);
            $t->decimal('weight', 8, 4)->default(1);
            $t->string('comment')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
        });
        Schema::create('user_daily_report_reminder_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->string('remind_time', 5)->default('18:30');
            $t->string('timezone', 64)->default('Asia/Dushanbe');
            $t->json('channels')->nullable();
            $t->boolean('allow_edit_submitted_daily_report')->default(false);
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
    }

    public function test_patch_daily_report_allows_only_rop_plus_and_denies_mop(): void
    {
        [$users, $reports] = $this->seedContext();

        Sanctum::actingAs($users['ropA']);
        $this->patchJson('/api/daily-reports/'.$reports['agentA'], ['comment' => 'rop edit'])
            ->assertOk()
            ->assertJsonPath('comment', 'rop edit');
        $this->patchJson('/api/daily-reports/'.$reports['agentB'], ['comment' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DAILY_REPORT_EDIT_FORBIDDEN');

        Sanctum::actingAs($users['mopA1']);
        $this->patchJson('/api/daily-reports/'.$reports['agentA'], ['comment' => 'mop edit'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DAILY_REPORT_EDIT_FORBIDDEN');
        $this->patchJson('/api/daily-reports/'.$reports['agentA2'], ['comment' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DAILY_REPORT_EDIT_FORBIDDEN');

        Sanctum::actingAs($users['branchDirectorA']);
        $this->patchJson('/api/daily-reports/'.$reports['agentA2'], ['comment' => 'director edit'])
            ->assertOk()
            ->assertJsonPath('comment', 'director edit');
    }

    public function test_daily_reports_support_report_date_and_range_with_report_date_priority(): void
    {
        [$users, $reports] = $this->seedContext();
        Sanctum::actingAs($users['admin']);

        DailyReport::query()->whereKey($reports['agentA'])->update(['report_date' => '2026-05-02']);
        DailyReport::query()->whereKey($reports['agentA2'])->update(['report_date' => '2026-05-03']);

        $response = $this->getJson('/api/daily-reports?report_date=2026-05-02&from=2026-05-03&to=2026-05-03');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reports['agentA']);
    }

    public function test_mop_scope_filter_violation_returns_403_with_code(): void
    {
        [$users, $reports, $groups] = $this->seedContext();
        Sanctum::actingAs($users['mopA1']);

        $this->getJson('/api/daily-reports?branch_group_id='.$groups['groupA2']->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');
    }

    public function test_owner_has_global_access_for_daily_reports_and_kpi_daily(): void
    {
        [$users] = $this->seedContext();
        Sanctum::actingAs($users['owner']);

        $this->getJson('/api/daily-reports')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/kpi/daily?date=2026-05-01')->assertOk();
    }

    public function test_daily_reports_row_contains_stable_metrics_with_kpi_v2_contract_and_plan_sources(): void
    {
        [$users, $reports] = $this->seedContext();
        Sanctum::actingAs($users['admin']);

        foreach (['objects' => 3, 'shows' => 4, 'ads' => 5, 'calls' => 6, 'sales' => 2] as $metric => $plan) {
            \App\Models\KpiPlan::query()->create([
                'role_slug' => 'agent',
                'user_id' => null,
                'branch_id' => $users['agentA']->branch_id,
                'branch_group_id' => $users['agentA']->branch_group_id,
                'metric_key' => $metric,
                'daily_plan' => $plan,
                'weight' => 1,
                'effective_from' => '2026-05-01',
                'effective_to' => '2026-05-01',
            ]);
        }
        // Personal override for one metric only: other metrics must still come from common plan.
        \App\Models\KpiPlan::query()->create([
            'role_slug' => 'agent',
            'user_id' => $users['agentA']->id,
            'branch_id' => $users['agentA']->branch_id,
            'branch_group_id' => $users['agentA']->branch_group_id,
            'metric_key' => 'objects',
            'daily_plan' => 9,
            'weight' => 1,
            'effective_from' => '2026-05-01',
            'effective_to' => '2026-05-01',
        ]);

        DailyReport::query()->whereKey($reports['agentA'])->update([
            'ad_count' => 9,
            'calls_count' => 11,
            'new_properties_count' => 7,
            'shows_count' => 8,
            'report_date' => '2026-05-01',
        ]);

        $response = $this->getJson('/api/daily-reports?report_date=2026-05-01&per_page=10&page=1');
        $response->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 10);

        $rows = collect($response->json('data'));
        $agentRow = $rows->firstWhere('user_id', $users['agentA']->id);
        $this->assertNotNull($agentRow);
        $this->assertSame(9, (int) data_get($agentRow, 'metrics.ads.final_value'));
        $this->assertSame(11, (int) data_get($agentRow, 'metrics.calls.final_value'));
        $this->assertSame(9, (int) data_get($agentRow, 'metrics.ads.fact_value'));
        $this->assertSame(11, (int) data_get($agentRow, 'metrics.calls.fact_value'));
        $this->assertArrayHasKey('target_value', (array) data_get($agentRow, 'metrics.objects'));
        $this->assertArrayHasKey('plan_source', (array) data_get($agentRow, 'metrics.objects'));
        $this->assertArrayHasKey('target_value', (array) data_get($agentRow, 'metrics.sales'));
        $this->assertArrayHasKey('plan_source', (array) data_get($agentRow, 'metrics.sales'));
    }

    public function test_agent_cannot_edit_own_submitted_report_even_with_flag(): void
    {
        [$users, $reports] = $this->seedContext();
        Sanctum::actingAs($users['agentA']);

        $this->patchJson('/api/daily-reports/'.$reports['agentA'], ['comment' => 'blocked'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DAILY_REPORT_EDIT_FORBIDDEN');

        UserDailyReportReminderSetting::query()->create([
            'user_id' => $users['agentA']->id,
            'enabled' => false,
            'remind_time' => '18:30',
            'timezone' => 'Asia/Dushanbe',
            'channels' => ['in_app'],
            'allow_edit_submitted_daily_report' => true,
        ]);

        $this->patchJson('/api/daily-reports/'.$reports['agentA'], ['comment' => 'allowed'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DAILY_REPORT_EDIT_FORBIDDEN');
    }

    public function test_daily_reports_row_marks_missing_plan_with_null_source_and_target(): void
    {
        [$users] = $this->seedContext();
        Sanctum::actingAs($users['admin']);

        \App\Models\KpiPlan::query()->delete();

        $response = $this->getJson('/api/daily-reports?report_date=2026-05-01&per_page=10&page=1');
        $response->assertOk();

        $rows = collect($response->json('data'));
        $agentRow = $rows->firstWhere('user_id', $users['agentA']->id);
        $this->assertNotNull($agentRow);
        $this->assertNull(data_get($agentRow, 'metrics.objects.target_value'));
        $this->assertNull(data_get($agentRow, 'metrics.objects.plan_source'));
        $this->assertNull(data_get($agentRow, 'metrics.sales.target_value'));
        $this->assertNull(data_get($agentRow, 'metrics.sales.plan_source'));
    }

    private function seedContext(): array
    {
        $roles = [
            'admin' => Role::create(['name' => 'Admin', 'slug' => 'admin']),
            'owner' => Role::create(['name' => 'Owner', 'slug' => 'owner']),
            'rop' => Role::create(['name' => 'ROP', 'slug' => 'rop']),
            'mop' => Role::create(['name' => 'MOP', 'slug' => 'mop']),
            'branch_director' => Role::create(['name' => 'Branch Director', 'slug' => 'branch_director']),
            'agent' => Role::create(['name' => 'Agent', 'slug' => 'agent']),
        ];

        $branchA = Branch::create(['name' => 'A']);
        $branchB = Branch::create(['name' => 'B']);
        $groupA1 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A1']);
        $groupA2 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A2']);
        $groupB = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'B1']);

        $users = [
            'admin' => $this->makeUser('Admin', $roles['admin'], $branchA, $groupA1),
            'owner' => $this->makeUser('Owner', $roles['owner'], $branchA, $groupA1),
            'ropA' => $this->makeUser('RopA', $roles['rop'], $branchA, $groupA1),
            'mopA1' => $this->makeUser('MopA1', $roles['mop'], $branchA, $groupA1),
            'branchDirectorA' => $this->makeUser('DirA', $roles['branch_director'], $branchA, $groupA1),
            'agentA' => $this->makeUser('AgentA', $roles['agent'], $branchA, $groupA1),
            'agentA2' => $this->makeUser('AgentA2', $roles['agent'], $branchA, $groupA2),
            'agentB' => $this->makeUser('AgentB', $roles['agent'], $branchB, $groupB),
        ];

        $reports = [
            'agentA' => $this->makeReport($users['agentA']),
            'agentA2' => $this->makeReport($users['agentA2']),
            'agentB' => $this->makeReport($users['agentB']),
            'mopA1' => $this->makeReport($users['mopA1']),
        ];

        return [$users, $reports, ['groupA1' => $groupA1, 'groupA2' => $groupA2]];
    }

    private function makeUser(string $name, Role $role, Branch $branch, BranchGroup $group): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function makeReport(User $user): int
    {
        return (int) DailyReport::query()->create([
            'user_id' => $user->id,
            'role_slug' => $user->role?->slug,
            'report_date' => '2026-05-01',
            'calls_count' => 1,
            'submitted_at' => now(),
        ])->id;
    }
}
