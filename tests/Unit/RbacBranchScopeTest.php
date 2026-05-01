<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\RbacBranchScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RbacBranchScopeTest extends TestCase
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
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
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
            $table->timestamps();
        });
    }

    public function test_it_denies_foreign_branch_group_for_rop_scope(): void
    {
        $scope = app(RbacBranchScope::class);

        $ropRole = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $branchA = Branch::create(['name' => 'A']);
        $branchB = Branch::create(['name' => 'B']);
        $groupB = BranchGroup::create(['branch_id' => $branchB->id, 'name' => 'B1']);

        $rop = User::create([
            'name' => 'ROP',
            'phone' => '960000001',
            'role_id' => $ropRole->id,
            'branch_id' => $branchA->id,
        ]);

        $this->expectException(HttpResponseException::class);
        $scope->ensureBranchGroupInUserBranchOrDeny($groupB->id, $rop);
    }
}
