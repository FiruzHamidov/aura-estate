<?php

namespace Tests\Feature;

use App\Models\{AttendanceDailySummary, Branch, Role, User};
use Illuminate\Database\Schema\Blueprint;
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
