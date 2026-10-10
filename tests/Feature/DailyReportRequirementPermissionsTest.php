<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\Rbac\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyReportRequirementPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug'); $table->json('permissions')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('role_id'); $table->timestamps();
        });
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->date('report_date'); $table->timestamp('submitted_at')->nullable();
        });
        config(['app.timezone' => 'Asia/Dushanbe', 'kpi.daily_report.enforced_roles' => ['agent', 'mop', 'intern'],
            'kpi.daily_report.enforce_submission' => true, 'kpi.daily_report.missing_report_check_time' => '11:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Dushanbe'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $slug, ?array $permissions = null): User
    {
        $role = Role::create(['slug' => $slug, 'name' => $slug]);
        $role->forceFill(['permissions' => $permissions])->save();
        return User::create(['name' => $slug, 'role_id' => $role->id]);
    }

    private function gate(User $user, string $path = '/api/private-work', string $method = 'GET'): int
    {
        $request = Request::create($path, $method);
        $request->setUserResolver(fn () => $user);
        return app(EnsureDailyReportSubmitted::class)->handle($request, fn () => response()->json(['ok' => true]))->getStatusCode();
    }

    public function test_existing_roles_and_configured_migration_defaults_are_preserved(): void
    {
        foreach (['agent', 'mop', 'intern', 'admin', 'client'] as $slug) {
            $user = $this->employee($slug);
            $required = in_array($slug, ['agent', 'mop', 'intern'], true);
            $this->assertSame($required, app(DailyReportService::class)->statusForUser($user)['daily_report_required']);
            $this->assertSame($required ? 403 : 200, $this->gate($user));
        }
        config(['kpi.daily_report.enforced_roles' => ['custom_existing']]);
        $catalog = app(PermissionCatalog::class);
        $this->assertContains('daily_reports.submission.required', $catalog->migrationDefaults('custom_existing'));
        $this->assertNotContains('daily_reports.submission.required', $catalog->migrationDefaults('agent'));
    }

    public function test_custom_requirement_can_be_revoked_and_old_agent_requirement_can_be_removed(): void
    {
        $custom = $this->employee('custom_required', ['daily_reports.submission.required']);
        $agent = $this->employee('agent', []);
        $this->assertSame(403, $this->gate($custom));
        $this->assertSame(200, $this->gate($agent));
        $custom->role->forceFill(['permissions' => []])->save();
        $fresh = $custom->fresh('role');
        $this->assertSame(200, $this->gate($fresh));
        $this->assertFalse(app(DailyReportService::class)->statusForUser($fresh)['daily_report_required']);
    }

    public function test_cutoff_submission_exceptions_and_global_switch_still_apply(): void
    {
        $user = $this->employee('custom_required', ['daily_reports.submission.required']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:59:00', 'Asia/Dushanbe'));
        $this->assertSame(200, $this->gate($user));
        Carbon::setTestNow(Carbon::parse('2026-10-10 11:00:00', 'Asia/Dushanbe'));
        $this->assertSame(403, $this->gate($user));
        $this->assertSame('2026-10-09', app(DailyReportService::class)->missingReportDate($user));
        foreach ([['/api/logout', 'POST'], ['/api/user/profile', 'GET'], ['/api/kpi/daily/my-report', 'POST'], ['/api/kpi/daily/my-report/draft', 'PUT']] as [$path, $method]) {
            $this->assertSame(200, $this->gate($user, $path, $method));
        }
        config(['kpi.daily_report.enforce_submission' => false]);
        $this->assertSame(200, $this->gate($user));
        config(['kpi.daily_report.enforce_submission' => true]);
        DB::table('daily_reports')->insert(['user_id' => $user->id, 'report_date' => '2026-10-09', 'submitted_at' => null]);
        $this->assertSame(403, $this->gate($user));
        DB::table('daily_reports')->where('user_id', $user->id)->update(['submitted_at' => now()]);
        $this->assertSame(200, $this->gate($user));
        $this->assertNull(app(DailyReportService::class)->missingReportDate($user));
    }
}
