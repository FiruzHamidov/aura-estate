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
