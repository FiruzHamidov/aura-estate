<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\DailyReport;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RopBranchScopeIsolationTest extends TestCase
{
    private int $phoneCounter = 950000000;

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

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug')->nullable();
            $table->date('report_date');
            $table->integer('calls_count')->default(0);
            $table->integer('meetings_count')->default(0);
            $table->integer('shows_count')->default(0);
            $table->integer('new_clients_count')->default(0);
            $table->integer('new_properties_count')->default(0);
            $table->integer('deals_count')->default(0);
            $table->text('comment')->nullable();
            $table->text('plans_for_tomorrow')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('moderation_status')->default('approved');
            $table->string('offer_type')->default('sale');
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('total_area', 12, 2)->default(0);
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('deposit_received_at')->nullable();
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

    public function test_rop_gets_only_own_branch_data_for_groups_and_reports(): void
    {
        [$branchA, $branchB, $users] = $this->seedContext();
        $rop = $users['ropA'];
        Sanctum::actingAs($rop);

        $groupsResponse = $this->getJson('/api/branch-groups');
        $groupsResponse->assertOk()->assertJsonCount(2, 'data');

        $dailyResponse = $this->getJson('/api/daily-reports');
        $dailyResponse->assertOk()->assertJsonCount(3, 'data');

        $summaryResponse = $this->getJson('/api/reports/properties/summary');
        $summaryResponse->assertOk()->assertJsonPath('total', 2);
    }

    public function test_rop_foreign_branch_filter_returns_403_with_rbac_code(): void
    {
        [$branchA, $branchB, $users] = $this->seedContext();
        Sanctum::actingAs($users['ropA']);

        $this->getJson('/api/daily-reports?branch_id='.$branchB->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');
    }

    public function test_rop_foreign_agent_filter_returns_403_with_rbac_code(): void
    {
        [$branchA, $branchB, $users] = $this->seedContext();
        Sanctum::actingAs($users['ropA']);

        $this->getJson('/api/reports/properties/summary?agent_id='.$users['agentB']->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');
    }

    public function test_rop_foreign_branch_group_filter_returns_403_with_rbac_code(): void
    {
        [$branchA, $branchB, $users, $groups] = $this->seedContext();
        Sanctum::actingAs($users['ropA']);

        $this->getJson('/api/daily-reports?branch_group_id='.$groups['groupB']->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'RBAC_BRANCH_SCOPE_VIOLATION');
    }

    public function test_admin_and_superadmin_keep_global_access(): void
    {
        [$branchA, $branchB, $users] = $this->seedContext();

        Sanctum::actingAs($users['admin']);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/daily-reports')->assertOk()->assertJsonCount(4, 'data');

        Sanctum::actingAs($users['superadmin']);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/daily-reports')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_mop_and_agent_models_continue_to_work(): void
    {
        [$branchA, $branchB, $users] = $this->seedContext();

        Sanctum::actingAs($users['mopA']);
        $this->getJson('/api/daily-reports')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($users['agentA']);
        $this->getJson('/api/daily-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $users['agentA']->id);
    }

    private function seedContext(): array
    {
        $roles = [
            'admin' => Role::create(['name' => 'Admin', 'slug' => 'admin']),
            'superadmin' => Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']),
            'rop' => Role::create(['name' => 'ROP', 'slug' => 'rop']),
            'mop' => Role::create(['name' => 'MOP', 'slug' => 'mop']),
            'agent' => Role::create(['name' => 'Agent', 'slug' => 'agent']),
        ];

        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $groupA1 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A1']);
        $groupA2 = BranchGroup::create(['branch_id' => $branchA->id, 'name' => 'A2']);
        $groupB = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'B1']);

        $ropA = $this->createUser($roles['rop'], $branchA, 'ROP A');
        $mopA = $this->createUser($roles['mop'], $branchA, 'MOP A', $groupA1);
        $agentA = $this->createUser($roles['agent'], $branchA, 'Agent A', $groupA1);
        $agentA2 = $this->createUser($roles['agent'], $branchA, 'Agent A2', $groupA2);
        $agentB = $this->createUser($roles['agent'], $branchB, 'Agent B', $groupB);
        $admin = $this->createUser($roles['admin'], $branchA, 'Admin A');
        $superadmin = $this->createUser($roles['superadmin'], $branchA, 'Superadmin A');

        $this->createReport($agentA);
        $this->createReport($agentA2);
        $this->createReport($mopA);
        $this->createReport($agentB);

        Property::create(['created_by' => $agentA->id, 'agent_id' => $agentA->id, 'branch_group_id' => $groupA1->id]);
        Property::create(['created_by' => $agentA2->id, 'agent_id' => $agentA2->id, 'branch_group_id' => $groupA2->id]);
        Property::create(['created_by' => $agentB->id, 'agent_id' => $agentB->id, 'branch_group_id' => $groupB->id]);

        return [
            $branchA,
            $branchB,
            [
                'ropA' => $ropA,
                'mopA' => $mopA,
                'agentA' => $agentA,
                'agentB' => $agentB,
                'admin' => $admin,
                'superadmin' => $superadmin,
            ],
            [
                'groupB' => $groupB,
            ],
        ];
    }

    private function createReport(User $user): void
    {
        DailyReport::create([
            'user_id' => $user->id,
            'role_slug' => $user->role->slug,
            'report_date' => '2026-04-30',
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
