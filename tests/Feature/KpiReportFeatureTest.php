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
