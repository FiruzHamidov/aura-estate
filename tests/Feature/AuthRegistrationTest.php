<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('report_date');
            $table->text('summary')->nullable();
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

    public function test_registration_assigns_client_role_even_if_another_role_is_passed(): void
    {
        $clientRole = Role::create(['name' => 'Клиент', 'slug' => 'client']);
        $adminRole = Role::create(['name' => 'Админ', 'slug' => 'admin']);

        $response = $this->postJson('/api/register', [
            'name' => 'Test Client',
            'phone' => '992900001111',
            'email' => 'client@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $adminRole->id,
            'role' => 'admin',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('user.role.slug', 'client');
        $this->assertNotEmpty($response->json('token'));

        $user = User::query()->where('phone', '992900001111')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', (string) $user->password));
        $this->assertSame($clientRole->id, $user->role_id);
    }
}
