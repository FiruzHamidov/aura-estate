<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function test_role_sync_is_idempotent_and_preserves_existing_user_role(): void
    {
        $this->seed(RoleSeeder::class);

        $agentRole = Role::query()->where('slug', 'agent')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Agent User',
            'phone' => '900000001',
            'role_id' => $agentRole->id,
        ]);

        Role::query()->whereKey($agentRole->id)->update([
            'description' => 'Outdated description',
        ]);

        $this->seed(RoleSeeder::class);

        $user->refresh();
        $agentRole->refresh();

        $this->assertSame($agentRole->id, $user->role_id);
        $this->assertSame(
            count(Role::systemRoles()),
            Role::query()->whereIn('slug', collect(Role::systemRoles())->pluck('slug'))->count()
        );
        $this->assertSame('Работа с недвижимостью', $agentRole->description);
    }
}
