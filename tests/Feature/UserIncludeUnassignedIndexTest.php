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

class UserIncludeUnassignedIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
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
    }

    public function test_rop_include_unassigned_zero_keeps_old_scope(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $rop = $this->makeUser('ROP A', '910000001', $roles['rop'], $branchA->id, 'active');
        $inBranch = $this->makeUser('Agent A', '910000002', $roles['agent'], $branchA->id, 'active');
        $this->makeUser('No Branch', '910000003', $roles['agent'], null, 'inactive');
        $this->makeUser('Agent B', '910000004', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?include_unassigned=0');
        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $inBranch->id]);
        $response->assertJsonMissing(['phone' => '910000003']);
        $response->assertJsonMissing(['phone' => '910000004']);
    }

    public function test_rop_include_unassigned_one_includes_null_branch_and_counts_are_scoped(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $rop = $this->makeUser('ROP A', '910000011', $roles['rop'], $branchA->id, 'active');
        $this->makeUser('Agent A Active', '910000012', $roles['agent'], $branchA->id, 'active');
        $this->makeUser('Agent A Inactive', '910000013', $roles['agent'], $branchA->id, 'inactive');
        $unassigned = $this->makeUser('No Branch Inactive', '910000014', $roles['agent'], null, 'inactive');
        $this->makeUser('Agent B', '910000015', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?include_unassigned=1');
        $response->assertOk();
        $response->assertJsonFragment(['id' => $unassigned->id]);
        $response->assertJsonMissing(['phone' => '910000015']);
        $response->assertJsonPath('total', 4);
        $response->assertJsonPath('active_count', 2);
        $response->assertJsonPath('inactive_count', 2);
    }

    public function test_rop_cannot_escape_scope_with_include_unassigned_and_foreign_branch_filter(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $rop = $this->makeUser('ROP A', '910000021', $roles['rop'], $branchA->id, 'active');
        $this->makeUser('Agent A', '910000022', $roles['agent'], $branchA->id, 'active');
        $this->makeUser('Agent B', '910000023', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?include_unassigned=1&branch_id='.$branchB->id);
        $response->assertOk();
        $response->assertJsonMissing(['phone' => '910000023']);
        $response->assertJsonCount(2, 'data');
    }

    public function test_branch_director_has_same_include_unassigned_behavior(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $director = $this->makeUser('Director A', '910000031', $roles['branch_director'], $branchA->id, 'active');
        $this->makeUser('Agent A', '910000032', $roles['agent'], $branchA->id, 'active');
        $this->makeUser('No Branch', '910000033', $roles['agent'], null, 'inactive');
        $this->makeUser('Agent B', '910000034', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($director);

        $offResponse = $this->getJson('/api/user?include_unassigned=false');
        $offResponse->assertOk()->assertJsonMissing(['phone' => '910000033']);

        $onResponse = $this->getJson('/api/user?include_unassigned=true&branch_id='.$branchB->id);
        $onResponse->assertOk();
        $onResponse->assertJsonFragment(['phone' => '910000033']);
        $onResponse->assertJsonMissing(['phone' => '910000034']);
    }

    public function test_superadmin_include_unassigned_does_not_change_global_visibility_model(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $superadmin = $this->makeUser('Superadmin', '910000041', $roles['superadmin'], $branchA->id, 'active');
        $inBranch = $this->makeUser('Agent A', '910000042', $roles['agent'], $branchA->id, 'active');
        $unassigned = $this->makeUser('No Branch', '910000043', $roles['agent'], null, 'inactive');
        $otherBranch = $this->makeUser('Agent B', '910000044', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($superadmin);

        $defaultResponse = $this->getJson('/api/user');
        $defaultResponse->assertOk();
        $defaultResponse->assertJsonFragment(['id' => $inBranch->id]);
        $defaultResponse->assertJsonFragment(['id' => $unassigned->id]);
        $defaultResponse->assertJsonFragment(['id' => $otherBranch->id]);

        $withFlagResponse = $this->getJson('/api/user?include_unassigned=1');
        $withFlagResponse->assertOk();
        $withFlagResponse->assertJsonFragment(['id' => $inBranch->id]);
        $withFlagResponse->assertJsonFragment(['id' => $unassigned->id]);
        $withFlagResponse->assertJsonFragment(['id' => $otherBranch->id]);
    }

    public function test_invalid_include_unassigned_value_falls_back_to_false_without_breaking_endpoint(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

        $rop = $this->makeUser('ROP A', '910000051', $roles['rop'], $branchA->id, 'active');
        $this->makeUser('Agent A', '910000052', $roles['agent'], $branchA->id, 'active');
        $this->makeUser('No Branch', '910000053', $roles['agent'], null, 'active');
        $this->makeUser('Agent B', '910000054', $roles['agent'], $branchB->id, 'active');

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?include_unassigned=maybe');
        $response->assertOk();
        $response->assertJsonMissing(['phone' => '910000053']);
        $response->assertJsonMissing(['phone' => '910000054']);
        $response->assertJsonCount(2, 'data');
    }

    public function test_branch_group_filter_with_include_unassigned_uses_variant_a_and_excludes_unassigned(): void
    {
        [$branchA, $branchB, $roles] = $this->seedBaseScopes();

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

        $rop = $this->makeUser('ROP A', '910000061', $roles['rop'], $branchA->id, 'active', $groupA->id);
        $groupUser = $this->makeUser('Agent Group A', '910000062', $roles['agent'], $branchA->id, 'active', $groupA->id);
        $this->makeUser('Agent Group B', '910000063', $roles['agent'], $branchB->id, 'active', $groupB->id);
        $this->makeUser('No Branch', '910000064', $roles['agent'], null, 'active', null);

        Sanctum::actingAs($rop);

        $response = $this->getJson('/api/user?include_unassigned=1&branch_group_id='.$groupA->id);
        $response->assertOk();
        $response->assertJsonFragment(['id' => $groupUser->id]);
        $response->assertJsonMissing(['phone' => '910000064']);
        $response->assertJsonMissing(['phone' => '910000063']);
    }

    private function seedBaseScopes(): array
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $roles = [
            'rop' => Role::create(['name' => 'ROP', 'slug' => 'rop']),
            'branch_director' => Role::create(['name' => 'Director', 'slug' => 'branch_director']),
            'agent' => Role::create(['name' => 'Agent', 'slug' => 'agent']),
            'superadmin' => Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']),
        ];

        return [$branchA, $branchB, $roles];
    }

    private function makeUser(
        string $name,
        string $phone,
        Role $role,
        ?int $branchId,
        string $status,
        ?int $branchGroupId = null
    ): User {
        return User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'branch_group_id' => $branchGroupId,
            'status' => $status,
        ]);
    }
}
