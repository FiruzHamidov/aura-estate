<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SmsVerificationCode;
use App\Models\User;
use App\Services\SmsAuthService;
use App\Services\TelegramBotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            $table->string('photo')->nullable();
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

        Schema::create('sms_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('purpose')->default('login');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['phone', 'purpose']);
        });

        config()->set('services.telegram.bot_token', '123456:TEST_BOT_TOKEN');
        config()->set('services.telegram.bot_username', 'AuraEstateTestBot');
        config()->set('services.telegram.webhook_secret', 'secret-123');
        config()->set('services.telegram.auth_ttl_seconds', 300);
    }

    public function test_widget_login_authorizes_linked_user_and_returns_sanctum_token(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://t.me/*' => Http::response('telegram-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

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
        $this->assertNotNull($user->photo);
        Storage::disk('public')->assertExists($user->photo);
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
        Storage::fake('public');
        Http::fake([
            'https://t.me/*' => Http::response('telegram-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

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
        $this->assertNotNull($user->photo);
        Storage::disk('public')->assertExists($user->photo);
    }

    public function test_link_keeps_existing_user_photo_when_telegram_is_attached(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://t.me/*' => Http::response('telegram-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000014',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'photo' => 'users/existing-photo.jpg',
        ]);

        Storage::disk('public')->put('users/existing-photo.jpg', 'existing-image');

        Sanctum::actingAs($user);

        $payload = $this->makeTelegramPayload([
            'id' => '100014',
            'first_name' => 'Keep',
            'username' => 'existing_photo_agent',
            'photo_url' => 'https://t.me/i/userpic/320/existing.jpg',
        ]);

        $this->postJson('/api/telegram/auth/link', $payload)->assertOk();

        $user->refresh();
        $this->assertSame('users/existing-photo.jpg', $user->photo);
        Storage::disk('public')->assertExists('users/existing-photo.jpg');
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

    public function test_password_reset_code_can_be_requested_via_telegram_and_used_to_set_new_password(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000101',
            'password' => bcrypt('old-password'),
            'role_id' => $role->id,
            'status' => 'active',
            'telegram_id' => '701001',
            'telegram_chat_id' => '880011',
        ]);

        $this->postJson('/api/password/reset/request', [
            'phone' => $user->phone,
            'channel' => 'telegram',
        ])->assertOk()->assertJsonPath('message', 'Код для сброса пароля отправлен в Telegram');

        $record = SmsVerificationCode::query()
            ->where('phone', $user->phone)
            ->where('purpose', SmsVerificationCode::PURPOSE_PASSWORD_RESET)
            ->first();

        $this->assertNotNull($record);

        $this->postJson('/api/password/reset/confirm', [
            'phone' => $user->phone,
            'code' => $record->code,
            'new_password' => 'new-secret',
            'new_password_confirmation' => 'new-secret',
        ])->assertOk()->assertJsonPath('message', 'Пароль успешно сброшен');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret', (string) $user->password));
        $this->assertDatabaseMissing('sms_verification_codes', [
            'phone' => $user->phone,
            'purpose' => SmsVerificationCode::PURPOSE_PASSWORD_RESET,
        ]);
    }

    public function test_password_reset_code_can_be_requested_via_sms(): void
    {
        $smsAuthService = $this->createMock(SmsAuthService::class);
        $smsAuthService->expects($this->once())
            ->method('sendVerificationCode')
            ->with('992900000102', SmsVerificationCode::PURPOSE_PASSWORD_RESET)
            ->willReturn('123456');

        $this->app->instance(SmsAuthService::class, $smsAuthService);

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Agent',
            'phone' => '992900000102',
            'password' => bcrypt('old-password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->postJson('/api/password/reset/request', [
            'phone' => $user->phone,
            'channel' => 'sms',
        ])->assertOk()->assertJsonPath('message', 'Код для сброса пароля отправлен по SMS');
    }

    public function test_widget_login_fails_after_telegram_link_is_removed_from_deleted_user(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Deleted Agent',
            'phone' => '992900000103',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'inactive',
            'telegram_id' => '701003',
            'telegram_chat_id' => '880013',
        ]);

        $user->forceFill([
            'telegram_id' => null,
            'telegram_username' => null,
            'telegram_photo_url' => null,
            'telegram_chat_id' => null,
            'telegram_linked_at' => null,
        ])->save();

        $payload = $this->makeTelegramPayload([
            'id' => '701003',
            'first_name' => 'Deleted',
        ]);

        $this->postJson('/api/telegram/auth/login', $payload)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Telegram account is not linked to any user.');
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
