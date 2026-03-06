<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->text('description')->nullable();
            $table->date('birthday')->nullable();
            $table->string('photo')->nullable();
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

    public function test_rop_user_index_is_scoped_to_own_branch_and_excludes_branch_directors(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $rop = User::create([
            'name' => 'ROP A',
            'phone' => '900000001',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $sameBranchAgent = User::create([
            'name' => 'Agent A',
            'phone' => '900000002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Director A',
            'phone' => '900000003',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Agent B',
            'phone' => '900000004',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?branch_id=' . $branchB->id);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $sameBranchAgent->id);
        $response->assertJsonMissing(['phone' => '900000003']);
        $response->assertJsonMissing(['phone' => '900000004']);
    }

    public function test_rop_cannot_view_branch_director_from_same_branch(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);

        $rop = User::create([
            'name' => 'ROP A',
            'phone' => '900000011',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $director = User::create([
            'name' => 'Director A',
            'phone' => '900000012',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($rop);

        $this->getJson('/api/user/' . $director->id)->assertForbidden();
    }

    public function test_rop_cannot_assign_privileged_role_and_branch_is_forced_for_branch_scoped_roles(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $rop = User::create([
            'name' => 'ROP A',
            'phone' => '900000021',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($rop);

        $this->postJson('/api/user', [
            'name' => 'Blocked Director',
            'phone' => '900000022',
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
        ])->assertStatus(422);

        $response = $this->postJson('/api/user', [
            'name' => 'Agent A',
            'phone' => '900000023',
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('branch_id', $branchA->id);
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $user = User::create([
            'name' => 'Agent A',
            'phone' => '900000031',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/profile');

        $response->assertOk();
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('role.slug', 'agent');
        $response->assertJsonPath('branch.id', $branch->id);
    }

    public function test_superadmin_user_index_is_global_and_not_forced_to_own_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000041',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Agent A',
            'phone' => '900000042',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '900000043',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/user?branch_id=' . $branchB->id . '&role=agent&status=active');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $agentB->id);

        $this->getJson('/api/user/' . $agentB->id)->assertOk();
    }
}
