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

        $user = User::query()->where('phone', '992900001111')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', (string) $user->password));
        $this->assertSame($clientRole->id, $user->role_id);
    }

    public function test_app_review_account_can_sign_in_with_fixed_sms_code(): void
    {
        config()->set('auth.app_review.phone', '938080888');
        config()->set('auth.app_review.otp', '000000');

        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $user = User::create([
            'name' => 'App Review',
            'phone' => '938080888',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
            'auth_method' => 'sms',
        ]);

        $this->postJson('/api/sms/request', [
            'phone' => '938080888',
        ])->assertOk();

        $this->assertDatabaseHas('sms_verification_codes', [
            'phone' => '938080888',
            'purpose' => SmsVerificationCode::PURPOSE_LOGIN,
            'code' => '000000',
        ]);

        $response = $this->postJson('/api/sms/verify', [
            'phone' => '938080888',
            'code' => '000000',
            'device_name' => 'iPad Air 11-inch (M3)',
            'platform' => 'ios',
            'app_version' => '1.0',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($response->json('token'));
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
}
