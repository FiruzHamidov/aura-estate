<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SmsVerificationCode;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
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

        Schema::create('sms_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('purpose')->default('login');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['phone', 'purpose']);
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

        $user = User::query()->where('phone', '900001111')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', (string) $user->password));
        $this->assertSame($clientRole->id, $user->role_id);
    }

    public function test_app_review_account_requires_a_fresh_code(): void
    {
        $this->postJson('/api/sms/verify', [
            'phone' => '938080888',
            'code' => '000000',
        ])->assertUnauthorized();
    }

    public function test_mobile_client_filters_route_is_available_for_authenticated_users(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'Mobile Agent',
            'phone' => '992900002222',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'auth_method' => 'sms',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/clients/filters');

        $response->assertOk();
        $response->assertJsonStructure([
            'contact_kinds',
            'statuses',
            'client_types',
            'client_sources',
            'need_types',
            'need_statuses',
            'repair_types',
            'property_types',
            'branches',
            'branch_groups',
            'responsible_agents',
        ]);
    }

    public function test_login_resolves_active_agent_instead_of_inactive_national_duplicate(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $client = Role::create(['name' => 'Client', 'slug' => 'client']);
        $agent = User::create(['name' => 'Agent', 'phone' => '+992901234567', 'password' => Hash::make('agent-password'), 'role_id' => $role->id, 'status' => 'active']);
        User::create(['name' => 'Old client', 'phone' => '901234567', 'password' => Hash::make('client-password'), 'role_id' => $client->id, 'status' => 'inactive']);
        foreach (['901234567', '+992901234567', '992901234567', '+992 90 123-45-67'] as $phone) {
            $this->postJson('/api/login', ['phone' => $phone, 'password' => 'agent-password'])
                ->assertOk()->assertJsonPath('user.id', $agent->id);
        }
        $this->postJson('/api/login', ['phone' => '901234567', 'password' => 'client-password'])->assertUnauthorized();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_password_disambiguates_active_legacy_accounts_without_changing_phone_only_auth(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $first = User::create(['name' => 'First', 'phone' => '901234567', 'password' => Hash::make('first-password'), 'role_id' => $role->id, 'status' => 'active']);
        $second = User::create(['name' => 'Second', 'phone' => '+992901234567', 'password' => Hash::make('second-password'), 'role_id' => $role->id, 'status' => 'active']);
        User::create(['name' => 'Inactive', 'phone' => '992901234567', 'password' => Hash::make('inactive-password'), 'role_id' => $role->id, 'status' => 'inactive']);
        foreach (['901234567', '+992901234567', '+992 90 123-45-67'] as $phone) {
            $this->postJson('/api/login', ['phone' => $phone, 'password' => 'first-password'])->assertOk()->assertJsonPath('user.id', $first->id);
            $this->postJson('/api/login', ['phone' => $phone, 'password' => 'second-password'])->assertOk()->assertJsonPath('user.id', $second->id);
        }
        $tokens = \DB::table('personal_access_tokens')->count();
        foreach (['wrong-password', 'inactive-password'] as $password) {
            $this->postJson('/api/login', ['phone' => '901234567', 'password' => $password])->assertUnauthorized();
        }
        $this->postJson('/api/sms/request', ['phone' => '901234567'])->assertStatus(409)->assertJsonPath('code', 'PHONE_IDENTITY_CONFLICT');
        $this->assertDatabaseCount('personal_access_tokens', $tokens);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_two_active_equivalent_accounts_require_manual_resolution(): void
    {
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        foreach (['901234567', '+992901234567'] as $phone) {
            User::create(['name' => 'Duplicate', 'phone' => $phone, 'password' => Hash::make('password123'), 'role_id' => $role->id, 'status' => 'active']);
        }
        $this->postJson('/api/login', ['phone' => '901234567', 'password' => 'password123'])
            ->assertStatus(409)->assertJsonPath('code', 'PHONE_IDENTITY_CONFLICT');
        $this->postJson('/api/sms/request', ['phone' => '901234567'])
            ->assertStatus(409)->assertJsonPath('code', 'PHONE_IDENTITY_CONFLICT');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_rejects_equivalent_formatted_existing_phone(): void
    {
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        User::create(['name' => 'Existing', 'phone' => '+992 90 123-45-67', 'role_id' => $role->id, 'status' => 'active']);
        $this->postJson('/api/register', ['name' => 'New', 'phone' => '901234567', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['phone']]]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_sms_with_changed_phone_format_uses_existing_agent_without_creating_client(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        Role::create(['name' => 'Client', 'slug' => 'client']);
        $agent = User::create(['name' => 'Agent', 'phone' => '+992901234567', 'role_id' => $role->id, 'status' => 'active']);
        $service = app(\App\Services\SmsAuthService::class);
        $code = $service->storeVerificationCode('+992901234567', 'login');
        $this->postJson('/api/sms/verify', ['phone' => '901234567', 'code' => $code])
            ->assertOk()->assertJsonPath('user.id', $agent->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_inactive_account_remains_blocked_in_any_format(): void
    {
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        User::create(['name' => 'Inactive', 'phone' => '+992901234567', 'password' => Hash::make('password123'), 'role_id' => $role->id, 'status' => 'inactive']);
        $this->postJson('/api/login', ['phone' => '901234567', 'password' => 'password123'])->assertForbidden();
    }

    public function test_failed_otp_verification_is_throttled_across_phone_formats(): void
    {
        $codes = ['901234567', '+992901234567', '+992 90 123-45-67'];
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/sms/verify', ['phone' => $codes[$attempt % 3], 'code' => '000000'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/sms/verify', ['phone' => $codes[0], 'code' => '000000'])->assertStatus(429);
    }

    public function test_foreign_phone_cannot_authenticate_an_equal_length_tajik_number(): void
    {
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        $victim = User::create(['name' => 'TJ', 'phone' => '376812345', 'role_id' => $role->id, 'status' => 'active']);
        $service = app(\App\Services\SmsAuthService::class);
        $code = $service->storeVerificationCode('+376812345', 'login');
        $this->assertFalse($service->verifyCode('+992376812345', $code));
        $response = $this->postJson('/api/sms/verify', ['phone' => '+376812345', 'code' => $code])->assertOk();
        $this->assertNotSame($victim->id, $response->json('user.id'));
        $this->assertSame('+376812345', $response->json('user.phone'));
    }

    public function test_reissued_sms_code_replaces_legacy_format_and_uniqueness_ignores_only_self(): void
    {
        $role = Role::create(['name' => 'Client', 'slug' => 'client']);
        $user = User::create(['name' => 'Existing', 'phone' => '+992901234567', 'role_id' => $role->id, 'status' => 'active']);
        $rule = new \App\Rules\UniqueUserPhone($user->id);
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['phone' => '901234567'], ['phone' => [$rule]])->passes());
        $this->assertFalse(\Illuminate\Support\Facades\Validator::make(['phone' => '901234567'], ['phone' => [new \App\Rules\UniqueUserPhone]])->passes());
        SmsVerificationCode::create(['phone' => '+992901234567', 'purpose' => 'login', 'code' => 'old-code', 'expires_at' => now()->addMinutes(5)]);
        $service = app(\App\Services\SmsAuthService::class);
        $code = $service->storeVerificationCode('901234567', 'login');
        $this->assertFalse($service->verifyCode('+992901234567', 'old-code'));
        $this->assertTrue($service->verifyCode('+992901234567', $code));
        $this->assertFalse($service->verifyCode('+992901234567', $code));
        $this->assertDatabaseCount('sms_verification_codes', 0);
    }
}
