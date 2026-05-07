<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->string('moderation_status')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
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

    public function test_rop_user_index_can_include_unassigned_users_when_requested(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $rop = User::create([
            'name' => 'ROP A',
            'phone' => '900000015',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $sameBranchUser = User::create([
            'name' => 'Agent A',
            'phone' => '900000016',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $unassignedUser = User::create([
            'name' => 'No Branch Agent',
            'phone' => '900000017',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => null,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Agent B',
            'phone' => '900000018',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($rop);

        $defaultResponse = $this->getJson('/api/user');
        $defaultResponse->assertOk();
        $defaultResponse->assertJsonMissing(['id' => $unassignedUser->id]);

        $response = $this->getJson('/api/user?include_unassigned=1');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonFragment(['id' => $sameBranchUser->id]);
        $response->assertJsonFragment(['id' => $unassignedUser->id]);
        $response->assertJsonMissing(['phone' => '900000018']);
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('last_page', 1);
        $response->assertJsonPath('active_count', 2);
        $response->assertJsonPath('inactive_count', 1);
    }

    public function test_branch_director_user_index_can_include_unassigned_users_when_requested(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $director = User::create([
            'name' => 'Director A',
            'phone' => '900000019',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $sameBranchUser = User::create([
            'name' => 'Agent A',
            'phone' => '900000020',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $unassignedUser = User::create([
            'name' => 'No Branch Agent',
            'phone' => '900000121',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => null,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Agent B',
            'phone' => '900000122',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($director);

        $response = $this->getJson('/api/user?include_unassigned=1');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonFragment(['id' => $sameBranchUser->id]);
        $response->assertJsonFragment(['id' => $unassignedUser->id]);
        $response->assertJsonMissing(['phone' => '900000122']);
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('last_page', 1);
        $response->assertJsonPath('active_count', 2);
        $response->assertJsonPath('inactive_count', 1);
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

    public function test_rop_can_create_and_update_mop_in_own_branch_group(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $groupA = BranchGroup::create([
            'branch_id' => $branchA->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        $groupB = BranchGroup::create([
            'branch_id' => $branchB->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);

        $rop = User::create([
            'name' => 'ROP A',
            'phone' => '900000024',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($rop);

        $this->postJson('/api/user', [
            'name' => 'MOP Wrong Group',
            'phone' => '900000025',
            'role_id' => $mopRole->id,
            'branch_group_id' => $groupB->id,
        ])->assertStatus(422);

        $response = $this->postJson('/api/user', [
            'name' => 'MOP A',
            'phone' => '900000026',
            'role_id' => $mopRole->id,
            'branch_group_id' => $groupA->id,
        ]);

        $createdUserId = $response->json('id');

        $response->assertCreated();
        $response->assertJsonPath('role.slug', 'mop');
        $response->assertJsonPath('branch_id', $branchA->id);
        $response->assertJsonPath('branch_group_id', $groupA->id);

        $this->patchJson('/api/user/'.$createdUserId, [
            'name' => 'MOP A Updated',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('name', 'MOP A Updated')
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('role.slug', 'mop')
            ->assertJsonPath('branch_id', $branchA->id)
            ->assertJsonPath('branch_group_id', $groupA->id);
    }

    public function test_branch_director_can_create_and_update_mop_in_own_branch_group(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $groupA = BranchGroup::create([
            'branch_id' => $branchA->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        $groupB = BranchGroup::create([
            'branch_id' => $branchB->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);

        $director = User::create([
            'name' => 'Director A',
            'phone' => '900000027',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($director);

        $this->postJson('/api/user', [
            'name' => 'MOP Wrong Group',
            'phone' => '900000028',
            'role_id' => $mopRole->id,
            'branch_group_id' => $groupB->id,
        ])->assertStatus(422);

        $response = $this->postJson('/api/user', [
            'name' => 'MOP A',
            'phone' => '900000029',
            'role_id' => $mopRole->id,
            'branch_group_id' => $groupA->id,
        ]);

        $createdUserId = $response->json('id');

        $response->assertCreated();
        $response->assertJsonPath('role.slug', 'mop');
        $response->assertJsonPath('branch_id', $branchA->id);
        $response->assertJsonPath('branch_group_id', $groupA->id);

        $this->patchJson('/api/user/'.$createdUserId, [
            'name' => 'MOP A Updated By Director',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('name', 'MOP A Updated By Director')
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('role.slug', 'mop')
            ->assertJsonPath('branch_id', $branchA->id)
            ->assertJsonPath('branch_group_id', $groupA->id);
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $user = User::create([
            'name' => 'Agent A',
            'phone' => '900000031',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/profile');

        $response->assertOk();
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('role.slug', 'agent');
        $response->assertJsonPath('branch.id', $branch->id);
        $response->assertJsonPath('branch_group.id', $group->id);
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

    public function test_marketing_user_index_is_global_and_not_forced_to_own_branch(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $marketingRole = Role::create(['name' => 'Marketing', 'slug' => 'marketing']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $marketing = User::create([
            'name' => 'Marketing',
            'phone' => '900000051',
            'password' => bcrypt('password'),
            'role_id' => $marketingRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Agent A',
            'phone' => '900000052',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '900000053',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($marketing);

        $response = $this->getJson('/api/user?branch_id=' . $branchB->id . '&role=agent&status=active');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $agentB->id);

        $this->getJson('/api/user/' . $agentB->id)->assertOk();
    }

    public function test_superadmin_visibility_is_unchanged_when_include_unassigned_is_passed(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000054',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $unassigned = User::create([
            'name' => 'Unassigned Agent',
            'phone' => '900000055',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => null,
            'status' => 'active',
        ]);

        Sanctum::actingAs($superadmin);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonFragment(['id' => $unassigned->id]);

        $this->getJson('/api/user?include_unassigned=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $unassigned->id]);
    }

    public function test_marketing_cannot_assign_admin_or_superadmin_roles(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $marketingRole = Role::create(['name' => 'Marketing', 'slug' => 'marketing']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);

        $marketing = User::create([
            'name' => 'Marketing',
            'phone' => '900000054',
            'password' => bcrypt('password'),
            'role_id' => $marketingRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($marketing);

        $this->postJson('/api/user', [
            'name' => 'Blocked Admin',
            'phone' => '900000055',
            'role_id' => $adminRole->id,
        ])->assertStatus(422);

        $this->postJson('/api/user', [
            'name' => 'Blocked Superadmin',
            'phone' => '900000056',
            'role_id' => $superadminRole->id,
        ])->assertStatus(422);
    }

    public function test_hr_can_manage_users_globally_but_cannot_assign_admin_or_superadmin_roles(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $hrRole = Role::create(['name' => 'HR', 'slug' => 'hr']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);

        $hr = User::create([
            'name' => 'HR User',
            'phone' => '900000154',
            'password' => bcrypt('password'),
            'role_id' => $hrRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '900000155',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($hr);

        $this->getJson('/api/user?branch_id='.$branchB->id.'&role=agent&status=active')
            ->assertOk()
            ->assertJsonFragment(['id' => $agentB->id]);

        $this->getJson('/api/user/'.$agentB->id)
            ->assertOk()
            ->assertJsonPath('id', $agentB->id);

        $this->postJson('/api/user', [
            'name' => 'HR Created Agent',
            'phone' => '900000156',
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
        ])->assertCreated()
            ->assertJsonPath('branch_id', $branchB->id);

        $this->postJson('/api/user', [
            'name' => 'Blocked Admin',
            'phone' => '900000157',
            'role_id' => $adminRole->id,
        ])->assertStatus(422);

        $this->postJson('/api/user', [
            'name' => 'Blocked Superadmin',
            'phone' => '900000158',
            'role_id' => $superadminRole->id,
        ])->assertStatus(422);
    }

    public function test_branch_director_cannot_assign_admin_role(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);

        $director = User::create([
            'name' => 'Director',
            'phone' => '900000057',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($director);

        $this->postJson('/api/user', [
            'name' => 'Blocked Admin',
            'phone' => '900000058',
            'role_id' => $adminRole->id,
            'branch_id' => $branch->id,
        ])->assertStatus(422);
    }

    public function test_user_index_filters_inactive_users_and_returns_tab_counts(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $otherBranch = Branch::create(['name' => 'Branch B']);

        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        $otherGroup = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000061',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Active Agent',
            'phone' => '900000062',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        $inactiveAgent = User::create([
            'name' => 'Inactive Agent',
            'phone' => '900000063',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Inactive Other Group',
            'phone' => '900000064',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $otherGroup->id,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Inactive Other Branch',
            'phone' => '900000065',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $otherBranch->id,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Inactive Manager',
            'phone' => '900000066',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'inactive',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/user?branch_id='.$branch->id.'&branch_group_id='.$group->id.'&role=agent&status=inactive&page=1&per_page=15');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $inactiveAgent->id);
        $response->assertJsonPath('data.0.status', 'inactive');
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('per_page', 15);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('active_count', 1);
        $response->assertJsonPath('inactive_count', 1);
    }

    public function test_user_index_combines_status_with_name_phone_and_keeps_pagination_shape(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000071',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $activeUser = User::create([
            'name' => 'Alex Active',
            'phone' => '7770001',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Alex Inactive',
            'phone' => '7770002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Maria Active',
            'phone' => '8880001',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/user?name=Alex&phone=777&status=active&page=1&per_page=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $activeUser->id);
        $response->assertJsonPath('data.0.status', 'active');
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('last_page', 1);
        $response->assertJsonPath('per_page', 1);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('active_count', 1);
        $response->assertJsonPath('inactive_count', 1);
    }

    public function test_branch_director_cannot_assign_group_from_another_branch_and_user_payload_includes_group(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $groupA = BranchGroup::create([
            'branch_id' => $branchA->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);
        $groupB = BranchGroup::create([
            'branch_id' => $branchB->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $director = User::create([
            'name' => 'Director A',
            'phone' => '900000051',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($director);

        $this->postJson('/api/user', [
            'name' => 'Agent Wrong Group',
            'phone' => '900000052',
            'role_id' => $agentRole->id,
            'branch_group_id' => $groupB->id,
        ])->assertStatus(422);

        $response = $this->postJson('/api/user', [
            'name' => 'Agent Right Group',
            'phone' => '900000053',
            'role_id' => $agentRole->id,
            'branch_group_id' => $groupA->id,
        ]);

        $createdUserId = $response->json('id');

        $response->assertCreated();
        $response->assertJsonPath('branch_id', $branchA->id);
        $response->assertJsonPath('branch_group_id', $groupA->id);
        $response->assertJsonPath('branch_group.id', $groupA->id);

        $this->getJson('/api/user/' . $createdUserId)
            ->assertOk()
            ->assertJsonPath('branch_group.id', $groupA->id);
    }

    public function test_destroy_user_transfers_only_approved_properties(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000081',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $dismissedAgent = User::create([
            'name' => 'Dismissed Agent',
            'phone' => '900000082',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $targetAgent = User::create([
            'name' => 'Target Agent',
            'phone' => '900000083',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $availableStatusId = DB::table('property_statuses')->insertGetId([
            'name' => 'Доступен',
            'slug' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $soldStatusId = DB::table('property_statuses')->insertGetId([
            'name' => 'Продан',
            'slug' => 'sold',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $openPropertyId = DB::table('properties')->insertGetId([
            'title' => 'Open property',
            'status_id' => $availableStatusId,
            'moderation_status' => 'approved',
            'created_by' => $dismissedAgent->id,
            'agent_id' => $dismissedAgent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $closedByStatusPropertyId = DB::table('properties')->insertGetId([
            'title' => 'Not approved property',
            'status_id' => $soldStatusId,
            'moderation_status' => 'pending',
            'created_by' => $dismissedAgent->id,
            'agent_id' => $dismissedAgent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $closedByModerationPropertyId = DB::table('properties')->insertGetId([
            'title' => 'Closed by moderation',
            'status_id' => $availableStatusId,
            'moderation_status' => 'sold',
            'created_by' => $dismissedAgent->id,
            'agent_id' => $dismissedAgent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($superadmin);

        $this->deleteJson('/api/user/'.$dismissedAgent->id, [
            'agent_id' => $targetAgent->id,
        ])->assertOk();

        $openProperty = DB::table('properties')->where('id', $openPropertyId)->first();
        $closedByStatusProperty = DB::table('properties')->where('id', $closedByStatusPropertyId)->first();
        $closedByModerationProperty = DB::table('properties')->where('id', $closedByModerationPropertyId)->first();

        $this->assertSame($targetAgent->id, $openProperty->created_by);
        $this->assertSame($targetAgent->id, $openProperty->agent_id);

        $this->assertSame($dismissedAgent->id, $closedByStatusProperty->created_by);
        $this->assertSame($dismissedAgent->id, $closedByStatusProperty->agent_id);

        $this->assertSame($dismissedAgent->id, $closedByModerationProperty->created_by);
        $this->assertSame($dismissedAgent->id, $closedByModerationProperty->agent_id);

        $this->assertSame('inactive', $dismissedAgent->fresh()->status);
    }

    public function test_destroy_user_auto_distribution_is_fair_between_agents(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000084',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $dismissedAgent = User::create([
            'name' => 'Dismissed Agent',
            'phone' => '900000085',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agentA = User::create([
            'name' => 'Agent A',
            'phone' => '900000086',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '900000087',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agentC = User::create([
            'name' => 'Agent C',
            'phone' => '900000088',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $availableStatusId = DB::table('property_statuses')->insertGetId([
            'name' => 'Доступен',
            'slug' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Текущая нагрузка агентов: A=3, B=1, C=1
        foreach (['A-1', 'A-2', 'A-3'] as $title) {
            DB::table('properties')->insert([
                'title' => $title,
                'status_id' => $availableStatusId,
                'moderation_status' => 'approved',
                'created_by' => $agentA->id,
                'agent_id' => $agentA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['B-1', 'C-1'] as $idx => $title) {
            $agentId = $idx === 0 ? $agentB->id : $agentC->id;
            DB::table('properties')->insert([
                'title' => $title,
                'status_id' => $availableStatusId,
                'moderation_status' => 'approved',
                'created_by' => $agentId,
                'agent_id' => $agentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4 опубликованных объекта уволенного + 1 неопубликованный (не должен переназначаться)
        foreach (['D-1', 'D-2', 'D-3', 'D-4'] as $title) {
            DB::table('properties')->insert([
                'title' => $title,
                'status_id' => $availableStatusId,
                'moderation_status' => 'approved',
                'created_by' => $dismissedAgent->id,
                'agent_id' => $dismissedAgent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pendingId = DB::table('properties')->insertGetId([
            'title' => 'D-pending',
            'status_id' => $availableStatusId,
            'moderation_status' => 'pending',
            'created_by' => $dismissedAgent->id,
            'agent_id' => $dismissedAgent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($superadmin);

        $this->deleteJson('/api/user/'.$dismissedAgent->id, [
            'distribute_to_agents' => true,
        ])->assertOk();

        $counts = DB::table('properties')
            ->where('moderation_status', 'approved')
            ->whereIn('agent_id', [$agentA->id, $agentB->id, $agentC->id])
            ->selectRaw('agent_id, COUNT(*) as total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $this->assertSame(3, (int) ($counts[$agentA->id] ?? 0));
        $this->assertSame(3, (int) ($counts[$agentB->id] ?? 0));
        $this->assertSame(3, (int) ($counts[$agentC->id] ?? 0));

        $pendingProperty = DB::table('properties')->where('id', $pendingId)->first();
        $this->assertSame($dismissedAgent->id, $pendingProperty->agent_id);
        $this->assertSame($dismissedAgent->id, $pendingProperty->created_by);
    }

    public function test_user_index_exposes_meta_and_can_filter_report_agents(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000091',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Manager CRM',
            'phone' => '900000092',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Agent Report',
            'phone' => '900000093',
            'email' => 'agent@example.com',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Intern Report',
            'phone' => '900000094',
            'email' => 'intern@example.com',
            'password' => bcrypt('password'),
            'role_id' => $internRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'ROP Report',
            'phone' => '900000095',
            'email' => 'rop@example.com',
            'password' => bcrypt('password'),
            'role_id' => $ropRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        $inactiveMop = User::create([
            'name' => 'MOP Report',
            'phone' => '900000096',
            'email' => 'mop@example.com',
            'password' => bcrypt('password'),
            'role_id' => $mopRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'inactive',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/user?report_agents=1&status=inactive&email=mop@example.com&page=1&per_page=100');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $inactiveMop->id);
        $response->assertJsonPath('data.0.role.slug', 'mop');
        $response->assertJsonPath('data.0.branch_id', $branch->id);
        $response->assertJsonPath('data.0.branch_group_id', $group->id);
        $response->assertJsonPath('data.0.branch.name', 'Branch A');
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 1);
        $response->assertJsonPath('meta.per_page', 100);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('last_page', 1);
        $response->assertJsonPath('per_page', 100);
        $response->assertJsonPath('total', 1);
    }

    public function test_user_index_can_filter_by_roles_array(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $internRole = Role::create(['name' => 'Intern', 'slug' => 'intern']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '900000101',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agent = User::create([
            'name' => 'Agent User',
            'phone' => '900000102',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $intern = User::create([
            'name' => 'Intern User',
            'phone' => '900000103',
            'password' => bcrypt('password'),
            'role_id' => $internRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Manager User',
            'phone' => '900000104',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/user?roles[]=agent&roles[]=intern&status=active');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $agent->id]);
        $response->assertJsonFragment(['id' => $intern->id]);
        $response->assertJsonMissing(['phone' => '900000104']);
    }

    public function test_public_user_agents_endpoint_returns_agents_and_mops(): void
    {
        $branch = Branch::create(['name' => 'Branch A']);
        $group = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_GROUP_ONLY,
        ]);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $mopRole = Role::create(['name' => 'MOP', 'slug' => 'mop']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $activeAgent = User::create([
            'name' => 'Active Agent',
            'phone' => '900000105',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        $activeMop = User::create([
            'name' => 'Active MOP',
            'phone' => '900000106',
            'password' => bcrypt('password'),
            'role_id' => $mopRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        $inactiveMop = User::create([
            'name' => 'Inactive MOP',
            'phone' => '900000107',
            'password' => bcrypt('password'),
            'role_id' => $mopRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'inactive',
        ]);

        $inactiveAgent = User::create([
            'name' => 'Inactive Agent',
            'phone' => '900000108',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'inactive',
        ]);

        User::create([
            'name' => 'Manager User',
            'phone' => '900000109',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $group->id,
            'status' => 'active',
        ]);

        $activeResponse = $this->getJson('/api/user/agents');
        $activeResponse->assertOk();
        $activeResponse->assertJsonCount(2);
        $activeResponse->assertJsonPath('0.id', $activeMop->id);
        $activeResponse->assertJsonPath('1.id', $activeAgent->id);
        $activeResponse->assertJsonFragment(['id' => $activeAgent->id]);
        $activeResponse->assertJsonFragment(['id' => $activeMop->id]);
        $activeResponse->assertJsonMissing(['id' => $inactiveAgent->id]);
        $activeResponse->assertJsonMissing(['id' => $inactiveMop->id]);
        $activeResponse->assertJsonMissing(['phone' => '900000109']);

        $inactiveResponse = $this->getJson('/api/user/agents?status=inactive');
        $inactiveResponse->assertOk();
        $inactiveResponse->assertJsonCount(2);
        $inactiveResponse->assertJsonPath('0.id', $inactiveMop->id);
        $inactiveResponse->assertJsonPath('1.id', $inactiveAgent->id);
        $inactiveResponse->assertJsonFragment(['id' => $inactiveAgent->id]);
        $inactiveResponse->assertJsonFragment(['id' => $inactiveMop->id]);
        $inactiveResponse->assertJsonMissing(['id' => $activeAgent->id]);
        $inactiveResponse->assertJsonMissing(['id' => $activeMop->id]);
        $inactiveResponse->assertJsonMissing(['phone' => '900000109']);
    }
}
