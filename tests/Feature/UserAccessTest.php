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

    public function test_destroy_user_does_not_transfer_closed_properties(): void
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
            'title' => 'Closed by status',
            'status_id' => $soldStatusId,
            'moderation_status' => 'approved',
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
}
