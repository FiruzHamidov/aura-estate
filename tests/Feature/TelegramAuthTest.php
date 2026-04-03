<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
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
            $table->text('telegram_photo_url')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamp('telegram_linked_at')->nullable();
            $table->rememberToken()->nullable();
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

        config()->set('services.telegram.bot_token', '123456:TEST_BOT_TOKEN');
        config()->set('services.telegram.bot_username', 'AuraEstateTestBot');
        config()->set('services.telegram.webhook_secret', 'secret-123');
        config()->set('services.telegram.auth_ttl_seconds', 300);
    }

    public function test_widget_login_authorizes_linked_user_and_returns_sanctum_token(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000001',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'telegram_id' => '100001',
        ]);

        $payload = $this->makeTelegramPayload([
            'id' => '100001',
            'first_name' => 'Aru',
            'last_name' => 'Estate',
            'username' => 'aura_agent',
            'photo_url' => 'https://t.me/i/userpic/320/test.jpg',
        ]);

        $response = $this->postJson('/api/telegram/auth/login', $payload);

        $response->assertOk();
        $response->assertJsonPath('status', 'authorized');
        $response->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($response->json('token'));

        $user->refresh();
        $this->assertSame('aura_agent', $user->telegram_username);
        $this->assertSame('https://t.me/i/userpic/320/test.jpg', $user->telegram_photo_url);
    }

    public function test_widget_login_rejects_invalid_hash(): void
    {
        $payload = $this->makeTelegramPayload([
            'id' => '100002',
            'first_name' => 'Bad',
        ]);
        $payload['hash'] = str_repeat('a', 64);

        $response = $this->postJson('/api/telegram/auth/login', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid Telegram authorization hash.');
    }

    public function test_widget_login_rejects_expired_auth_date(): void
    {
        $payload = $this->makeTelegramPayload([
            'id' => '100003',
            'first_name' => 'Late',
            'auth_date' => now()->subMinutes(20)->timestamp,
        ]);

        $response = $this->postJson('/api/telegram/auth/login', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Telegram authorization payload expired.');
    }

    public function test_authenticated_user_can_link_telegram_widget_account(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000004',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $payload = $this->makeTelegramPayload([
            'id' => '100004',
            'first_name' => 'Link',
            'username' => 'linked_agent',
        ]);

        $response = $this->postJson('/api/telegram/auth/link', $payload);

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.telegram_id', '100004');

        $user->refresh();
        $this->assertSame('100004', $user->telegram_id);
        $this->assertSame('linked_agent', $user->telegram_username);
    }

    public function test_webhook_requires_secret_token_and_links_chat_id_via_start(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000005',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'telegram_id' => '100005',
            'telegram_username' => 'notify_agent',
        ]);

        $payload = [
            'message' => [
                'text' => '/start',
                'chat' => ['id' => '555000111'],
                'from' => ['id' => '100005', 'username' => 'notify_agent'],
            ],
        ];

        $this->postJson('/api/telegram/webhook', $payload)->assertForbidden();

        $this->postJson(
            '/api/telegram/webhook',
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => 'secret-123']
        )->assertOk();

        $user->refresh();
        $this->assertSame('555000111', $user->telegram_chat_id);
    }

    public function test_telegram_bot_service_sends_notification(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000006',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'telegram_id' => '100006',
            'telegram_chat_id' => '555000222',
        ]);

        $response = app(TelegramBotService::class)->sendUserMessage($user, 'Тестовое уведомление');

        $this->assertTrue($response['ok']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '555000222'
                && $request['text'] === 'Тестовое уведомление';
        });
    }

    private function makeTelegramPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'id' => '999001',
            'first_name' => 'Aura',
            'last_name' => 'Estate',
            'username' => 'aura_user',
            'photo_url' => 'https://t.me/i/userpic/320/default.jpg',
            'auth_date' => now()->timestamp,
        ], $overrides);

        $hashSource = $payload;
        ksort($hashSource);

        $dataCheckString = collect($hashSource)
            ->filter(fn ($value, $key) => $key !== 'hash' && $value !== null && $value !== '')
            ->map(fn ($value, $key) => sprintf('%s=%s', $key, $value))
            ->implode("\n");

        $payload['hash'] = hash_hmac(
            'sha256',
            $dataCheckString,
            hash('sha256', (string) config('services.telegram.bot_token'), true)
        );

        return $payload;
    }
}
