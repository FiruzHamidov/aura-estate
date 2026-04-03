<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TelegramLoginToken;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
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
            $table->string('telegram_id')->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamp('telegram_linked_at')->nullable();
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone');
            $table->string('token')->unique();
            $table->string('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('used_at')->nullable();
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

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.bot_username', 'AuraEstateTestBot');
        config()->set('services.telegram.webhook_secret', 'secret-123');
    }

    public function test_it_issues_telegram_login_token_for_active_user(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000001',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/telegram/auth/request', [
            'phone' => $user->phone,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('bot_username', 'AuraEstateTestBot');

        $issuedToken = $response->json('token');

        $this->assertNotEmpty($issuedToken);
        $this->assertDatabaseHas('telegram_login_tokens', [
            'user_id' => $user->id,
            'token' => $issuedToken,
        ]);
    }

    public function test_it_confirms_telegram_login_via_webhook_and_returns_api_token(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000002',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        /** @var TelegramLoginToken $loginToken */
        $loginToken = TelegramLoginToken::create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'token' => 'auth_test_token_123',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson(
            '/api/telegram/webhook',
            [
                'message' => [
                    'text' => '/start '.$loginToken->token,
                    'chat' => ['id' => '555000111'],
                    'from' => ['id' => '777888999', 'username' => 'tg_agent'],
                ],
            ],
            ['X-Telegram-Bot-Api-Secret-Token' => 'secret-123']
        )->assertOk();

        $loginToken->refresh();
        $user->refresh();

        $this->assertNotNull($loginToken->confirmed_at);
        $this->assertSame('777888999', $user->telegram_id);
        $this->assertSame('555000111', $user->telegram_chat_id);

        $confirmResponse = $this->postJson('/api/telegram/auth/confirm', [
            'token' => $loginToken->token,
        ]);

        $confirmResponse->assertOk();
        $confirmResponse->assertJsonPath('status', 'authorized');
        $confirmResponse->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($confirmResponse->json('token'));
        $this->assertDatabaseHas('telegram_login_tokens', [
            'id' => $loginToken->id,
        ]);

        $loginToken->refresh();
        $this->assertNotNull($loginToken->used_at);
    }
}
