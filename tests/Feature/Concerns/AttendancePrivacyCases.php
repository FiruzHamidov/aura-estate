<?php

namespace Tests\Feature\Concerns;

use App\Models\AttendanceDailySummary;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRawEvent;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

trait AttendancePrivacyCases
{
    private function privacyFixture(): array
    {
        \Illuminate\Support\Facades\Schema::table('users', fn ($table) => $table->timestamp('deleted_at')->nullable());
        $c = $this->context();
        $role = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        foreach (['private' => true, 'public' => false] as $key => $hidden) {
            $c[$key] = User::create(['name' => 'Privacy '.$key, 'phone' => '99290077700'.($hidden ? '1' : '2'),
                'role_id' => $role->id, 'branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id,
                'attendance_hidden_from_others' => $hidden, 'status' => 'active']);
        }
        $c['device'] = $this->device('PRIVATE', $c['branch'], $c['group']);
        foreach (['private', 'public'] as $key) {
            $c[$key.'Mapping'] = $this->map($c['device'], $c[$key]);
            $this->postDevicePayload('/iclock/cdata?SN=PRIVATE&table=ATTLOG', $c[$key]->id."\t2026-08-17 09:07:00\t0\t15\t0")->assertOk();
        }

        return $c;
    }

    public function test_attendance_privacy_migration_initializes_only_confirmed_account_and_preserves_changes(): void
    {
        $migration = require database_path('migrations/2026_11_20_130000_add_attendance_privacy_to_users.php');
        $migration->down();
        $role = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        DB::table('users')->insert([
            ['id' => 14, 'name' => 'Munis Khizmatzoda', 'phone' => '992900777001', 'role_id' => $role->id],
            ['id' => 15, 'name' => 'Other superadmin', 'phone' => '992900777002', 'role_id' => $role->id],
        ]);
        $migration->up();
        $this->assertTrue(User::findOrFail(14)->attendance_hidden_from_others);
        $this->assertFalse(User::findOrFail(15)->attendance_hidden_from_others);
        DB::table('users')->where('id', 14)->update(['attendance_hidden_from_others' => false]);
        $migration->up();
        $this->assertFalse(User::findOrFail(14)->attendance_hidden_from_others);
        $migration->down();
        DB::table('users')->where('id', 14)->update(['name' => 'Unrelated account']);
        $migration->up();
        $this->assertFalse(User::findOrFail(14)->attendance_hidden_from_others);
    }

    public function test_attendance_privacy_setting_roundtrips_only_for_superadmins(): void
    {
        $c = $this->privacyFixture();
        Sanctum::actingAs($c['public']);
        $this->getJson('/api/user/'.$c['private']->id)->assertOk()->assertJsonPath('attendance_hidden_from_others', true);
        $this->getJson('/api/user')->assertOk()->assertJsonFragment(['id' => $c['private']->id, 'attendance_hidden_from_others' => true]);
        foreach ([false, true] as $value) {
            $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => $value])
                ->assertOk()->assertJsonPath('attendance_hidden_from_others', $value);
            $this->assertSame($value, $c['private']->fresh()->attendance_hidden_from_others);
        }
        $this->putJson('/api/user/'.$c['agent']->id, ['attendance_hidden_from_others' => true])->assertUnprocessable();
        $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => 'invalid'])->assertUnprocessable();
        foreach (['admin', 'hr', 'rop'] as $key) {
            Sanctum::actingAs($c[$key]);
            $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => false])->assertStatus($key === 'rop' ? 404 : 403);
            $this->putJson('/api/user/'.$c['agent']->id, ['attendance_hidden_from_others' => true])->assertForbidden();
        }
        Sanctum::actingAs($c['admin']);
        $this->getJson('/api/user/'.$c['private']->id)->assertOk()->assertJsonMissingPath('attendance_hidden_from_others');
        $this->assertStringNotContainsString('attendance_hidden_from_others', $this->getJson('/api/user')->assertOk()->getContent());
        $this->assertTrue($c['private']->fresh()->attendance_hidden_from_others);
    }

    public function test_attendance_privacy_create_defaults_and_promotion_do_not_disclose_old_marks(): void
    {
        $c = $this->privacyFixture();
        Sanctum::actingAs($c['public']);
        $payload = ['name' => 'New superadmin', 'phone' => '992900777003', 'role_id' => $c['public']->role_id,
            'branch_id' => $c['branch']->id, 'auth_method' => 'sms'];
        $created = $this->postJson('/api/user', $payload)->assertCreated()->json('id');
        $this->assertFalse(User::findOrFail($created)->attendance_hidden_from_others);
        $this->postJson('/api/user', [...$payload, 'phone' => '992900777004', 'attendance_hidden_from_others' => true])
            ->assertCreated()->assertJsonPath('attendance_hidden_from_others', true);
        $this->putJson('/api/user/'.$c['private']->id, ['role_id' => $c['admin']->role_id])->assertOk();
        $this->assertTrue($c['private']->fresh()->attendance_hidden_from_others);
        Sanctum::actingAs($c['admin']);
        $this->getJson('/api/attendance/users/'.$c['private']->id.'/days/2026-08-17')->assertForbidden();
        Sanctum::actingAs($c['public']);
        $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => false])->assertOk();
        $this->assertFalse($c['private']->fresh()->attendance_hidden_from_others);
    }

    public function test_attendance_privacy_excludes_rows_totals_history_and_reports_for_other_roles(): void
    {
        $c = $this->privacyFixture();
        $this->travelTo(CarbonImmutable::parse('2026-08-17T12:00:00+05:00'));
        $range = 'date_from=2026-08-17&date_to=2026-08-17';
        foreach (['admin', 'hr'] as $key) {
            Sanctum::actingAs($c[$key]);
            $this->getJson('/api/attendance/matrix?'.$range.'&role=superadmin')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $c['public']->id);
            $matrix = $this->getJson('/api/attendance/matrix?'.$range)->assertOk();
            $this->assertSame(7, $matrix->json('meta.summary.active_users'));
            $this->getJson('/api/attendance/matrix?'.$range.'&view=branches&role=superadmin')->assertOk()
                ->assertJsonPath('data.0.days.2026-08-17.checked_in_count', 1);
            foreach (['daily', 'events'] as $endpoint) {
                $this->getJson('/api/attendance/'.$endpoint.'?'.$range.'&user_id='.$c['private']->id)->assertOk()->assertJsonCount(0, 'data');
            }
            foreach (['daily', 'days/2026-08-17', 'schedule', 'leaves', 'duties'] as $endpoint) {
                $this->getJson('/api/attendance/users/'.$c['private']->id.'/'.$endpoint.'?'.$range)->assertForbidden();
            }
            $this->getJson('/api/attendance/hr-report?'.$range.'&role=superadmin')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $c['public']->id);
            $csv = $this->get('/api/attendance/export?'.$range)->assertOk()->streamedContent();
            $this->assertStringNotContainsString($c['private']->name, $csv);
            $this->assertStringContainsString($c['public']->name, $csv);
        }
        Sanctum::actingAs($c['admin']);
        $presence = collect($this->getJson('/api/attendance/presence')->assertOk()->json('data'))->pluck('user.id')->all();
        $this->assertNotContains($c['private']->id, $presence);
        $this->assertContains($c['public']->id, $presence);
        $this->assertSame(1, AttendanceEvent::where('user_id', $c['private']->id)->count());
        $this->assertSame(1, AttendanceDailySummary::where('user_id', $c['private']->id)->count());
        $this->travelBack();
    }

    public function test_attendance_privacy_is_visible_to_superadmins_and_can_be_disabled(): void
    {
        $c = $this->privacyFixture();
        $range = 'date_from=2026-08-17&date_to=2026-08-17';
        foreach (['private', 'public'] as $viewer) {
            Sanctum::actingAs($c[$viewer]);
            $this->getJson('/api/attendance/matrix?'.$range.'&role=superadmin')->assertOk()->assertJsonCount(2, 'data');
            $this->getJson('/api/attendance/users/'.$c['private']->id.'/days/2026-08-17')->assertOk()->assertJsonCount(1, 'data.events');
            $this->assertStringContainsString($c['private']->name, $this->get('/api/attendance/export?'.$range)->assertOk()->streamedContent());
        }
        $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => false])->assertOk();
        Sanctum::actingAs($c['admin']);
        $this->getJson('/api/attendance/matrix?'.$range.'&role=superadmin')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/attendance/users/'.$c['private']->id.'/days/2026-08-17')->assertOk();
    }

    public function test_attendance_privacy_cannot_be_bypassed_with_recorded_role_or_broad_role_grants(): void
    {
        $c = $this->privacyFixture();
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        DB::table('attendance_daily_summaries')->where('user_id', $c['private']->id)->update(['role_slug' => 'agent']);
        DB::table('attendance_events')->where('user_id', $c['private']->id)->update(['role_slug' => 'agent']);
        // An administrator with exactly the same grants still is not a superadmin.
        $c['admin']->role->forceFill(['permissions' => app(\App\Services\Rbac\PermissionCatalog::class)->defaults('superadmin')])->save();
        $c['rop']->role->forceFill(['permissions' => app(\App\Services\Rbac\PermissionCatalog::class)->defaults('rop')])->save();
        foreach (['admin', 'rop'] as $key) {
            Sanctum::actingAs($c[$key]->fresh('role'));
            if ($key === 'admin') {
                $this->putJson('/api/user/'.$c['private']->id, ['attendance_hidden_from_others' => false])->assertForbidden();
            }
            $response = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17')->assertOk();
            $this->assertNotContains($c['private']->id, collect($response->json('data'))->pluck('user.id')->all());
            $this->assertFalse(app(\App\Services\Attendance\AttendanceGroupScope::class)
                ->apply(AttendanceEvent::query(), $c[$key]->fresh('role'))->where('user_id', $c['private']->id)->exists());
            $this->getJson('/api/attendance/users/'.$c['private']->id.'/days/2026-08-17')->assertStatus($key === 'rop' ? 404 : 403);
        }
    }

    public function test_attendance_privacy_hides_terminal_links_and_raw_marks_even_after_mapping_removal(): void
    {
        $c = $this->privacyFixture();
        $c['privateMapping']->update(['is_active' => false]);
        $this->postDevicePayload('/iclock/cdata?SN=PRIVATE&table=ATTLOG', $c['private']->id."\t2026-08-17 18:00:00\t1\t15\t0")->assertOk();
        $raw = AttendanceRawEvent::where('processing_status', 'unmapped')->firstOrFail();
        Sanctum::actingAs($c['admin']);
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/attendance/mapping-users?search=Privacy')->assertOk()->assertJsonCount(1, 'data');
        foreach (['', '?grouped=1'] as $suffix) {
            $this->getJson('/api/attendance/unmapped-events'.$suffix)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->postJson('/api/attendance/events/reprocess', ['raw_event_id' => $raw->id])->assertNotFound();
        $this->deleteJson('/api/attendance/device-users/'.$c['privateMapping']->id)->assertNotFound();
        $this->deleteJson('/api/attendance/unmapped-events', ['device_id' => $c['device']->id, 'device_user_id' => (string) $c['private']->id, 'last_raw_event_id' => $raw->id])
            ->assertOk()->assertJsonPath('deleted_count', 0);
        $this->putJson('/api/attendance/device-users', ['device_id' => $c['device']->id, 'device_user_id' => 'new', 'user_id' => $c['private']->id])->assertNotFound();
        $c['privateMapping']->delete();
        $this->getJson('/api/attendance/unmapped-events?grouped=1')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/attendance/device-users', ['device_id' => $c['device']->id, 'device_user_id' => (string) $c['private']->id, 'user_id' => $c['agent']->id])->assertForbidden();
        Sanctum::actingAs($c['public']);
        $this->getJson('/api/attendance/unmapped-events?grouped=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_attendance_privacy_filters_both_excel_exports_and_rechecks_generated_files(): void
    {
        $c = $this->privacyFixture();
        Sanctum::actingAs($c['admin']);
        foreach (['export', 'hr-report'] as $endpoint) {
            $response = $this->get('/api/attendance/'.$endpoint.'?date_from=2026-08-17&date_to=2026-08-17&format=xlsx')->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringNotContainsString($c['private']->name, $xml);
            $this->assertStringContainsString($c['public']->name, $xml);
            $zip->close();
            DB::table('users')->where('id', $c['public']->id)->update(['attendance_hidden_from_others' => true]);
            ob_start();
            try {
                $response->baseResponse->sendContent();
                $this->fail('Private attendance file must not be sent.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
                $this->assertSame('', ob_get_contents());
            } finally {
                ob_end_clean();
            }
            $this->assertFileDoesNotExist($path);
            DB::table('users')->where('id', $c['public']->id)->update(['attendance_hidden_from_others' => false]);
        }
    }
}
