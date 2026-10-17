<?php

namespace Tests\Feature;

use App\Models\{AttendanceDailySummary, AttendanceDevice, AttendanceEvent, Branch, Role, User};
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class SecurityAttendanceFeatureTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.firebase.credentials' => '']);
        $this->withoutMiddleware([\App\Http\Middleware\EnsureDailyReportSubmitted::class, \App\Http\Middleware\LogApiRequest::class]);
        Schema::dropAllTables();
        $this->createBaseSchema();
        foreach ([
            '2026_08_16_000001_create_attendance_tables.php',
            '2026_08_16_000002_create_attendance_daily_comments_table.php',
            '2026_08_16_000003_create_attendance_leaves_table.php',
            '2026_08_16_000004_create_attendance_holidays_table.php',
            '2026_08_16_000005_create_attendance_duties_table.php',
            '2026_08_16_000006_create_attendance_global_schedules_table.php',
            '2026_09_08_120000_create_rop_group_access.php',
            '2026_09_08_140000_add_attendance_context_groups.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
        (require database_path('migrations/2026_09_17_120000_add_security_attendance_branches_to_users.php'))->up();
    }

    private function securityFixture(): array
    {
        $a = Branch::create(['name' => 'Allowed A']);
        $b = Branch::create(['name' => 'Allowed B']);
        $c = Branch::create(['name' => 'Hidden C']);
        $make = function ($role, $branch, $name) {
            $r = Role::firstOrCreate(['slug' => $role], ['name' => $role]);
            return User::create(['name' => $name, 'phone' => '990'.(User::count()+1), 'role_id' => $r->id, 'branch_id' => $branch->id, 'status' => 'active']);
        };
        $sb = $make('security', $a, 'SB');
        $one = $make('agent', $a, 'Employee A');
        $two = $make('agent', $b, 'Employee B');
        $hidden = $make('agent', $c, 'Hidden employee');
        foreach ([$one, $two, $hidden] as $user) {
            AttendanceDailySummary::create(['user_id' => $user->id, 'work_date' => '2026-09-17', 'status' => 'late', 'late_minutes' => 5]);
        }
        return compact('a', 'b', 'c', 'sb', 'one', 'two', 'hidden');
    }

    public function test_security_branch_scope_filters_details_exports_and_revocation(): void
    {
        extract($this->securityFixture());
        $sb->update(['security_attendance_branch_ids' => [$a->id, $b->id]]);
        Sanctum::actingAs($sb);
        $range = 'date_from=2026-09-17&date_to=2026-09-17';
        $result = $this->getJson('/api/attendance/matrix?'.$range)->assertOk();
        $ids = collect($result->json('data'))->pluck('user.id')->all();
        $this->assertContains($one->id, $ids);
        $this->assertContains($two->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $result->assertJsonPath('meta.permissions.can_view_all_branches', false)
            ->assertJsonPath('meta.permissions.can_manage_schedules', false)
            ->assertJsonPath('meta.permissions.can_comment_late_day', false)
            ->assertJsonCount(2, 'meta.selectable_branches');
        $this->getJson('/api/attendance/matrix?'.$range.'&branch_id='.$c->id)->assertOk()->assertJsonCount(0, 'data');
        $filtered = $this->getJson('/api/attendance/matrix?'.$range.'&branch_ids[]='.$b->id)->assertOk();
        $this->assertSame([$two->id], collect($filtered->json('data'))->pluck('user.id')->all());
        $this->getJson('/api/attendance/users/'.$hidden->id.'/days/2026-09-17')->assertForbidden();
        $this->getJson('/api/attendance/users/'.$one->id.'/days/2026-09-17')->assertOk();
        $this->getJson('/api/attendance/daily?'.$range.'&user_id='.$hidden->id)->assertOk()->assertJsonCount(0, 'data');
        $csv = $this->get('/api/attendance/export?format=csv&'.$range.'&branch_ids[]='.$b->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Employee B', $csv);
        $this->assertStringNotContainsString('Employee A', $csv);
        $this->assertStringNotContainsString('Hidden employee', $csv);
        $xlsx = $this->get('/api/attendance/export?format=xlsx&'.$range.'&branch_ids[]='.$b->id)->assertOk();
        $zip = new \ZipArchive;
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Employee B', $sheet);
        $this->assertStringNotContainsString('Employee A', $sheet);
        $this->assertStringNotContainsString('Hidden employee', $sheet);
        $zip->close();
        unlink($path);
        $sb->update(['security_attendance_branch_ids' => []]);
        $this->getJson('/api/attendance/matrix?'.$range)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/users/'.$one->id.'/days/2026-09-17')->assertForbidden();
    }

    public function test_security_excludes_dismissed_employees_from_attendance_and_preserves_history(): void
    {
        extract($this->securityFixture());
        $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dushanbe'));
        $sb->update(['security_attendance_branch_ids' => [$a->id, $b->id]]);
        foreach ([$one, $two, $hidden] as $employee) {
            $device = AttendanceDevice::create(['name' => 'Entry '.$employee->id, 'serial_number' => 'SECURITY-'.$employee->id,
                'branch_id' => $employee->branch_id, 'timezone' => 'Asia/Dushanbe', 'is_active' => true]);
            AttendanceDailySummary::create(['user_id' => $employee->id, 'work_date' => '2026-08-17',
                'status' => 'late', 'late_minutes' => 5]);
            foreach (['2026-08-17', '2026-09-17'] as $date) {
                AttendanceEvent::create(['user_id' => $employee->id, 'device_id' => $device->id, 'branch_id' => $employee->branch_id,
                    'device_user_id' => (string) $employee->id, 'event_type' => 'check_in', 'occurred_at' => $date.'T04:05:00Z']);
            }
        }
        Sanctum::actingAs($sb);
        $url = '/api/attendance/matrix?date_from=2026-09-17&date_to=2026-09-17';
        $before = $this->getJson($url)->assertOk()->json('meta.summary.active_users');
        $this->getJson($url.'&search=Employee')->assertOk()->assertJsonCount(2, 'data');
        // Dismiss after the tab has already been loaded: the next request must use the new status.
        DB::table('users')->where('id', $one->id)->update(['status' => User::STATUS_INACTIVE]);
        $snapshot = collect(['users', 'attendance_daily_summaries', 'attendance_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.summary.active_users', $before - 1)
            ->assertJsonPath('meta.summary.checked_in_today', 1)->assertJsonPath('meta.summary.late_today', 1);
        foreach (['2026-08-17', '2026-09-17'] as $date) {
            $range = 'date_from='.$date.'&date_to='.$date;
            $this->getJson('/api/attendance/matrix?'.$range.'&search=Employee&per_page=1')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $two->id)
                ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('meta.pagination.last_page', 1);
            $branches = $this->getJson('/api/attendance/matrix?'.$range.'&view=branches')->assertOk()->json('data');
            $this->assertSame(1, collect($branches)->sum(fn ($row) => $row['days'][$date]['checked_in_count']));
            foreach (['daily', 'events'] as $endpoint) {
                $this->getJson('/api/attendance/'.$endpoint.'?'.$range)->assertOk()->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.user_id', $two->id);
                $this->getJson('/api/attendance/'.$endpoint.'?'.$range.'&user_id='.$one->id)->assertOk()->assertJsonCount(0, 'data');
            }
            $this->getJson('/api/attendance/matrix?'.$range.'&user_id='.$one->id)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/attendance/users/'.$one->id.'/days/'.$date)->assertForbidden();
            $this->getJson('/api/attendance/users/'.$one->id.'/daily?'.$range)->assertForbidden();
            $this->getJson('/api/attendance/users/'.$two->id.'/days/'.$date)->assertOk();
            $this->getJson('/api/attendance/users/'.$hidden->id.'/days/'.$date)->assertForbidden();
            $csv = $this->get('/api/attendance/export?format=csv&'.$range)->assertOk()->streamedContent();
            $xlsx = $this->get('/api/attendance/export?format=xlsx&'.$range)->assertOk();
            $path = $xlsx->baseResponse->getFile()->getPathname();
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            unlink($path);
            foreach ([$csv, $sheet] as $output) {
                $this->assertStringContainsString($two->name, $output);
                $this->assertStringNotContainsString($one->name, $output);
                $this->assertStringNotContainsString($hidden->name, $output);
            }
        }
        foreach ($snapshot as $table => $rows) $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        $this->travelBack();
    }

    public function test_security_primary_branch_and_optional_presence_exclude_dismissed_employees(): void
    {
        extract($this->securityFixture());
        DB::table('users')->where('id', $one->id)->update(['status' => User::STATUS_INACTIVE]);
        Sanctum::actingAs($sb);
        $this->getJson('/api/attendance/matrix?search=Employee')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/presence')->assertForbidden();
        // A configured presence grant must retain the same employee/branch restrictions.
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $sb->role->forceFill(['permissions' => ['attendance.table.view', 'attendance.presence.view', 'attendance.scope.assigned_branches']])->save();
        $sb->update(['security_attendance_branch_ids' => [$a->id, $b->id]]);
        Sanctum::actingAs($sb->fresh());
        $ids = collect($this->getJson('/api/attendance/presence')->assertOk()->json('data'))->pluck('user.id')->all();
        $this->assertContains($two->id, $ids);
        $this->assertNotContains($one->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $sb->update(['security_attendance_branch_ids' => []]);
        Sanctum::actingAs($sb->fresh());
        $this->getJson('/api/attendance/presence')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/matrix')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_security_is_read_only_and_legacy_account_uses_primary_branch(): void
    {
        extract($this->securityFixture());
        Sanctum::actingAs($sb);
        $this->getJson('/api/attendance/matrix')->assertOk()->assertJsonCount(1, 'meta.selectable_branches');
        $this->getJson('/api/attendance/users/'.$two->id.'/days/2026-09-17')->assertForbidden();
        foreach (['schedule' => 'PUT', 'leaves' => 'POST', 'duties' => 'POST', 'days/2026-09-17/comment' => 'PUT'] as $path => $method) {
            $this->json($method, '/api/attendance/users/'.$one->id.'/'.$path, [])->assertForbidden();
        }
        $this->postJson('/api/attendance/holidays', [])->assertForbidden();
        $this->getJson('/api/attendance/devices')->assertForbidden();
        $this->putJson('/api/attendance/device-users', [])->assertForbidden();
    }

    public function test_security_assignments_require_admin_and_validate_branches(): void
    {
        extract($this->securityFixture());
        $adminRole = Role::create(['slug' => 'admin', 'name' => 'Admin']);
        $admin = User::create(['name' => 'Admin', 'phone' => 'admin', 'role_id' => $adminRole->id, 'branch_id' => $a->id]);
        Sanctum::actingAs($admin);
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('security_attendance_branch_ids', [$a->id, $b->id]);
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [999999]])->assertUnprocessable();
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [$a->id, $a->id]])->assertUnprocessable();
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => []])->assertOk();
        $this->assertSame([], $sb->fresh()->security_attendance_branch_ids);
        Sanctum::actingAs($sb);
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [$c->id]])->assertForbidden();
        $this->assertSame([], $sb->fresh()->security_attendance_branch_ids);
    }
    public function test_custom_role_can_use_assigned_attendance_branches_and_revocation_removes_scope(): void
    {
        extract($this->securityFixture());
        $adminRole = Role::create(['slug' => 'admin', 'name' => 'Admin']);
        $admin = User::create(['name' => 'Admin', 'phone' => 'admin', 'role_id' => $adminRole->id, 'branch_id' => $a->id]);
        $custom = Role::create(['slug' => 'custom_attendance', 'name' => 'Custom attendance']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $custom->forceFill(['permissions' => ['attendance.table.view', 'attendance.scope.assigned_branches']])->save();
        $sb->update(['role_id' => $custom->id]);
        Sanctum::actingAs($admin->fresh());
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [$a->id, $b->id]])->assertOk();
        Sanctum::actingAs($sb->fresh());
        $url = '/api/attendance/matrix?date_from=2026-09-17&date_to=2026-09-17';
        $response = $this->getJson($url)->assertOk()->assertJsonCount(2, 'meta.selectable_branches');
        $ids = collect($response->json('data'))->pluck('user.id')->all();
        $this->assertContains($one->id, $ids);
        $this->assertContains($two->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->getJson('/api/attendance/users/'.$two->id.'/days/2026-09-17')->assertOk();
        $this->getJson('/api/attendance/users/'.$hidden->id.'/days/2026-09-17')->assertForbidden();
        $custom->forceFill(['permissions' => ['attendance.table.view']])->save();
        Sanctum::actingAs($sb->fresh());
        $response = $this->getJson($url)->assertOk();
        $this->assertSame([$sb->id], collect($response->json('data'))->pluck('user.id')->all());
        $this->getJson('/api/attendance/users/'.$two->id.'/days/2026-09-17')->assertForbidden();
        Sanctum::actingAs($admin->fresh());
        $this->putJson('/api/user/'.$sb->id, ['security_attendance_branch_ids' => [$c->id]])->assertUnprocessable();
    }

    private function createBaseSchema(): void
    {
        Schema::create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('slug')->unique(), $t->timestamps()]);
        Schema::create('branches', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('branch_groups', fn (Blueprint $t) => [$t->id(), $t->foreignId('branch_id'), $t->string('name'), $t->string('contact_visibility_mode')->default('group_only'), $t->timestamps()]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->unique();
            $t->foreignId('role_id');
            $t->foreignId('branch_id')->nullable();
            $t->foreignId('branch_group_id')->nullable();
            $t->string('status')->default('active');
            $t->string('auth_method')->default('password');
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('actor_id')->nullable();
            $t->string('type', 100)->nullable();
            $t->string('category', 32)->nullable();
            $t->string('status', 20)->default('unread');
            $t->unsignedTinyInteger('priority')->default(2);
            $t->json('channels')->nullable();
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('action_url')->nullable();
            $t->string('action_type', 50)->nullable();
            $t->string('dedupe_key')->nullable();
            $t->unsignedInteger('occurrences_count')->default(1);
            $t->timestamp('last_occurred_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
    }
}
