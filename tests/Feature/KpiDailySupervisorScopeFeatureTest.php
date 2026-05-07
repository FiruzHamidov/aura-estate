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

class KpiDailySupervisorScopeFeatureTest extends TestCase
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
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('updated_by_role', 64)->nullable();
            $t->string('updated_reason', 500)->nullable();
            $t->string('edit_source', 64)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'report_date']);
        });
        Schema::create('kpi_period_locks', function (Blueprint $t) {
            $t->id();
            $t->string('period_type', 16);
            $t->string('period_key', 32);
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable();
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
    }

    public function test_mop_can_read_and_edit_agent_in_same_group_but_not_other_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));
        [$users, $reports] = $this->seedContext();

        Sanctum::actingAs($users['mop_a1']);

        $this->getJson('/api/kpi/daily/report?date=2026-05-05&employee_id='.$users['agent_a1']->id)
            ->assertOk()
            ->assertJsonPath('employee_role', 'agent')
            ->assertJsonPath('editable', true);

        $this->getJson('/api/kpi/daily/report?date=2026-05-05&employee_id='.$users['agent_b1']->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');

        $this->patchJson('/api/kpi/daily/report', [
            'report_date' => '2026-05-05',
            'employee_id' => $users['agent_a1']->id,
            'ads' => 44,
            'calls' => 55,
            'comment' => 'supervised',
            'plans_for_tomorrow' => 'next',
            'updated_reason' => 'correction',
            'edit_source' => 'manager_panel',
        ])->assertOk()
            ->assertJsonPath('manual.ads', 44)
            ->assertJsonPath('employee_id', $users['agent_a1']->id);

        $report = DailyReport::query()->findOrFail($reports['agent_a1']);
        $this->assertSame($users['mop_a1']->id, (int) $report->updated_by);
        $this->assertSame('mop', $report->updated_by_role);
        $this->assertSame('correction', $report->updated_reason);
        $this->assertSame('manager_panel', $report->edit_source);
    }

    public function test_rop_can_read_and_edit_mop_in_same_branch_and_forbidden_for_other_branch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));
        [$users] = $this->seedContext();

        Sanctum::actingAs($users['rop_a']);

        $this->getJson('/api/kpi/daily/report?date=2026-05-05&employee_id='.$users['mop_a1']->id)
            ->assertOk()
            ->assertJsonPath('employee_role', 'mop')
            ->assertJsonPath('editable', true);

        $this->patchJson('/api/kpi/daily/report', [
            'report_date' => '2026-05-05',
            'employee_id' => $users['mop_a1']->id,
            'ads' => 12,
            'calls' => 21,
        ])->assertOk()->assertJsonPath('manual.calls', 21);

        $this->getJson('/api/kpi/daily/report?date=2026-05-05&employee_id='.$users['mop_b1']->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_SCOPE');
    }

    public function test_supervisor_update_rejects_unknown_kpi_like_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));
        [$users] = $this->seedContext();
        Sanctum::actingAs($users['mop_a1']);

        $this->patchJson('/api/kpi/daily/report', [
            'report_date' => '2026-05-05',
            'employee_id' => $users['agent_a1']->id,
            'ads' => 2,
            'calls' => 3,
            'deals' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'KPI_VALIDATION_FAILED')
            ->assertJsonPath('details.errors.deals.0', 'This metric is not writable for this endpoint.');
    }

    public function test_supervisor_update_checks_locked_period_and_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 00:01:00', 'Asia/Dushanbe'));
        [$users] = $this->seedContext();
        Sanctum::actingAs($users['rop_a']);

        $this->patchJson('/api/kpi/daily/report', [
            'report_date' => '2026-05-05',
            'employee_id' => $users['mop_a1']->id,
            'ads' => 8,
            'calls' => 9,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_DEADLINE_PASSED');

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'Asia/Dushanbe'));
        \Illuminate\Support\Facades\DB::table('kpi_period_locks')->insert([
            'period_type' => 'day',
            'period_key' => '2026-05-05',
            'branch_id' => $users['mop_a1']->branch_id,
            'branch_group_id' => null,
            'locked_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson('/api/kpi/daily/report', [
            'report_date' => '2026-05-05',
            'employee_id' => $users['mop_a1']->id,
            'ads' => 8,
            'calls' => 9,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'KPI_FORBIDDEN_LOCKED_PERIOD');
    }

    private function seedContext(): array
    {
        $roles = collect(['agent', 'mop', 'rop', 'branch_director', 'admin', 'superadmin'])
            ->mapWithKeys(fn (string $slug) => [$slug => Role::create(['name' => ucfirst($slug), 'slug' => $slug])]);

        $branchA = Branch::create(['name' => 'A']);
        $branchB = Branch::create(['name' => 'B']);
        $groupA1 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A1']);
        $groupA2 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A2']);
        $groupB1 = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'B1']);

        $users = [
            'mop_a1' => $this->u('Mop A1', $roles['mop'], $branchA, $groupA1),
            'mop_b1' => $this->u('Mop B1', $roles['mop'], $branchB, $groupB1),
            'rop_a' => $this->u('Rop A', $roles['rop'], $branchA, $groupA1),
            'agent_a1' => $this->u('Agent A1', $roles['agent'], $branchA, $groupA1),
            'agent_a2' => $this->u('Agent A2', $roles['agent'], $branchA, $groupA2),
            'agent_b1' => $this->u('Agent B1', $roles['agent'], $branchB, $groupB1),
        ];

        $reports = [
            'agent_a1' => $this->r($users['agent_a1']),
            'mop_a1' => $this->r($users['mop_a1']),
            'mop_b1' => $this->r($users['mop_b1']),
        ];

        return [$users, $reports];
    }

    private function u(string $name, Role $role, Branch $branch, BranchGroup $group): User
    {
        return User::create([
            'name' => $name,
            'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function r(User $user): int
    {
        return (int) DailyReport::query()->create([
            'user_id' => $user->id,
            'role_slug' => $user->role?->slug,
            'report_date' => '2026-05-05',
            'ad_count' => 1,
            'calls_count' => 2,
            'submitted_at' => now(),
        ])->id;
    }
}
