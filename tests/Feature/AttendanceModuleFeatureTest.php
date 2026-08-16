<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Jobs\ProcessAttendanceRawEvent;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceEvent;
use App\Models\AttendanceIngestRequest;
use App\Models\AttendanceRawEvent;
use App\Models\AttendanceWorkSchedule;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\AttendanceIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceModuleFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Schema::dropAllTables();
        $this->createBaseSchema();
        (require database_path('migrations/2026_08_16_000001_create_attendance_tables.php'))->up();
        config([
            'attendance.timezone' => 'Asia/Dushanbe',
            'attendance.duplicate_window_seconds' => 10,
            'attendance.device_rate_limit_per_minute' => 1000,
            'attendance.allowed_ips' => [],
        ]);
    }

    public function test_unknown_device_is_rejected_by_root_iclock_endpoint(): void
    {
        $this->get('/iclock/cdata?SN=UNKNOWN&options=all')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('ERROR: UNKNOWN DEVICE');
    }

    public function test_disabled_device_and_oversized_payload_are_rejected(): void
    {
        $disabled = $this->device('ZAM230-DISABLED');
        $disabled->update(['is_active' => false]);
        $this->get('/iclock/ping?SN=ZAM230-DISABLED')
            ->assertStatus(403)
            ->assertSeeText('ERROR: UNKNOWN DEVICE');

        $this->device('ZAM230-LIMIT');
        config(['attendance.device_request_max_bytes' => 10]);
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-LIMIT&table=ATTLOG',
            str_repeat('x', 11)
        )->assertStatus(413)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('ERROR: PAYLOAD TOO LARGE');
        $this->assertDatabaseCount('attendance_ingest_requests', 0);
    }

    public function test_device_options_handshake_uses_root_route_and_plain_text(): void
    {
        $device = $this->device('ZAM230-001');

        $this->get('/iclock/cdata?SN='.$device->serial_number.'&options=all')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('GET OPTION FROM: ZAM230-001')
            ->assertSeeText('TransFlag=AttLog')
            ->assertDontSee('TimeZone=');

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_attlog_ingestion_normalizes_events_and_builds_daily_summary(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-002', $context['branch'], $context['group']);
        AttendanceDeviceUser::create([
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['agent']->id,
            'is_active' => true,
            'mapped_at' => now(),
        ]);
        $payload = implode("\n", [
            $context['agent']->id."\t2026-08-17 09:05:00\t0\t15\t0\t0",
            $context['agent']->id."\t2026-08-17 18:00:00\t1\t2\t0\t0",
        ]);

        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-002&table=ATTLOG', $payload)
            ->assertOk()
            ->assertSeeText('OK: 2');

        $this->assertDatabaseCount('attendance_raw_events', 2);
        $this->assertDatabaseCount('attendance_events', 2);
        $first = AttendanceEvent::query()->orderBy('occurred_at')->firstOrFail();
        $this->assertSame('check_in', $first->event_type);
        $this->assertSame('face', $first->verification_method);
        $this->assertSame('2026-08-17 04:05:00', $first->occurred_at->format('Y-m-d H:i:s'));
        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('2026-08-17', $summary->work_date->toDateString());
        $this->assertSame(535, $summary->worked_minutes);
        $this->assertSame(5, $summary->late_minutes);
        $this->assertSame('late', $summary->status);
        $this->assertSame(2, $summary->events_count);
        $this->assertSame([$device->id], $summary->device_ids);
        $this->assertSame('2026-08-17 13:00:00', $device->fresh()->last_event_at->format('Y-m-d H:i:s'));
    }

    public function test_real_zam230_fixture_flows_from_device_api_to_daily_summary(): void
    {
        $context = $this->context();
        $device = $this->device('WCF3254200047', $context['branch'], $context['group']);
        $payload = file_get_contents(base_path('tests/Fixtures/zkteco/zam230_wcf3254200047_attlog.txt'));

        $this->postDevicePayload('/iclock/cdata?SN=WCF3254200047&table=ATTLOG', $payload)
            ->assertOk()
            ->assertSeeText('OK: 2');

        $this->assertDatabaseCount('attendance_raw_events', 2);
        $this->assertSame(2, AttendanceRawEvent::query()->where('processing_status', 'unmapped')->count());
        $this->assertDatabaseCount('attendance_events', 0);

        Sanctum::actingAs($context['admin']);
        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => '1',
            'user_id' => $context['admin']->id,
        ])->assertOk()
            ->assertJsonPath('reprocessed.processed', 2)
            ->assertJsonPath('reprocessed.unmapped', 0);

        $raw = AttendanceRawEvent::query()->orderBy('occurred_at_utc')->firstOrFail();
        $this->assertSame('1', $raw->device_user_id);
        $this->assertSame('2026-08-16 19:50:49', $raw->occurred_at_local->format('Y-m-d H:i:s'));
        $this->assertSame('processed', $raw->processing_status);

        $this->assertDatabaseCount('attendance_events', 2);
        $event = AttendanceEvent::query()->orderBy('occurred_at')->firstOrFail();
        $this->assertSame($context['admin']->id, $event->user_id);
        $this->assertSame('face', $event->verification_method);
        $this->assertSame('2026-08-16 14:50:49', $event->occurred_at->format('Y-m-d H:i:s'));
        $lastEvent = AttendanceEvent::query()->orderByDesc('occurred_at')->firstOrFail();
        $this->assertSame('check_out', $lastEvent->event_type);
        $this->assertSame('2026-08-16 15:03:09', $lastEvent->occurred_at->format('Y-m-d H:i:s'));

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('2026-08-16', $summary->work_date->toDateString());
        $this->assertSame('present', $summary->status);
        $this->assertSame('2026-08-16 14:50:49', $summary->first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-16 15:03:09', $summary->last_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(12, $summary->worked_minutes);
        $this->assertSame(0, $summary->late_minutes);

        $this->getJson('/api/attendance/daily?user_id='.$context['admin']->id.'&date_from=2026-08-16&date_to=2026-08-16')
            ->assertOk()
            ->assertJsonPath('data.0.work_date', '2026-08-16');
    }

    public function test_database_queue_accepts_first_and_normalizes_in_worker(): void
    {
        Queue::fake();
        $context = $this->context();
        $device = $this->device('ZAM230-QUEUE', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-QUEUE&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 09:00:00\t0\t15\t0"
        )->assertOk();

        $raw = AttendanceRawEvent::query()->firstOrFail();
        $this->assertSame('queued', $raw->processing_status);
        $this->assertDatabaseCount('attendance_events', 0);
        Queue::assertPushed(ProcessAttendanceRawEvent::class, fn ($job) => $job->rawEventId === $raw->id);

        (new ProcessAttendanceRawEvent($raw->id))->handle(app(AttendanceIngestionService::class));

        $this->assertDatabaseHas('attendance_raw_events', ['id' => $raw->id, 'processing_status' => 'processed']);
        $this->assertDatabaseHas('attendance_events', ['raw_event_id' => $raw->id]);
    }

    public function test_retried_payload_is_idempotent_and_near_duplicate_is_excluded_from_summary(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-003', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $line = $context['agent']->id."\t2026-08-16 09:00:00\t0\t15\t0\t0";

        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-003&table=ATTLOG', $line)->assertOk();
        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-003&table=ATTLOG', $line)->assertOk();
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-003&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 09:00:05\t0\t15\t0\t0"
        )->assertOk();

        $this->assertDatabaseCount('attendance_raw_events', 2);
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertSame(1, AttendanceEvent::query()->where('is_duplicate', true)->count());
        $this->assertSame(1, AttendanceDailySummary::query()->firstOrFail()->events_count);
    }

    public function test_unmapped_event_is_reprocessed_when_admin_creates_mapping(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-004', $context['branch'], $context['group']);
        $line = "777\t2026-08-16 09:00:00\t0\t15\t0\t0";
        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-004&table=ATTLOG', $line)->assertOk();
        $this->assertDatabaseHas('attendance_raw_events', ['processing_status' => 'unmapped']);

        Sanctum::actingAs($context['admin']);
        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => '777',
            'user_id' => $context['agent']->id,
        ])->assertOk()
            ->assertJsonPath('reprocessed.processed', 1);

        $this->assertDatabaseHas('attendance_raw_events', ['processing_status' => 'processed']);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $context['agent']->id]);
        $this->assertDatabaseCount('attendance_audit_logs', 1);
    }

    public function test_late_offline_event_recalculates_first_arrival(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-005', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-005&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 18:00:00\t1\t15\t0\t0"
        )->assertOk();
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-005&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 08:55:00\t0\t15\t0\t0"
        )->assertOk();

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('2026-08-16 03:55:00', $summary->first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(545, $summary->worked_minutes);
        $this->assertSame(0, $summary->late_minutes);
        $this->assertSame('present', $summary->status);
        $this->assertSame('2026-08-16 13:00:00', $device->fresh()->last_event_at->format('Y-m-d H:i:s'));
    }

    public function test_single_pass_is_incomplete_and_has_no_fake_work_duration(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-SINGLE', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-SINGLE&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 18:00:00\t1\t15\t0"
        )->assertOk();

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('incomplete', $summary->status);
        $this->assertNull($summary->last_out_at);
        $this->assertNull($summary->worked_minutes);
    }

    public function test_attendance_rbac_limits_agents_mop_and_rop_to_their_scope(): void
    {
        $context = $this->context();
        AttendanceDailySummary::create(['user_id' => $context['agent']->id, 'work_date' => '2026-08-16', 'status' => 'present']);
        AttendanceDailySummary::create(['user_id' => $context['otherAgent']->id, 'work_date' => '2026-08-16', 'status' => 'present']);

        Sanctum::actingAs($context['agent']);
        $this->getJson('/api/attendance/daily')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/attendance/users/'.$context['otherAgent']->id.'/daily')
            ->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_FORBIDDEN_SCOPE');

        Sanctum::actingAs($context['mop']);
        $this->getJson('/api/attendance/daily')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/daily')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($context['admin']);
        $this->getJson('/api/attendance/daily')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_any_active_role_can_participate_and_view_own_attendance(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-MARKETING', $context['branch'], $context['group']);
        $marketingRole = Role::query()->firstOrCreate(['slug' => 'marketing'], ['name' => 'Marketing']);
        $marketing = User::query()->create([
            'name' => 'Marketing User',
            'phone' => '992000009999',
            'role_id' => $marketingRole->id,
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);

        Sanctum::actingAs($context['admin']);
        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => 'marketing-1',
            'user_id' => $marketing->id,
        ])->assertOk()->assertJsonPath('data.user_id', $marketing->id);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-MARKETING&table=ATTLOG',
            "marketing-1\t2026-08-17 09:00:00\t0\t15\t0"
        )->assertOk();
        $this->assertDatabaseHas('attendance_events', ['user_id' => $marketing->id]);

        Sanctum::actingAs($marketing);
        $this->getJson('/api/attendance/me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $marketing->id)
            ->assertJsonPath('data.0.status', 'incomplete');
    }

    public function test_daily_and_csv_export_apply_device_filter_and_rbac(): void
    {
        $context = $this->context();
        $deviceA = $this->device('ZAM230-FILTER-A', $context['branch'], $context['group']);
        $deviceB = $this->device('ZAM230-FILTER-B');
        AttendanceDailySummary::query()->create([
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-16',
            'device_ids' => [$deviceA->id],
            'status' => 'present',
        ]);
        AttendanceDailySummary::query()->create([
            'user_id' => $context['otherAgent']->id,
            'work_date' => '2026-08-16',
            'device_ids' => [$deviceB->id],
            'status' => 'present',
        ]);

        Sanctum::actingAs($context['admin']);
        $this->getJson('/api/attendance/daily?device_id='.$deviceA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $context['agent']->id);

        Sanctum::actingAs($context['agent']);
        $csv = $this->get('/api/attendance/export')->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Agent A', $content);
        $this->assertStringNotContainsString('Agent B', $content);

        $context['agent']->update(['name' => '=HYPERLINK("https://example.invalid")']);
        $safeContent = $this->get('/api/attendance/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $safeContent);
    }

    public function test_only_administrators_can_manage_devices_and_changes_are_audited(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['agent']);
        $this->postJson('/api/attendance/devices', [
            'name' => 'Entrance', 'serial_number' => 'SERIAL-1',
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_ADMIN_FORBIDDEN');

        Sanctum::actingAs($context['admin']);
        $this->postJson('/api/attendance/devices', [
            'name' => 'Entrance',
            'serial_number' => 'SERIAL-1',
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'timezone' => 'Asia/Dushanbe',
        ])->assertCreated()
            ->assertJsonPath('data.serial_number', 'SERIAL-1')
            ->assertJsonPath('data.connection_status', 'offline');

        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_device.created']);
    }

    public function test_device_branch_and_group_must_remain_consistent_on_update(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-BRANCH', $context['branch'], $context['group']);
        $otherBranch = Branch::query()->where('id', '!=', $context['branch']->id)->firstOrFail();
        Sanctum::actingAs($context['admin']);

        $this->patchJson('/api/attendance/devices/'.$device->id, [
            'branch_id' => $otherBranch->id,
        ])->assertStatus(422)->assertJsonPath('details.errors.branch_group_id.0', 'Группа не принадлежит выбранному филиалу.');

        $this->patchJson('/api/attendance/devices/'.$device->id, [
            'branch_id' => null,
        ])->assertStatus(422)->assertJsonPath('details.errors.branch_id.0', 'Для группы необходимо выбрать филиал.');
    }

    public function test_communication_key_is_required_when_configured(): void
    {
        $device = $this->device('ZAM230-KEY');
        $device->communication_key = 'secret-key';
        $device->save();

        $this->get('/iclock/ping?SN=ZAM230-KEY')->assertStatus(403);
        $this->get('/iclock/ping?SN=ZAM230-KEY&PushCommKey=secret-key')->assertOk()->assertSeeText('OK');
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-KEY&table=ATTLOG&PushCommKey=secret-key',
            "777\t2026-08-16 09:00:00\t0\t15\t0"
        )->assertOk();

        $this->assertSame(
            '[redacted]',
            AttendanceIngestRequest::query()->firstOrFail()->request_meta['query']['PushCommKey']
        );
    }

    public function test_full_payload_and_rejected_rows_are_preserved_before_parsing(): void
    {
        $this->device('ZAM230-RAW');
        $payload = "broken row\n777\t2026-08-16 09:00:00\t0\t15\t0";

        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-RAW&table=ATTLOG', $payload)
            ->assertOk()
            ->assertSeeText('OK: 1');

        $request = AttendanceIngestRequest::query()->firstOrFail();
        $this->assertSame($payload, $request->raw_payload);
        $this->assertSame('partially_rejected', $request->processing_status);
        $this->assertCount(1, $request->rejected_rows);
        $this->assertDatabaseHas('attendance_raw_events', ['processing_status' => 'unmapped']);
    }

    public function test_optional_ip_allowlist_blocks_other_sources(): void
    {
        $this->device('ZAM230-IP');
        config(['attendance.allowed_ips' => ['203.0.113.10']]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get('/iclock/ping?SN=ZAM230-IP')
            ->assertStatus(403)
            ->assertSeeText('ERROR: IP NOT ALLOWED');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/iclock/ping?SN=ZAM230-IP')
            ->assertOk();
    }

    public function test_device_rate_limit_errors_remain_plain_text(): void
    {
        $this->device('ZAM230-RATE');
        config(['attendance.device_rate_limit_per_minute' => 1]);

        $this->get('/iclock/ping?SN=ZAM230-RATE')->assertOk();
        $this->get('/iclock/ping?SN=ZAM230-RATE')
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('ERROR: REQUEST FAILED');
    }

    public function test_recent_future_event_exposes_device_clock_drift(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 04:00:00', 'UTC'));
        try {
            $this->device('ZAM230-CLOCK');
            $this->postDevicePayload(
                '/iclock/cdata?SN=ZAM230-CLOCK&table=ATTLOG',
                "777\t2026-08-16 09:10:00\t0\t15\t0"
            )->assertOk();

            $device = AttendanceDevice::query()->where('serial_number', 'ZAM230-CLOCK')->firstOrFail();
            $this->assertSame(600, $device->clock_drift_seconds);
            $this->assertSame('DEVICE_CLOCK_DRIFT: 600 seconds', $device->last_error);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_admin_can_configure_schedule_for_any_active_user(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);
        $schedule = config('attendance.default_schedule');

        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $schedule,
            'holidays' => ['2026-09-09'],
            'change_reason' => 'График пилотной группы',
        ])->assertOk()->assertJsonPath('data.user_id', $context['agent']->id);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_schedule.created']);

        $invalid = $schedule;
        $invalid['8'] = ['start' => '09:00', 'end' => '18:00'];
        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $invalid,
            'change_reason' => 'Ошибка',
        ])->assertStatus(422);

        $this->putJson('/api/attendance/users/'.$context['admin']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $schedule,
            'change_reason' => 'График администратора',
        ])->assertOk()->assertJsonPath('data.user_id', $context['admin']->id);

        $context['otherAgent']->update(['status' => User::STATUS_INACTIVE]);
        $this->putJson('/api/attendance/users/'.$context['otherAgent']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $schedule,
            'change_reason' => 'Неактивный пользователь',
        ])->assertStatus(422)
            ->assertJsonPath('details.errors.user_id.0', 'Учёт посещаемости доступен только активным пользователям.');
    }

    public function test_inactive_mapped_user_does_not_receive_new_attendance(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-INACTIVE', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $context['agent']->update(['status' => User::STATUS_INACTIVE]);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-INACTIVE&table=ATTLOG',
            $context['agent']->id."\t2026-08-17 09:00:00\t0\t15\t0"
        )->assertOk();

        $this->assertDatabaseHas('attendance_raw_events', [
            'device_user_id' => (string) $context['agent']->id,
            'processing_status' => 'unmapped',
        ]);
        $this->assertDatabaseCount('attendance_events', 0);

        Sanctum::actingAs($context['admin']);
        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => '999',
            'user_id' => $context['agent']->id,
        ])->assertStatus(422)
            ->assertJsonPath('details.errors.user_id.0', 'Учёт посещаемости доступен только активным пользователям.');
    }

    public function test_offline_device_is_marked_notified_only_once_until_reconnection(): void
    {
        $this->context();
        $device = $this->device('ZAM230-OFFLINE');
        $device->forceFill([
            'created_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ])->save();

        Artisan::call('attendance:monitor-devices');
        $firstNotificationAt = $device->fresh()->offline_notified_at;
        $this->assertNotNull($firstNotificationAt);
        $this->assertDatabaseHas('notifications', [
            'user_id' => User::query()->whereHas('role', fn ($roles) => $roles->where('slug', 'admin'))->value('id'),
            'type' => 'attendance_device_offline',
            'dedupe_key' => 'attendance:device:offline:'.$device->id,
            'occurrences_count' => 1,
        ]);

        Artisan::call('attendance:monitor-devices');
        $this->assertTrue($device->fresh()->offline_notified_at->equalTo($firstNotificationAt));
        $this->assertDatabaseCount('notifications', 1);

        $this->get('/iclock/ping?SN=ZAM230-OFFLINE')->assertOk();
        $this->assertNull($device->fresh()->offline_notified_at);
    }

    public function test_stale_queued_event_is_requeued_without_flooding_fresh_jobs(): void
    {
        Queue::fake();
        $device = $this->device('ZAM230-STALE');
        $stale = AttendanceRawEvent::query()->create([
            'device_id' => $device->id,
            'event_hash' => str_repeat('a', 64),
            'device_user_id' => '777',
            'occurred_at_local' => '2026-08-16 09:00:00',
            'occurred_at_utc' => '2026-08-16 04:00:00',
            'raw_payload' => 'fixture',
            'received_at' => now()->subHour(),
            'processing_status' => 'queued',
        ]);
        $stale->forceFill(['updated_at' => now()->subHour()])->save();
        AttendanceRawEvent::query()->create([
            'device_id' => $device->id,
            'event_hash' => str_repeat('b', 64),
            'device_user_id' => '778',
            'occurred_at_local' => '2026-08-16 09:01:00',
            'occurred_at_utc' => '2026-08-16 04:01:00',
            'raw_payload' => 'fixture',
            'received_at' => now(),
            'processing_status' => 'queued',
        ]);

        Artisan::call('attendance:reprocess-pending');

        Queue::assertPushed(ProcessAttendanceRawEvent::class, 1);
        Queue::assertPushed(ProcessAttendanceRawEvent::class, fn ($job) => $job->rawEventId === $stale->id);
    }

    public function test_raw_retention_keeps_normalized_attendance(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-RETENTION', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-RETENTION&table=ATTLOG',
            $context['agent']->id."\t2026-01-01 09:00:00\t0\t15\t0"
        )->assertOk();
        $raw = AttendanceRawEvent::query()->firstOrFail();
        $request = AttendanceIngestRequest::query()->firstOrFail();
        $raw->forceFill(['received_at' => now()->subDays(100)])->save();
        $request->forceFill(['received_at' => now()->subDays(100)])->save();

        Artisan::call('attendance:prune-raw');

        $this->assertDatabaseMissing('attendance_raw_events', ['id' => $raw->id]);
        $this->assertDatabaseMissing('attendance_ingest_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('attendance_events', [
            'user_id' => $context['agent']->id,
            'raw_event_id' => null,
        ]);
    }

    public function test_mapping_with_processed_history_cannot_be_silently_reassigned(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-MAP', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-MAP&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 09:00:00\t0\t15\t0"
        )->assertOk();
        Sanctum::actingAs($context['admin']);

        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['otherAgent']->id,
        ])->assertStatus(422)->assertJsonPath(
            'details.errors.user_id.0',
            'Нельзя переназначить ID терминала после сохранения посещений. Создайте новый ID сотрудника.'
        );

        $this->assertDatabaseHas('attendance_device_users', [
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['agent']->id,
        ]);
    }

    public function test_summarize_command_materializes_absence(): void
    {
        $context = $this->context();

        Artisan::call('attendance:summarize', ['date' => '2026-08-17']);

        $this->assertDatabaseHas('attendance_daily_summaries', [
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
            'status' => 'absent',
        ]);
    }

    public function test_summarize_command_skips_individual_holiday(): void
    {
        $context = $this->context();
        AttendanceWorkSchedule::query()->create([
            'user_id' => $context['agent']->id,
            'timezone' => 'Asia/Dushanbe',
            'schedule' => config('attendance.default_schedule'),
            'holidays' => ['2026-08-17'],
        ]);

        Artisan::call('attendance:summarize', ['date' => '2026-08-17']);

        $this->assertDatabaseMissing('attendance_daily_summaries', [
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
        ]);
    }

    private function postDevicePayload(string $uri, string $payload)
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);
    }

    private function device(string $serial, ?Branch $branch = null, ?BranchGroup $group = null): AttendanceDevice
    {
        return AttendanceDevice::create([
            'name' => $serial,
            'serial_number' => $serial,
            'branch_id' => $branch?->id,
            'branch_group_id' => $group?->id,
            'timezone' => 'Asia/Dushanbe',
            'is_active' => true,
        ]);
    }

    private function map(AttendanceDevice $device, User $user): AttendanceDeviceUser
    {
        return AttendanceDeviceUser::create([
            'device_id' => $device->id,
            'device_user_id' => (string) $user->id,
            'user_id' => $user->id,
            'is_active' => true,
            'mapped_at' => now(),
        ]);
    }

    private function context(): array
    {
        $branchA = Branch::firstOrCreate(['name' => 'Branch A']);
        $branchB = Branch::firstOrCreate(['name' => 'Branch B']);
        $groupA = BranchGroup::firstOrCreate(['branch_id' => $branchA->id, 'name' => 'Group A']);
        $groupB = BranchGroup::firstOrCreate(['branch_id' => $branchB->id, 'name' => 'Group B']);
        $make = function (string $role, string $name, Branch $branch, BranchGroup $group): User {
            $roleModel = Role::firstOrCreate(['slug' => $role], ['name' => ucfirst($role)]);

            return User::firstOrCreate(['phone' => '992'.str_pad((string) (Role::count() * 100 + User::count() + 1), 9, '0', STR_PAD_LEFT)], [
                'name' => $name, 'role_id' => $roleModel->id, 'branch_id' => $branch->id,
                'branch_group_id' => $group->id, 'status' => 'active', 'auth_method' => 'password',
            ]);
        };

        return [
            'branch' => $branchA,
            'group' => $groupA,
            'agent' => $make('agent', 'Agent A', $branchA, $groupA),
            'mop' => $make('mop', 'MOP A', $branchA, $groupA),
            'rop' => $make('rop', 'ROP A', $branchA, $groupA),
            'admin' => $make('admin', 'Admin', $branchA, $groupA),
            'otherAgent' => $make('agent', 'Agent B', $branchB, $groupB),
        ];
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
