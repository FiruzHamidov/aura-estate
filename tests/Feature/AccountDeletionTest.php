<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
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
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->string('photo')->nullable();
            $table->string('telegram_id')->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->text('telegram_photo_url')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamp('telegram_linked_at')->nullable();
            $table->rememberToken()->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_phone_hash', 64)->nullable()->index();
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

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function test_account_deletion_requires_authentication(): void
    {
        $this->postJson('/api/user/account-deletion', [
            'status' => 'inactive',
            'reason' => 'user_requested_account_deletion',
        ])->assertUnauthorized();
    }

    public function test_user_can_delete_own_account_and_credentials_stop_working(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('users/avatar.jpg', 'avatar');

        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        $user = User::create([
            'name' => 'Private Client',
            'email' => 'client@example.com',
            'phone' => '992900009999',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'status' => 'active',
            'auth_method' => 'password',
            'photo' => 'users/avatar.jpg',
            'telegram_id' => '100999',
            'telegram_username' => 'private_client',
            'telegram_photo_url' => 'https://t.me/photo.jpg',
            'telegram_chat_id' => '900999',
            'telegram_linked_at' => now(),
        ]);

        $token = $user->createToken('ios-device')->plainTextToken;
        $user->createToken('second-device');

        \DB::table('sessions')->insert([
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->withToken($token)
            ->postJson('/api/user/account-deletion', [
                'status' => 'inactive',
                'reason' => 'user_requested_account_deletion',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Account deletion completed');

        $user->refresh();

        $this->assertSame(User::STATUS_INACTIVE, $user->status);
        $this->assertSame('Deleted User', $user->name);
        $this->assertSame('deleted_'.$user->id, $user->phone);
        $this->assertNull($user->email);
        $this->assertNull($user->photo);
        $this->assertNull($user->telegram_id);
        $this->assertNull($user->remember_token);
        $this->assertNotNull($user->deleted_at);
        $this->assertNotNull($user->deletion_requested_at);
        $this->assertSame('user_requested_account_deletion', $user->deletion_reason);
        $this->assertSame($user->id, $user->deleted_by_user_id);
        $this->assertSame(User::accountDeletionPhoneHash('992900009999'), $user->deletion_phone_hash);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
        Storage::disk('public')->assertMissing('users/avatar.jpg');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/user/profile')->assertUnauthorized();

        $this->postJson('/api/login', [
            'phone' => '992900009999',
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Account has been deleted');
    }
}
