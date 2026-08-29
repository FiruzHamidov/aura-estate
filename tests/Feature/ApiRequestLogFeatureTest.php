<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\ApiRequestLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRequestLogFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->withoutMiddleware(EnsureDailyReportSubmitted::class);
        config()->set('audit.api_requests.enabled', true);
        config()->set('audit.api_requests.retention_days', 90);

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->string('password')->nullable();
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

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 64)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role_slug', 64)->nullable();
            $table->string('method', 12);
            $table->string('path', 255);
            $table->string('route_name', 255)->nullable();
            $table->string('controller_action', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('client_locale', 16)->nullable();
            $table->json('request_query')->nullable();
            $table->json('request_body')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_authorized_request_is_logged_with_trace_and_user_context(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/audit/api-requests', ['X-Trace-Id' => 'trace-authorized'])
            ->assertOk();

        $this->assertDatabaseHas('api_request_logs', [
            'trace_id' => 'trace-authorized',
            'user_id' => $admin->id,
            'role_slug' => 'admin',
            'method' => 'GET',
            'path' => 'api/audit/api-requests',
            'status_code' => 200,
        ]);
    }

    public function test_unauthenticated_request_is_logged_as_401_without_user(): void
    {
        $this->getJson('/api/favorites', ['X-Trace-Id' => 'trace-guest'])
            ->assertStatus(401);

        $this->assertDatabaseHas('api_request_logs', [
            'trace_id' => 'trace-guest',
            'user_id' => null,
            'method' => 'GET',
            'path' => 'api/favorites',
            'status_code' => 401,
            'error_code' => 'UNAUTHENTICATED',
        ]);
    }

    public function test_sensitive_request_fields_are_redacted(): void
    {
        $this->postJson('/api/login', [
            'phone' => '992000000',
            'password' => 'secret-password',
            'device_name' => 'ios',
        ], ['X-Trace-Id' => 'trace-sensitive'])->assertStatus(404);

        $log = ApiRequestLog::query()->where('trace_id', 'trace-sensitive')->firstOrFail();

        $this->assertSame('[redacted]', $log->request_body['phone']);
        $this->assertSame('[redacted]', $log->request_body['password']);
        $this->assertSame('ios', $log->request_body['device_name']);
    }

    public function test_only_admin_and_superadmin_can_read_audit_logs(): void
    {
        ApiRequestLog::create([
            'trace_id' => 'trace-visible',
            'method' => 'GET',
            'path' => 'api/ping',
            'status_code' => 200,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->createUser('agent'));
        $this->getJson('/api/audit/api-requests')->assertStatus(403);

        Sanctum::actingAs($this->createUser('admin'));
        $this->getJson('/api/audit/api-requests')->assertOk();

        Sanctum::actingAs($this->createUser('superadmin'));
        $this->getJson('/api/audit/api-requests')->assertOk();
    }

    public function test_audit_log_filters_by_trace_user_and_status(): void
    {
        $admin = $this->createUser('admin');
        $agent = $this->createUser('agent');

        ApiRequestLog::create([
            'trace_id' => 'trace-match',
            'user_id' => $agent->id,
            'method' => 'POST',
            'path' => 'api/example',
            'status_code' => 500,
            'created_at' => now(),
        ]);

        ApiRequestLog::create([
            'trace_id' => 'trace-other',
            'user_id' => $admin->id,
            'method' => 'GET',
            'path' => 'api/example',
            'status_code' => 200,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/audit/api-requests?trace_id=trace-match&user_id='.$agent->id.'&status_code=500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trace_id', 'trace-match');
    }

    public function test_prune_command_deletes_logs_older_than_retention(): void
    {
        ApiRequestLog::create([
            'trace_id' => 'trace-old',
            'method' => 'GET',
            'path' => 'api/old',
            'status_code' => 200,
            'created_at' => now()->subDays(91),
        ]);

        ApiRequestLog::create([
            'trace_id' => 'trace-fresh',
            'method' => 'GET',
            'path' => 'api/fresh',
            'status_code' => 200,
            'created_at' => now()->subDays(10),
        ]);

        Artisan::call('audit:prune-api-request-logs');

        $this->assertDatabaseMissing('api_request_logs', ['trace_id' => 'trace-old']);
        $this->assertDatabaseHas('api_request_logs', ['trace_id' => 'trace-fresh']);
    }

    public function test_residential_and_lead_diagnostics_do_not_store_payloads_or_exception_text(): void
    {
        foreach (['api/lead-requests', 'api/chat', 'api/new-buildings/1/reviews', 'api/admin/new-buildings/1/imports/preview', 'api/payment-programs/1/calculate'] as $path) {
            $request = \Illuminate\Http\Request::create('/'.$path.'?search=private-contact', 'POST', ['text' => 'private-contact', 'phone' => '+992000000099']);
            $failure = new \RuntimeException('SQL values contain private-contact');
            try {
                app(\App\Http\Middleware\LogApiRequest::class)->handle($request, fn () => throw $failure);
                $this->fail('The business exception must propagate.');
            } catch (\RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }
            $log = ApiRequestLog::query()->where('path', $path)->firstOrFail();
            $this->assertNull($log->request_body);
            $this->assertNull($log->request_query);
            $this->assertNull($log->error_message);
            $this->assertSame(500, $log->status_code);
            $this->assertSame('INTERNAL_ERROR', $log->error_code);
            $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        }
    }

    public function test_intended_http_response_errors_keep_their_status_in_diagnostics(): void
    {
        $request = \Illuminate\Http\Request::create('/api/admin/new-buildings/1', 'PATCH');
        try {
            app(\App\Http\Middleware\LogApiRequest::class)->handle($request, fn () => throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => 'Denied'], 403)));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
            // The normal exception handler still owns the response.
        }
        $this->assertDatabaseHas('api_request_logs', ['path' => 'api/admin/new-buildings/1', 'status_code' => 403, 'error_code' => 'FORBIDDEN', 'error_message' => null]);
    }

    public function test_reported_residential_exceptions_do_not_fall_through_to_raw_application_logs(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $request = \Illuminate\Http\Request::create('/api/lead-requests?phone=private', 'POST');
        $request->attributes->set('trace_id', 'qa-safe-trace');
        $this->app->instance('request', $request);
        report(new \RuntimeException('SQL bindings contain private-contact'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->with('Residential request failed.', [
            'exception_type' => \RuntimeException::class,
            'route' => 'residential-or-lead',
            'trace_id' => 'qa-safe-trace',
        ]);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('log');
    }

    private function createUser(string $roleSlug): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst($roleSlug)]
        );

        return User::create([
            'name' => ucfirst($roleSlug),
            'phone' => (string) random_int(900000000, 999999999),
            'role_id' => $role->id,
            'status' => 'active',
            'auth_method' => 'password',
            'password' => Hash::make('password'),
        ]);
    }
}
