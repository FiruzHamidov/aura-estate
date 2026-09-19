<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchGroupFeatureTest extends TestCase
{
    private int $phoneCounter = 940000000;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('contact_visibility_mode', 32)->default(BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();

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

    public function test_admin_can_create_group_for_any_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);

        $admin = $this->createUser($adminRole, $branchA, 'Admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/branch-groups', [
            'branch_id' => $branchB->id,
            'name' => 'Sales B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ])
            ->assertCreated()
            ->assertJsonPath('branch_id', $branchB->id)
            ->assertJsonPath('contact_visibility_mode', BranchGroup::CONTACT_VISIBILITY_BRANCH);
    }

    public function test_branch_director_creates_group_only_in_own_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);

        $director = $this->createUser($directorRole, $branchA, 'Director');

        Sanctum::actingAs($director);

        $this->postJson('/api/branch-groups', [
            'branch_id' => $branchB->id,
            'name' => 'Foreign Request',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ])
            ->assertCreated()
            ->assertJsonPath('branch_id', $branchA->id);
    }

    public function test_marketing_can_create_group_for_any_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $marketingRole = Role::create(['name' => 'Marketing', 'slug' => 'marketing']);

        $marketing = $this->createUser($marketingRole, $branchA, 'Marketing');

        Sanctum::actingAs($marketing);

        $this->postJson('/api/branch-groups', [
            'branch_id' => $branchB->id,
            'name' => 'Marketing B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ])
            ->assertCreated()
            ->assertJsonPath('branch_id', $branchB->id)
            ->assertJsonPath('contact_visibility_mode', BranchGroup::CONTACT_VISIBILITY_BRANCH);
    }

    public function test_agent_can_list_only_own_branch_groups_but_cannot_manage_them(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $groupA = BranchGroup::create([
            'branch_id' => $branchA->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        BranchGroup::create([
            'branch_id' => $branchB->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ]);

        $agent = $this->createUser($agentRole, $branchA, 'Agent');

        Sanctum::actingAs($agent);

        $this->getJson('/api/branch-groups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $groupA->id);

        $this->postJson('/api/branch-groups', [
            'name' => 'Blocked',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ])->assertForbidden();

        $this->deleteJson('/api/branch-groups/' . $groupA->id)->assertForbidden();
    }

    public function test_delete_non_empty_group_returns_conflict(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $admin = $this->createUser($adminRole, $branch, 'Admin');
        $this->createUser($agentRole, $branch, 'Agent', $group);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/branch-groups/' . $group->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Нельзя удалить группу: к ней привязаны пользователи или контакты.');
    }

    public function test_rop_group_counts_include_only_accessible_employees_and_clients(): void
    {
        $branch = Branch::create(['name' => 'Source']);
        $other = Branch::create(['name' => 'Other']);
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'Team']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $rop = $this->createUser($ropRole, $branch, 'ROP', $group);
        $admin = $this->createUser($adminRole, $branch, 'Admin', $group);
        $this->createUser($agentRole, $branch, 'Agent', $group);
        $this->createUser($agentRole, $other, 'Inconsistent branch', $group);
        $rop->supervisedGroups()->attach($group->id);
        foreach ([$branch, $other] as $clientBranch) {
            \Illuminate\Support\Facades\DB::table('clients')->insert(['full_name' => 'Contact',
                'branch_id' => $clientBranch->id, 'branch_group_id' => $group->id]);
        }
        Sanctum::actingAs($rop);
        $this->getJson('/api/branch-groups')->assertOk()->assertJsonPath('data.0.users_count', 1)->assertJsonPath('data.0.clients_count', 1);
        $this->getJson('/api/branch-groups/'.$group->id)->assertOk()->assertJsonPath('users_count', 1)->assertJsonPath('clients_count', 1);
        Sanctum::actingAs($admin);
        $this->getJson('/api/branch-groups/'.$group->id)->assertOk()->assertJsonPath('users_count', 4)->assertJsonPath('clients_count', 2);
    }

    public function test_group_with_rop_assignment_cannot_move_or_be_deleted(): void
    {
        $branch = Branch::create(['name' => 'Source']);
        $other = Branch::create(['name' => 'Destination']);
        $admin = $this->createUser(Role::create(['name' => 'Admin', 'slug' => 'admin']), $branch, 'Admin');
        $rop = $this->createUser(Role::create(['name' => 'ROP', 'slug' => 'rop']), $branch, 'ROP');
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'Assigned']);
        $rop->supervisedGroups()->attach($group->id);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/branch-groups/'.$group->id, ['branch_id' => $other->id])->assertUnprocessable();
        $this->deleteJson('/api/branch-groups/'.$group->id)->assertConflict();
        $this->assertSame($branch->id, (int) $group->fresh()->branch_id);
        $this->assertDatabaseHas('rop_branch_groups', ['rop_id' => $rop->id, 'branch_group_id' => $group->id]);
        $this->patchJson('/api/branch-groups/'.$group->id, ['name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed');
        Sanctum::actingAs($rop);
        $this->patchJson('/api/branch-groups/'.$group->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->deleteJson('/api/branch-groups/'.$group->id)->assertForbidden();
    }

    public function test_group_history_and_configuration_prevent_deletion_and_branch_reassignment(): void
    {
        $branch = Branch::create(['name' => 'Source']);
        $other = Branch::create(['name' => 'Destination']);
        $admin = $this->createUser(Role::create(['name' => 'Admin', 'slug' => 'admin']), $branch, 'Admin');
        Sanctum::actingAs($admin);
        foreach (['attendance_devices', 'external_property_requests', 'kpi_rop_plans', 'kpi_period_locks',
            'kpi_early_risk_alerts', 'kpi_quality_issues', 'kpi_acceptance_runs', 'kpi_adjustment_logs',
            'rop_liquidity_results', 'rop_liquidity_history'] as $table) {
            Schema::create($table, function (Blueprint $schema) {
                $schema->id();
                // Include legacy references without an FK: the guard must not rely on it.
                $schema->unsignedBigInteger('branch_group_id');
            });
            $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => $table]);
            \Illuminate\Support\Facades\DB::table($table)->insert(['branch_group_id' => $group->id]);
            $this->patchJson('/api/branch-groups/'.$group->id, ['branch_id' => $other->id])->assertUnprocessable();
            $this->deleteJson('/api/branch-groups/'.$group->id)->assertConflict();
            $this->assertSame($branch->id, (int) $group->fresh()->branch_id);
            $this->assertDatabaseHas($table, ['branch_group_id' => $group->id]);
            $this->patchJson('/api/branch-groups/'.$group->id, ['name' => $table.' renamed'])->assertOk();
        }
    }

    public function test_only_empty_unassigned_groups_can_move_and_be_deleted(): void
    {
        $branch = Branch::create(['name' => 'Source']);
        $other = Branch::create(['name' => 'Destination']);
        $admin = $this->createUser(Role::create(['name' => 'Admin', 'slug' => 'admin']), $branch, 'Admin');
        $group = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'Empty']);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/branch-groups/'.$group->id, ['branch_id' => $other->id])->assertOk()->assertJsonPath('branch_id', $other->id);
        $this->deleteJson('/api/branch-groups/'.$group->id)->assertOk();
        $historical = BranchGroup::create(['branch_id' => $branch->id, 'name' => 'History']);
        \Illuminate\Support\Facades\DB::table('clients')->insert(['full_name' => 'Archived contact',
            'branch_group_id' => $historical->id, 'branch_id' => $branch->id, 'deleted_at' => now()]);
        $this->patchJson('/api/branch-groups/'.$historical->id, ['branch_id' => $other->id])->assertUnprocessable();
        $this->deleteJson('/api/branch-groups/'.$historical->id)->assertConflict();
    }

    private function createUser(Role $role, Branch $branch, string $name, ?BranchGroup $group = null): User
    {
        return User::create([
            'name' => $name,
            'phone' => (string) ++$this->phoneCounter,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group?->id,
            'status' => 'active',
        ]);
    }
}
