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
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
    }

    public function test_patch_daily_report_allows_scope_roles_and_denies_out_of_scope_with_code(): void
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
            ->assertJsonPath('code', 'KPI_FORBIDDEN_DEADLINE_PASSED');
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
