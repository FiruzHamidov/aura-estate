<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Jobs\ProcessAttendanceRawEvent;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceDuty;
use App\Models\AttendanceEvent;
use App\Models\AttendanceIngestRequest;
use App\Models\AttendanceLeave;
use App\Models\AttendanceRawEvent;
use App\Models\AttendanceWorkSchedule;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\AttendanceIngestionService;
use Carbon\CarbonImmutable;
use Database\Seeders\AttendanceHolidaySeeder;
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
        (require database_path('migrations/2026_08_16_000002_create_attendance_daily_comments_table.php'))->up();
        (require database_path('migrations/2026_08_16_000003_create_attendance_leaves_table.php'))->up();
        (require database_path('migrations/2026_08_16_000004_create_attendance_holidays_table.php'))->up();
        (require database_path('migrations/2026_08_16_000005_create_attendance_duties_table.php'))->up();
        (require database_path('migrations/2026_08_16_000006_create_attendance_global_schedules_table.php'))->up();
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

    public function test_break_statuses_are_normalized_without_becoming_arrival_or_departure(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-BREAKS', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $payload = implode("\n", [
            $context['agent']->id."\t2026-08-17 09:00:00\t0\t15\t0",
            $context['agent']->id."\t2026-08-17 12:00:00\t2\t15\t0",
            $context['agent']->id."\t2026-08-17 13:00:00\t3\t15\t0",
            $context['agent']->id."\t2026-08-17 18:00:00\t1\t15\t0",
        ]);

        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-BREAKS&table=ATTLOG', $payload)
            ->assertOk()
            ->assertSeeText('OK: 4');

        $events = AttendanceEvent::query()->orderBy('occurred_at')->get();
        $this->assertSame(['check_in', 'break_out', 'break_in', 'check_out'], $events->pluck('event_type')->all());
        $this->assertSame(['in', 'out', 'in', 'out'], $events->pluck('direction')->all());

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('2026-08-17 04:00:00', $summary->first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 13:00:00', $summary->last_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(480, $summary->worked_minutes);
        $this->assertSame(4, $summary->events_count);
        $this->assertSame('present', $summary->status);
    }

    public function test_break_event_after_checkout_does_not_replace_last_departure(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-LATE-BREAK', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $payload = implode("\n", [
            $context['agent']->id."\t2026-08-16 19:50:49\t0\t15\t0",
            $context['agent']->id."\t2026-08-16 20:03:09\t1\t15\t0",
            $context['agent']->id."\t2026-08-16 20:34:57\t3\t15\t0",
        ]);

        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-LATE-BREAK&table=ATTLOG', $payload)->assertOk();

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame('2026-08-16 15:03:09', $summary->last_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(12, $summary->worked_minutes);
        $this->assertSame(3, $summary->events_count);
        $this->assertSame('present', $summary->status);
    }

    public function test_checkout_without_arrival_is_incomplete(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-CHECKOUT-ONLY', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);

        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-CHECKOUT-ONLY&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 18:00:00\t1\t15\t0"
        )->assertOk();

        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertNull($summary->first_in_at);
        $this->assertNull($summary->last_out_at);
        $this->assertNull($summary->worked_minutes);
        $this->assertSame('incomplete', $summary->status);
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

    public function test_web_matrix_returns_complete_rows_and_enforces_table_scope(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-WEB-MATRIX', $context['branch'], $context['group']);
        $clientRole = Role::query()->firstOrCreate(['slug' => 'client'], ['name' => 'Client']);
        $client = User::query()->create([
            'name' => 'Attendance Client',
            'phone' => '992000008888',
            'role_id' => $clientRole->id,
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
        AttendanceDailySummary::query()->create([
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
            'first_in_at' => '2026-08-17 04:07:00',
            'last_out_at' => '2026-08-17 13:00:00',
            'worked_minutes' => 533,
            'late_minutes' => 7,
            'events_count' => 2,
            'status' => 'late',
        ]);
        AttendanceDailySummary::query()->create([
            'user_id' => $context['otherAgent']->id,
            'work_date' => '2026-08-17',
            'status' => 'absent',
        ]);
        AttendanceDailySummary::query()->create([
            'user_id' => $client->id,
            'work_date' => '2026-08-17',
            'late_minutes' => 15,
            'status' => 'late',
        ]);
        AttendanceEvent::query()->create([
            'user_id' => $context['agent']->id,
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'event_type' => 'check_in',
            'occurred_at' => '2026-08-17 04:07:00',
            'verification_method' => 'face',
        ]);

        Sanctum::actingAs($context['admin']);
        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users&status=late')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $context['agent']->id)
            ->assertJsonPath('data.0.days.2026-08-17.status', 'late')
            ->assertJsonPath('data.0.days.2026-08-17.verification_methods.0', 'face')
            ->assertJsonPath('meta.permissions.can_manage_devices', true)
            ->assertJsonPath('meta.summary.active_users', 6)
            ->assertJsonPath('meta.timezone', 'Asia/Dushanbe');

        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&role=client')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0);

        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=branches')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&sort=late_count')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $context['agent']->id);

        $this->getJson('/api/attendance/matrix?date_from=2026-08-01&date_to=2026-08-31&view=users&page=1&per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.date_from', '2026-08-01')
            ->assertJsonPath('meta.date_to', '2026-08-31')
            ->assertJsonCount(31, 'data.0.days');

        $this->getJson('/api/attendance/matrix?date_from=2026-08-01&date_to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('details.errors.date_to.0', 'Диапазон не может превышать 31 день.');

        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&branch_id='.$context['otherAgent']->branch_id)
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.permissions.can_view_all_branches', false);

        Sanctum::actingAs($context['agent']);
        $this->getJson('/api/attendance/matrix')->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_TABLE_FORBIDDEN');
    }

    public function test_hr_comment_uses_optimistic_locking_and_day_details_include_event_types(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-WEB-DAY', $context['branch'], $context['group']);
        AttendanceDailySummary::query()->create([
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
            'first_in_at' => '2026-08-17 04:07:00',
            'last_out_at' => '2026-08-17 13:00:00',
            'late_minutes' => 7,
            'events_count' => 3,
            'status' => 'late',
        ]);
        foreach ([
            ['check_in', '2026-08-17 04:07:00'],
            ['break_out', '2026-08-17 07:00:00'],
            ['check_out', '2026-08-17 13:00:00'],
        ] as [$type, $occurredAt]) {
            AttendanceEvent::query()->create([
                'user_id' => $context['agent']->id,
                'device_id' => $device->id,
                'device_user_id' => (string) $context['agent']->id,
                'event_type' => $type,
                'occurred_at' => $occurredAt,
                'verification_method' => 'face',
            ]);
        }

        Sanctum::actingAs($context['hr']);
        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17/comment', [
            'comment' => '<b>Предупредил о визите к врачу.</b>',
            'version' => 0,
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17/comment', [
            'comment' => 'Устаревшая запись',
            'version' => 0,
        ])->assertStatus(409)->assertJsonPath('code', 'ATTENDANCE_COMMENT_VERSION_CONFLICT');

        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17')
            ->assertOk()
            ->assertJsonPath('data.events.1.event_type', 'break_out')
            ->assertJsonPath('data.comment.comment', '<b>Предупредил о визите к врачу.</b>')
            ->assertJsonPath('data.comment.version', 1);

        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17')
            ->assertOk()->assertJsonPath('data.comment.version', 1);
        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17/comment', [
            'comment' => 'Нельзя', 'version' => 1,
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_COMMENT_FORBIDDEN');

        Sanctum::actingAs($context['hr']);
        $this->deleteJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17/comment', ['version' => 1])->assertOk();
        $this->assertDatabaseMissing('attendance_daily_comments', ['user_id' => $context['agent']->id]);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_comment.deleted']);
    }

    public function test_hr_can_view_devices_manage_mappings_and_group_unmapped_events(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-HR-SYNC', $context['branch'], $context['group']);
        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-HR-SYNC&table=ATTLOG', implode("\n", [
            "777\t2026-08-17 09:00:00\t0\t15\t0",
            "777\t2026-08-17 18:00:00\t1\t15\t0",
        ]))->assertOk();

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/devices')->assertOk()->assertJsonPath('data.0.id', $device->id);
        $this->postJson('/api/attendance/devices', ['name' => 'Forbidden', 'serial_number' => 'HR-NO'])
            ->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_ADMIN_FORBIDDEN');
        $this->getJson('/api/attendance/unmapped-events?grouped=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_user_id', '777')
            ->assertJsonPath('data.0.events_count', 2)
            ->assertJsonPath('data.0.verification_method', 'face');

        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => '777',
            'user_id' => $context['agent']->id,
        ])->assertOk()->assertJsonPath('reprocessed.processed', 2);

        $this->getJson('/api/attendance/device-users')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $context['agent']->id)
            ->assertJsonPath('data.0.processed_events_count', 2);
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

    public function test_hr_timesheet_export_is_a_valid_excel_workbook_and_excludes_clients(): void
    {
        $context = $this->context();
        $clientRole = Role::query()->firstOrCreate(['slug' => 'client'], ['name' => 'Client']);
        $client = User::query()->create([
            'name' => 'Export Client',
            'phone' => '992000007777',
            'role_id' => $clientRole->id,
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
        foreach ([$context['agent'], $client] as $user) {
            AttendanceDailySummary::query()->create([
                'user_id' => $user->id,
                'work_date' => '2026-08-17',
                'first_in_at' => '2026-08-17 04:00:00',
                'last_out_at' => '2026-08-17 13:00:00',
                'worked_minutes' => 540,
                'status' => 'present',
            ]);
        }

        Sanctum::actingAs($context['admin']);
        $response = $this->get('/api/attendance/export?format=xlsx&date_from=2026-08-17&date_to=2026-08-17')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $timesheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $details = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        $this->assertNotFalse(simplexml_load_string($workbook));
        $this->assertNotFalse(simplexml_load_string($timesheet));
        $this->assertStringContainsString('Табель', $workbook);
        $this->assertStringContainsString('Agent A', $timesheet);
        $this->assertStringContainsString('Явок', $timesheet);
        $this->assertStringNotContainsString('Export Client', $timesheet);
        $this->assertStringContainsString('04:00', $details);
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

    public function test_hr_and_admin_can_configure_schedule_for_visible_active_users(): void
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

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/schedule')
            ->assertOk()
            ->assertJsonPath('data.user_id', $context['agent']->id);
        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $schedule,
            'holidays' => ['2026-09-10'],
            'change_reason' => 'График обновлён HR',
        ])->assertOk()->assertJsonPath('data.holidays.0', '2026-09-10');

        Sanctum::actingAs($context['admin']);

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

    public function test_hr_can_manage_global_schedule_and_individual_schedule_has_priority(): void
    {
        $context = $this->context();
        $globalSchedule = config('attendance.default_schedule');
        $globalSchedule['1'] = null;

        Sanctum::actingAs($context['hr']);
        $this->putJson('/api/attendance/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $globalSchedule,
            'change_reason' => 'Общий выходной по понедельникам',
        ])->assertOk()
            ->assertJsonPath('data.source', 'global')
            ->assertJsonPath('data.schedule.1', null);

        $this->getJson('/api/attendance/schedule')
            ->assertOk()->assertJsonPath('data.change_reason', 'Общий выходной по понедельникам');
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/schedule')
            ->assertOk()->assertJsonPath('data.source', 'global')->assertJsonPath('data.schedule.1', null);

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users')
            ->assertOk()->json('data');
        $agentRow = collect($matrix)->firstWhere('user.id', $context['agent']->id);
        $this->assertFalse($agentRow['days']['2026-08-17']['is_working_day']);

        $individual = config('attendance.default_schedule');
        $this->putJson('/api/attendance/users/'.$context['agent']->id.'/schedule', [
            'timezone' => 'Asia/Dushanbe',
            'schedule' => $individual,
            'holidays' => [],
            'change_reason' => 'Индивидуальный график',
        ])->assertOk();
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/schedule')
            ->assertOk()->assertJsonPath('data.source', 'individual');

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users')
            ->assertOk()->json('data');
        $agentRow = collect($matrix)->firstWhere('user.id', $context['agent']->id);
        $this->assertTrue($agentRow['days']['2026-08-17']['is_working_day']);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_global_schedule.created']);

        Sanctum::actingAs($context['agent']);
        $this->putJson('/api/attendance/schedule', [
            'timezone' => 'Asia/Dushanbe', 'schedule' => $individual, 'change_reason' => 'Запрещено',
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_SCHEDULE_FORBIDDEN');
    }

    public function test_hr_can_manage_vacations_and_matrix_marks_leave_days(): void
    {
        $context = $this->context();
        AttendanceDailySummary::query()->create([
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
            'status' => 'absent',
        ]);

        Sanctum::actingAs($context['hr']);
        $created = $this->postJson('/api/attendance/users/'.$context['agent']->id.'/leaves', [
            'date_from' => '2026-08-17',
            'date_to' => '2026-08-21',
            'note' => 'Ежегодный отпуск',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $context['agent']->id)
            ->assertJsonPath('data.note', 'Ежегодный отпуск');

        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_leave.created']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/leaves')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/attendance/users/'.$context['agent']->id.'/leaves', [
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-22',
        ])->assertStatus(422);

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-23&view=users')
            ->assertOk()
            ->assertJsonPath('meta.permissions.can_manage_leaves', true)
            ->json('data');
        $agentRow = collect($matrix)->firstWhere('user.id', $context['agent']->id);
        $this->assertFalse($agentRow['days']['2026-08-17']['is_working_day']);
        $this->assertSame('2026-08-17', $agentRow['days']['2026-08-17']['leave']['date_from']);
        $this->assertSame(0, $agentRow['totals']['absent']);

        $leaveId = $created->json('data.id');
        $this->deleteJson('/api/attendance/users/'.$context['agent']->id.'/leaves/'.$leaveId)->assertNoContent();
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_leave.deleted']);

        Sanctum::actingAs($context['agent']);
        $this->postJson('/api/attendance/users/'.$context['agent']->id.'/leaves', [
            'date_from' => '2026-08-24',
            'date_to' => '2026-08-25',
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_LEAVE_FORBIDDEN');
    }

    public function test_hr_can_manage_duties_and_matrix_marks_duty_days(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['hr']);

        $created = $this->postJson('/api/attendance/users/'.$context['agent']->id.'/duties', [
            'date_from' => '2026-08-22',
            'date_to' => '2026-08-23',
            'note' => 'Дежурный по офису',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $context['agent']->id)
            ->assertJsonPath('data.note', 'Дежурный по офису');

        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_duty.created']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/duties')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/attendance/users/'.$context['agent']->id.'/duties', [
            'date_from' => '2026-08-23',
            'date_to' => '2026-08-24',
        ])->assertStatus(422);

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-23&view=users')
            ->assertOk()
            ->assertJsonPath('meta.permissions.can_manage_duties', true)
            ->json('data');
        $agentRow = collect($matrix)->firstWhere('user.id', $context['agent']->id);
        $this->assertSame('2026-08-22', $agentRow['days']['2026-08-22']['duty']['date_from']);

        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-22')
            ->assertOk()->assertJsonPath('data.duty.note', 'Дежурный по офису');

        $dutyId = $created->json('data.id');
        $this->deleteJson('/api/attendance/users/'.$context['agent']->id.'/duties/'.$dutyId)->assertNoContent();
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_duty.deleted']);

        Sanctum::actingAs($context['agent']);
        $this->postJson('/api/attendance/users/'.$context['agent']->id.'/duties', [
            'date_from' => '2026-08-24',
            'date_to' => '2026-08-24',
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_DUTY_FORBIDDEN');
    }

    public function test_hr_can_manage_global_holidays_and_seeded_calendar_affects_attendance(): void
    {
        $context = $this->context();
        $this->seed(AttendanceHolidaySeeder::class);
        $this->assertDatabaseCount('attendance_holidays', 17);
        $this->assertDatabaseHas('attendance_holidays', [
            'holiday_date' => '2026-03-20',
            'name' => 'Иди Рамазон',
            'kind' => 'official',
        ]);
        $this->assertDatabaseHas('attendance_holidays', [
            'holiday_date' => '2026-03-25',
            'kind' => 'transfer',
        ]);

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/holidays?year=2026')
            ->assertOk()->assertJsonCount(17, 'data');
        $created = $this->postJson('/api/attendance/holidays', [
            'holiday_date' => '2026-08-17',
            'name' => 'Корпоративный выходной',
            'note' => 'Добавлено HR',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'custom')
            ->assertJsonPath('data.name', 'Корпоративный выходной');

        $holidayId = $created->json('data.id');
        $this->putJson('/api/attendance/holidays/'.$holidayId, [
            'holiday_date' => '2026-08-17',
            'name' => 'Общий выходной компании',
            'note' => 'Обновлено HR',
        ])->assertOk()->assertJsonPath('data.name', 'Общий выходной компании');
        $this->postJson('/api/attendance/holidays', [
            'holiday_date' => '2026-08-17',
            'name' => 'Дубликат',
        ])->assertStatus(422);

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users')
            ->assertOk()->json('data');
        $agentRow = collect($matrix)->firstWhere('user.id', $context['agent']->id);
        $this->assertFalse($agentRow['days']['2026-08-17']['is_working_day']);
        $this->assertSame('Общий выходной компании', $agentRow['days']['2026-08-17']['holiday']['name']);

        Artisan::call('attendance:summarize', ['date' => '2026-08-17']);
        $this->assertDatabaseMissing('attendance_daily_summaries', [
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_holiday.created']);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_holiday.updated']);

        $this->deleteJson('/api/attendance/holidays/'.$holidayId)->assertNoContent();
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_holiday.deleted']);

        Sanctum::actingAs($context['agent']);
        $this->postJson('/api/attendance/holidays', [
            'holiday_date' => '2026-08-18',
            'name' => 'Нет доступа',
        ])->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_HOLIDAY_FORBIDDEN');
    }

    public function test_summarize_command_skips_employee_vacation(): void
    {
        $context = $this->context();
        AttendanceLeave::query()->create([
            'user_id' => $context['agent']->id,
            'date_from' => '2026-08-17',
            'date_to' => '2026-08-21',
            'created_by' => $context['hr']->id,
        ]);

        Artisan::call('attendance:summarize', ['date' => '2026-08-17']);

        $this->assertDatabaseMissing('attendance_daily_summaries', [
            'user_id' => $context['agent']->id,
            'work_date' => '2026-08-17',
        ]);
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

    public function test_mapping_can_be_deleted_and_terminal_id_reused_without_reassigning_history(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-MAP-REUSE', $context['branch'], $context['group']);
        $mapping = $this->map($device, $context['agent']);
        $this->postDevicePayload(
            '/iclock/cdata?SN=ZAM230-MAP-REUSE&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 09:00:00\t0\t15\t0"
        )->assertOk();
        Sanctum::actingAs($context['hr']);

        $this->deleteJson('/api/attendance/device-users/'.$mapping->id)
            ->assertOk()
            ->assertJsonPath('message', 'Сопоставление удалено. ZKTeco ID можно назначить другому сотруднику.');

        $this->assertDatabaseMissing('attendance_device_users', ['id' => $mapping->id]);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $context['agent']->id]);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_mapping.deleted']);

        $this->putJson('/api/attendance/device-users', [
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['otherAgent']->id,
        ])->assertOk()->assertJsonPath('data.user_id', $context['otherAgent']->id);

        $this->assertDatabaseHas('attendance_events', ['user_id' => $context['agent']->id]);
        $this->assertDatabaseHas('attendance_device_users', [
            'device_id' => $device->id,
            'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['otherAgent']->id,
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
            'hr' => $make('hr', 'HR', $branchA, $groupA),
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
