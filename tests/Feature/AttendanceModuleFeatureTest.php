<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Jobs\ProcessAttendanceRawEvent;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceDuty;
use App\Models\AttendanceEvent;
use App\Models\AttendanceIngestRequest;
use App\Models\AttendanceHoliday;
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
        config(['services.firebase.credentials' => '']);
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Schema::dropAllTables();
        $this->createBaseSchema();
        (require database_path('migrations/2026_08_16_000001_create_attendance_tables.php'))->up();
        (require database_path('migrations/2026_08_16_000002_create_attendance_daily_comments_table.php'))->up();
        (require database_path('migrations/2026_08_16_000003_create_attendance_leaves_table.php'))->up();
        (require database_path('migrations/2026_08_16_000004_create_attendance_holidays_table.php'))->up();
        (require database_path('migrations/2026_08_16_000005_create_attendance_duties_table.php'))->up();
        (require database_path('migrations/2026_08_16_000006_create_attendance_global_schedules_table.php'))->up();
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        (require database_path('migrations/2026_09_08_140000_add_attendance_context_groups.php'))->up();
        (require database_path('migrations/2026_10_17_140000_create_attendance_device_user_profiles.php'))->up();
        (require database_path('migrations/2026_10_17_160000_create_attendance_discarded_events.php'))->up();
        config([
            'attendance.timezone' => 'Asia/Dushanbe',
            'attendance.duplicate_window_seconds' => 10,
            'attendance.device_rate_limit_per_minute' => 1000,
            'attendance.allowed_ips' => [],
        ]);
    }

    public function test_presence_uses_today_marks_and_respects_roles_and_group_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T12:00:00+05:00'));
        $c = $this->context();
        $device = $this->device('PRESENCE', $c['branch'], $c['group']);
        $this->map($device, $c['agent']);
        $this->postDevicePayload('/iclock/cdata?SN=PRESENCE&table=ATTLOG', $c['agent']->id."\t2026-09-30 10:00:00\t2\t15\t0")->assertOk();
        // Already imported old classifications are read using the raw terminal code.
        AttendanceEvent::query()->update(['event_type' => 'break_out']);
        $this->postDevicePayload('/iclock/cdata?SN=PRESENCE&table=ATTLOG', $c['agent']->id."\t2026-09-30 13:00:00\t3\t15\t0")->assertOk();
        foreach (['agent', 'mop', 'hr'] as $role) {
            Sanctum::actingAs($c[$role]);
            $this->getJson('/api/attendance/presence')->assertForbidden();
        }
        Sanctum::actingAs($c['admin']);
        $data = collect($this->getJson('/api/attendance/presence')->assertOk()->assertJsonPath('meta.date', '2026-09-30')->json('data'))->keyBy('user.id');
        $this->assertSame('showing', $data[$c['agent']->id]['activity']['state']);
        $this->assertSame('unknown', $data[$c['otherAgent']->id]['activity']['state']);
        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $director = User::create(['name' => 'Director', 'phone' => '900000991', 'role_id' => $directorRole->id, 'branch_id' => $c['branch']->id, 'status' => 'active']);
        Sanctum::actingAs($director);
        $directorRows = collect($this->getJson('/api/attendance/presence')->assertOk()->json('data'))->keyBy('user.id');
        $this->assertTrue($directorRows->has($c['agent']->id));
        $this->assertFalse($directorRows->has($c['otherAgent']->id));
        Sanctum::actingAs($c['rop']);
        $data = collect($this->getJson('/api/attendance/presence')->assertOk()->json('data'))->keyBy('user.id');
        $this->assertTrue($data->has($c['agent']->id));
        $this->assertFalse($data->has($c['otherAgent']->id));
        $c['rop']->supervisedGroups()->detach();
        $this->getJson('/api/attendance/presence')->assertOk()->assertJsonCount(0, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00+05:00'));
        Sanctum::actingAs($c['admin']);
        $data = collect($this->getJson('/api/attendance/presence')->assertOk()->json('data'))->keyBy('user.id');
        $this->assertSame('unknown', $data[$c['agent']->id]['activity']['state']);
        $this->travelBack();
    }

    public function test_fieldwork_codes_keep_working_time_and_only_real_arrival_and_departure(): void
    {
        $context = $this->context();
        $device = $this->device('FIELDWORK', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $rows = [];
        foreach ([['09:00:00', 0], ['10:00:00', 2], ['11:00:00', 3], ['12:00:00', 4], ['14:00:00', 5], ['18:00:00', 1]] as [$time, $code]) {
            $rows[] = $context['agent']->id."\t2026-09-28 {$time}\t{$code}\t15\t0";
        }
        $this->postDevicePayload('/iclock/cdata?SN=FIELDWORK&table=ATTLOG', implode("\n", $rows))->assertOk();
        $events = AttendanceEvent::query()->orderBy('occurred_at')->get();
        $this->assertSame(['check_in', 'showing_out', 'showing_in', 'property_out', 'property_in', 'check_out'], $events->pluck('event_type')->all());
        $this->assertSame(['in', 'out', 'in', 'out', 'in', 'out'], $events->pluck('direction')->all());
        $summary = AttendanceDailySummary::query()->firstOrFail();
        $this->assertSame(540, $summary->worked_minutes);
        $this->assertSame('2026-09-28 04:00:00', $summary->first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 13:00:00', $summary->last_out_at->format('Y-m-d H:i:s'));

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-09-28')->assertOk()
            ->assertJsonPath('data.summary.activity.state', 'left')
            ->assertJsonPath('data.summary.activity.showing_count', 1)
            ->assertJsonPath('data.summary.activity.property_count', 1)
            ->assertJsonPath('data.events.4.event_type', 'property_in');
    }

    public function test_current_activity_tracks_fieldwork_returns_and_ignores_duplicates_and_future_marks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29T12:00:00+05:00'));
        $context = $this->context();
        $device = $this->device('FIELDWORK-NOW', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        foreach ([['09:00:00', 0, 'office'], ['09:00:05', 2, 'showing'], ['10:00:00', 3, 'office'], ['10:30:00', 4, 'property'], ['10:30:05', 4, 'property'], ['13:00:00', 5, 'property']] as [$time, $code, $state]) {
            $this->postDevicePayload('/iclock/cdata?SN=FIELDWORK-NOW&table=ATTLOG', $context['agent']->id."\t2026-09-29 {$time}\t{$code}\t15\t0")->assertOk();
            Sanctum::actingAs($context['hr']);
            $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-09-29')->assertOk()
                ->assertJsonPath('data.summary.activity.state', $state)
                ->assertJsonPath('data.summary.last_out_at', null);
        }
        $this->assertSame(1, AttendanceEvent::query()->where('is_duplicate', true)->count());
        $this->getJson('/api/attendance/matrix?date_from=2026-09-29&date_to=2026-09-29&user_id='.$context['agent']->id)->assertOk()
            ->assertJsonPath('data.0.days.2026-09-29.activity.state', 'property')
            ->assertJsonPath('data.0.days.2026-09-29.activity.since', '2026-09-29T05:30:00.000000Z')
            ->assertJsonPath('data.0.days.2026-09-29.activity.showing_count', 1)
            ->assertJsonPath('data.0.days.2026-09-29.activity.property_count', 1);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-09-28')->assertOk()
            ->assertJsonPath('data.summary.activity.state', 'unknown')
            ->assertJsonPath('data.summary.activity.last_event_at', null);
        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/users/'.$context['otherAgent']->id.'/days/2026-09-29')->assertNotFound();
        $this->travelBack();
    }

    public function test_fieldwork_cutover_uses_occurrence_time_and_can_scope_terminals(): void
    {
        $context = $this->context();
        $device = $this->device('FIELDWORK-CUTOVER', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        $classifier = app(\App\Services\Attendance\AttendanceEventClassifier::class);
        $this->assertSame('break_out', $classifier->classify($device, '2', CarbonImmutable::parse('2026-09-27T23:59:59+05:00')));
        $this->assertSame('showing_out', $classifier->classify($device, '2', CarbonImmutable::parse('2026-09-28T00:00:00+05:00')));
        $this->assertSame('punch', $classifier->classify($device, '99', CarbonImmutable::parse('2026-09-28T00:00:00+05:00')));
        config(['attendance.fieldwork_device_serials' => ['OTHER-TERMINAL']]);
        $this->assertSame('break_out', $classifier->classify($device, '2', CarbonImmutable::parse('2026-09-28T00:00:00+05:00')));
    }

    public function test_fieldwork_reclassification_previews_repairs_summaries_and_is_idempotent(): void
    {
        $context = $this->context();
        $device = $this->device('FIELDWORK-REPLAY', $context['branch'], $context['group']);
        $this->map($device, $context['agent']);
        config(['attendance.fieldwork_started_at' => null]);
        foreach (['2026-09-27', '2026-09-28'] as $date) {
            $rows = [];
            foreach ([['09:00:00', 0], ['09:00:05', 4], ['10:00:00', 2], ['11:00:00', 3], ['12:00:00', 4], ['14:00:00', 5], ['18:00:00', 1]] as [$time, $code]) {
                $rows[] = $context['agent']->id."\t{$date} {$time}\t{$code}\t15\t0";
            }
            $this->postDevicePayload('/iclock/cdata?SN=FIELDWORK-REPLAY&table=ATTLOG', implode("\n", $rows))->assertOk();
        }
        config(['attendance.fieldwork_started_at' => '2026-09-28T00:00:00+05:00']);
        $this->artisan('attendance:reclassify-fieldwork')->assertSuccessful();
        $this->assertSame(0, AttendanceEvent::query()->where('event_type', 'showing_out')->count());
        $this->artisan('attendance:reclassify-fieldwork', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, AttendanceEvent::query()->where('event_type', 'showing_out')->count());
        $this->assertSame(1, AttendanceEvent::query()->where('event_type', 'break_out')->count());
        $this->assertSame(540, AttendanceDailySummary::query()->whereDate('work_date', '2026-09-28')->firstOrFail()->worked_minutes);
        $this->assertSame(480, AttendanceDailySummary::query()->whereDate('work_date', '2026-09-27')->firstOrFail()->worked_minutes);
        $this->assertDatabaseCount('attendance_audit_logs', 5);
        $this->assertSame(1, AttendanceEvent::query()->where('is_duplicate', true)->count());
        $this->artisan('attendance:reclassify-fieldwork', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('attendance_audit_logs', 5);
        $this->assertSame(1, AttendanceEvent::query()->where('is_duplicate', true)->count());
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
            ->assertSeeText("TransFlag=TransData AttLog\tEnrollUser\tChgUser")
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

    public function test_unmapped_group_deletion_preserves_history_links_and_new_arrivals_and_blocks_replays(): void
    {
        $c = $this->context();
        $device = $this->device('DELETE-UNMAPPED', $c['branch'], $c['group']);
        $otherDevice = $this->device('KEEP-UNMAPPED');
        $mapping = AttendanceDeviceUser::create([
            'device_id' => $device->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id, 'is_active' => true,
        ]);
        $url = '/iclock/cdata?SN=DELETE-UNMAPPED&table=ATTLOG';
        $this->postDevicePayload($url, "0096\t2026-10-17 08:00:00\t0\t15\t0")->assertOk();
        $history = AttendanceEvent::query()->firstOrFail();
        $c['agent']->update(['status' => User::STATUS_INACTIVE]);
        $payload = "0096\t2026-10-17 09:00:00\t0\t15\t0\n0096\t2026-10-17 10:00:00\t1\t15\t0";
        $this->postDevicePayload($url, $payload)->assertOk();
        $staleRaw = AttendanceRawEvent::query()->where('processing_status', 'unmapped')->firstOrFail();
        $this->postDevicePayload($url, "96\t2026-10-17 09:00:00\t0\t15\t0")->assertOk();
        $this->postDevicePayload('/iclock/cdata?SN=KEEP-UNMAPPED&table=ATTLOG', "0096\t2026-10-17 09:00:00\t0\t15\t0")->assertOk();

        Sanctum::actingAs($c['hr']);
        $group = collect($this->getJson('/api/attendance/unmapped-events?grouped=true&device_id='.$device->id)
            ->assertOk()->json('data'))->firstWhere('device_user_id', '0096');
        $this->assertSame(2, $group['events_count']);
        $delete = ['device_id' => $device->id, 'device_user_id' => '0096', 'last_raw_event_id' => $group['last_raw_event_id']];
        // This event arrived after the operator saw the row and must remain.
        $this->postDevicePayload($url, "0096\t2026-10-17 11:00:00\t0\t15\t0")->assertOk();
        $this->deleteJson('/api/attendance/unmapped-events', $delete)->assertOk()->assertJsonPath('deleted_count', 2);
        $this->assertDatabaseMissing('attendance_raw_events', ['id' => $staleRaw->id]);
        $this->assertDatabaseCount('attendance_discarded_events', 2);
        $this->assertDatabaseHas('attendance_events', ['id' => $history->id, 'raw_event_id' => $history->raw_event_id]);
        $this->assertDatabaseHas('attendance_device_users', ['id' => $mapping->id, 'is_active' => true]);
        $this->assertDatabaseHas('attendance_raw_events', ['device_id' => $device->id, 'device_user_id' => '96', 'processing_status' => 'unmapped']);
        $this->assertDatabaseHas('attendance_raw_events', ['device_id' => $otherDevice->id, 'device_user_id' => '0096', 'processing_status' => 'unmapped']);
        $this->deleteJson('/api/attendance/unmapped-events', $delete)->assertOk()->assertJsonPath('deleted_count', 0);
        $audit = \App\Models\AttendanceAuditLog::query()->where('action', 'attendance_unmapped_events.deleted')->sole();
        $this->assertSame($c['hr']->id, $audit->actor_user_id);
        $this->assertSame('0096', $audit->old_values['device_user_id']);
        $this->assertSame(2, $audit->new_values['deleted_count']);
        $this->assertSame('already_processed', app(AttendanceIngestionService::class)->reprocess($staleRaw));
        (new ProcessAttendanceRawEvent($staleRaw->id))->handle(app(AttendanceIngestionService::class));

        $this->postDevicePayload($url, $payload)->assertOk()->assertContent('OK: 2');
        $this->assertSame(1, AttendanceRawEvent::query()->where('device_id', $device->id)->where('device_user_id', '0096')->where('processing_status', 'unmapped')->count());
        $c['agent']->update(['status' => User::STATUS_ACTIVE]);
        $this->putJson('/api/attendance/device-users', ['device_id' => $device->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id])
            ->assertOk()->assertJsonPath('reprocessed.processed', 1);
        $this->assertDatabaseCount('attendance_events', 2);

        // Retention removes raw payloads, but deletion markers still prevent re-import.
        $this->travel(91)->days();
        Artisan::call('attendance:prune-raw');
        $this->postDevicePayload($url, $payload)->assertOk();
        $this->assertDatabaseCount('attendance_raw_events', 0);
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseCount('attendance_discarded_events', 2);
        $this->travelBack();
    }

    public function test_unmapped_deletion_requires_mapping_permission_without_group_scope_and_a_snapshot(): void
    {
        $c = $this->context();
        $device = $this->device('DELETE-PERMISSION', $c['branch'], $c['group']);
        $this->postDevicePayload('/iclock/cdata?SN=DELETE-PERMISSION&table=ATTLOG', "0096\t2026-10-17 09:00:00\t0\t15\t0")->assertOk();
        $raw = AttendanceRawEvent::query()->firstOrFail();
        $delete = ['device_id' => $device->id, 'device_user_id' => '0096', 'last_raw_event_id' => $raw->id];
        foreach (['agent', 'rop', 'mop'] as $role) {
            Sanctum::actingAs($c[$role]);
            $this->deleteJson('/api/attendance/unmapped-events', $delete)->assertForbidden();
        }
        $this->assertDatabaseCount('attendance_raw_events', 1);
        $this->assertDatabaseCount('attendance_discarded_events', 0);
        Sanctum::actingAs($c['admin']);
        $this->deleteJson('/api/attendance/unmapped-events', ['device_id' => $device->id, 'device_user_id' => '0096'])
            ->assertUnprocessable()->assertJsonValidationErrors('last_raw_event_id', 'details.errors');
        $this->deleteJson('/api/attendance/unmapped-events', [...$delete, 'last_raw_event_id' => 0])->assertUnprocessable();
        $this->deleteJson('/api/attendance/unmapped-events', [...$delete, 'device_user_id' => '96'])
            ->assertOk()->assertJsonPath('deleted_count', 0);
        $this->deleteJson('/api/attendance/unmapped-events', $delete)->assertOk()->assertJsonPath('deleted_count', 1);
    }

    public function test_unmapped_deletion_removes_groups_across_multiple_database_chunks(): void
    {
        $c = $this->context();
        $device = $this->device('DELETE-LARGE-GROUP');
        $start = CarbonImmutable::parse('2026-10-17 09:00:00', 'Asia/Dushanbe');
        $payload = implode("\n", array_map(fn ($offset) => "0096\t".$start->addSeconds($offset)->format('Y-m-d H:i:s')."\t0\t15\t0", range(0, 204)));
        $this->postDevicePayload('/iclock/cdata?SN=DELETE-LARGE-GROUP&table=ATTLOG', $payload)->assertOk();
        Sanctum::actingAs($c['admin']);
        $this->deleteJson('/api/attendance/unmapped-events', [
            'device_id' => $device->id, 'device_user_id' => '0096', 'last_raw_event_id' => AttendanceRawEvent::query()->max('id'),
        ])->assertOk()->assertJsonPath('deleted_count', 205);
        $this->assertDatabaseCount('attendance_raw_events', 0);
        $this->assertDatabaseCount('attendance_discarded_events', 205);
    }

    public function test_unmapped_deletion_skips_events_linked_after_the_list_was_loaded(): void
    {
        $c = $this->context();
        $device = $this->device('DELETE-LINKED');
        $this->postDevicePayload('/iclock/cdata?SN=DELETE-LINKED&table=ATTLOG', "0096\t2026-10-17 09:00:00\t0\t15\t0")->assertOk();
        $raw = AttendanceRawEvent::query()->firstOrFail();
        Sanctum::actingAs($c['hr']);
        $this->putJson('/api/attendance/device-users', ['device_id' => $device->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id])->assertOk();
        $this->deleteJson('/api/attendance/unmapped-events', [
            'device_id' => $device->id, 'device_user_id' => '0096', 'last_raw_event_id' => $raw->id,
        ])->assertOk()->assertJsonPath('deleted_count', 0);
        $this->assertDatabaseHas('attendance_raw_events', ['id' => $raw->id, 'processing_status' => 'processed']);
        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseCount('attendance_discarded_events', 0);
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

    public function test_rop_matrix_explains_missing_group_assignments_and_recovers_after_assignment(): void
    {
        $context = $this->context();
        $context['rop']->supervisedGroups()->detach();
        Sanctum::actingAs($context['rop']);
        $url = '/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17';
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.access_scope.notice.code', 'ROP_GROUPS_NOT_ASSIGNED');
        $this->getJson('/api/me/access-scope')->assertOk()
            ->assertJsonPath('notice.code', 'ROP_GROUPS_NOT_ASSIGNED');
        app(\App\Services\GroupAccess\RopGroupAssignments::class)->replace(
            $context['admin'], $context['rop']->id, [$context['group']->id], 0
        );
        Sanctum::actingAs($context['rop']->fresh());
        $response = $this->getJson($url)->assertOk()->assertJsonPath('meta.access_scope.notice', null);
        $ids = collect($response->json('data'))->pluck('user.id')->all();
        $this->assertContains($context['agent']->id, $ids);
        $this->assertNotContains($context['otherAgent']->id, $ids);
    }

    public function test_rop_without_primary_group_sees_all_assigned_groups_only(): void
    {
        $context = $this->context();
        $secondGroup = BranchGroup::create(['branch_id' => $context['branch']->id, 'name' => 'Second assigned group']);
        $secondEmployee = User::create(['name' => 'Second agent', 'phone' => '992999000555',
            'role_id' => $context['agent']->role_id, 'branch_id' => $context['branch']->id,
            'branch_group_id' => $secondGroup->id, 'status' => 'active']);
        $context['rop']->forceFill(['branch_group_id' => null])->save();
        $context['rop']->supervisedGroups()->attach($secondGroup->id);
        Sanctum::actingAs($context['rop']);
        $response = $this->getJson('/api/attendance/matrix?date_from=2026-09-01&date_to=2026-09-30')->assertOk();
        $ids = collect($response->json('data'))->pluck('user.id')->all();
        $this->assertContains($context['agent']->id, $ids);
        $this->assertContains($secondEmployee->id, $ids);
        $this->assertNotContains($context['otherAgent']->id, $ids);
        $this->getJson('/api/attendance/matrix?date_from=2026-09-01&date_to=2026-09-30&branch_group_id='.$secondGroup->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $secondEmployee->id);
    }

    public function test_rop_can_open_empty_attendance_day_of_current_group_employee(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17')
            ->assertOk()->assertJsonCount(0, 'data.events');
        $this->getJson('/api/attendance/users/'.$context['otherAgent']->id.'/days/2026-08-17')
            ->assertNotFound();
        $response = $this->getJson('/api/attendance/matrix?branch_group_id='.$context['otherAgent']->branch_group_id)
            ->assertForbidden()->assertJsonPath('code', 'RBAC_GROUP_SCOPE_VIOLATION');
        $this->assertStringContainsString('назначенные вам группы', $response->json('message'));
    }

    public function test_attendance_rbac_limits_agents_mop_and_rop_to_their_scope(): void
    {
        $context = $this->context();
        AttendanceDailySummary::create(['user_id' => $context['agent']->id, 'branch_group_id' => $context['group']->id, 'work_date' => '2026-08-16', 'status' => 'present']);
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

    public function test_accountant_can_read_attendance_across_branches_and_export_without_write_permissions(): void
    {
        $context = $this->context();
        $accountant = $this->accountant($context['branch']);
        foreach ([$context['agent'], $context['otherAgent']] as $employee) {
            AttendanceDailySummary::create(['user_id' => $employee->id, 'work_date' => '2026-08-17', 'status' => 'present']);
        }
        Sanctum::actingAs($accountant);

        $matrix = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users')
            ->assertOk()
            ->assertJsonPath('meta.permissions.can_view_attendance_table', true)
            ->assertJsonPath('meta.permissions.can_view_all_branches', true);
        $ids = collect($matrix->json('data'))->pluck('user.id')->all();
        $this->assertContains($context['agent']->id, $ids);
        $this->assertContains($context['otherAgent']->id, $ids);
        foreach ($matrix->json('meta.permissions') as $permission => $value) {
            if (! in_array($permission, ['can_view_attendance_table', 'can_view_all_branches', 'can_view_hr_report'], true)) {
                $this->assertFalse($value, $permission);
            }
        }

        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=branches')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&branch_id='.$context['otherAgent']->branch_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $context['otherAgent']->id);
        $this->getJson('/api/attendance/users/'.$context['otherAgent']->id.'/days/2026-08-17')
            ->assertOk()->assertJsonPath('data.user.id', $context['otherAgent']->id);
        $this->getJson('/api/attendance/users/'.$context['otherAgent']->id.'/daily')->assertOk();
        $this->getJson('/api/attendance/daily')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/attendance/events')->assertOk();
        $csv = $this->get('/api/attendance/export?date_from=2026-08-17&date_to=2026-08-17')->assertOk()->streamedContent();
        $this->assertStringContainsString('Agent A', $csv);
        $this->assertStringContainsString('Agent B', $csv);
        $this->get('/api/attendance/export?format=xlsx&date_from=2026-08-17&date_to=2026-08-17')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->getJson('/api/roles')->assertOk();
        $groups = $this->getJson('/api/branch-groups')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing(['id', 'name', 'branch_id'], array_keys($groups[0]));
        $this->getJson('/api/branch-groups?branch_id='.$context['otherAgent']->branch_id)
            ->assertOk()->assertJsonCount(1, 'data');

        $accountant->update(['status' => User::STATUS_INACTIVE]);
        $this->getJson('/api/attendance/matrix')->assertForbidden();
    }

    public function test_accountant_cannot_mutate_attendance_or_access_administrative_apis(): void
    {
        $context = $this->context();
        $device = $this->device('ACCOUNTANT-READONLY', $context['branch'], $context['group']);
        $mapping = $this->map($device, $context['agent']);
        $leave = AttendanceLeave::create([
            'user_id' => $context['agent']->id, 'date_from' => '2026-08-17', 'date_to' => '2026-08-17',
            'created_by' => $context['hr']->id,
        ]);
        $duty = AttendanceDuty::create([
            'user_id' => $context['agent']->id, 'date_from' => '2026-08-18', 'date_to' => '2026-08-18',
            'created_by' => $context['hr']->id,
        ]);
        $holiday = AttendanceHoliday::create(['holiday_date' => '2026-08-19', 'name' => 'Holiday', 'created_by' => $context['hr']->id]);
        Sanctum::actingAs($this->accountant($context['branch']));
        $userPath = '/api/attendance/users/'.$context['agent']->id;
        $requests = [
            ['POST', '/api/attendance/devices', 'ATTENDANCE_ADMIN_FORBIDDEN'],
            ['PATCH', '/api/attendance/devices/'.$device->id, 'ATTENDANCE_ADMIN_FORBIDDEN'],
            ['PUT', '/api/attendance/device-users', 'ATTENDANCE_MAPPING_FORBIDDEN'],
            ['DELETE', '/api/attendance/device-users/'.$mapping->id, 'ATTENDANCE_MAPPING_FORBIDDEN'],
            ['POST', '/api/attendance/events/reprocess', 'ATTENDANCE_ADMIN_FORBIDDEN'],
            ['PUT', $userPath.'/days/2026-08-17/comment', 'ATTENDANCE_COMMENT_FORBIDDEN'],
            ['DELETE', $userPath.'/days/2026-08-17/comment', 'ATTENDANCE_COMMENT_FORBIDDEN'],
            ['PUT', $userPath.'/schedule', 'ATTENDANCE_SCHEDULE_FORBIDDEN'],
            ['PUT', '/api/attendance/schedule', 'ATTENDANCE_SCHEDULE_FORBIDDEN'],
            ['POST', $userPath.'/leaves', 'ATTENDANCE_LEAVE_FORBIDDEN'],
            ['DELETE', $userPath.'/leaves/'.$leave->id, 'ATTENDANCE_LEAVE_FORBIDDEN'],
            ['POST', $userPath.'/duties', 'ATTENDANCE_DUTY_FORBIDDEN'],
            ['DELETE', $userPath.'/duties/'.$duty->id, 'ATTENDANCE_DUTY_FORBIDDEN'],
            ['POST', '/api/attendance/holidays', 'ATTENDANCE_HOLIDAY_FORBIDDEN'],
            ['PUT', '/api/attendance/holidays/'.$holiday->id, 'ATTENDANCE_HOLIDAY_FORBIDDEN'],
            ['DELETE', '/api/attendance/holidays/'.$holiday->id, 'ATTENDANCE_HOLIDAY_FORBIDDEN'],
            ['GET', '/api/attendance/device-users', 'ATTENDANCE_MAPPING_FORBIDDEN'],
            ['GET', '/api/attendance/unmapped-events', 'ATTENDANCE_MAPPING_FORBIDDEN'],
            ['GET', '/api/attendance/devices', 'ATTENDANCE_DEVICE_FORBIDDEN'],
        ];
        foreach ($requests as [$method, $path, $code]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('code', $code);
        }
        foreach ([
            ['POST', '/api/roles'], ['PUT', '/api/roles/'.$context['agent']->role_id],
            ['DELETE', '/api/roles/'.$context['agent']->role_id], ['POST', '/api/branch-groups'],
            ['PUT', '/api/branch-groups/'.$context['group']->id], ['DELETE', '/api/branch-groups/'.$context['group']->id],
            ['POST', '/api/properties'], ['GET', '/api/user'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden();
        }
        $this->assertDatabaseCount('attendance_audit_logs', 0);
        $this->assertDatabaseCount('attendance_daily_comments', 0);
        $this->assertDatabaseHas('attendance_device_users', ['id' => $mapping->id]);
        $this->assertDatabaseHas('attendance_leaves', ['id' => $leave->id]);
        $this->assertDatabaseHas('attendance_duties', ['id' => $duty->id]);
        $this->assertDatabaseHas('attendance_holidays', ['id' => $holiday->id, 'name' => 'Holiday']);
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
            ->assertJsonPath('meta.summary.average_late_minutes', 7)
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
            ->assertForbidden()->assertJsonPath('code', 'RBAC_GROUP_SCOPE_VIOLATION');

        Sanctum::actingAs($context['agent']);
        $this->getJson('/api/attendance/matrix')->assertStatus(403)->assertJsonPath('code', 'ATTENDANCE_TABLE_FORBIDDEN');
    }

    public function test_hr_comment_uses_optimistic_locking_and_day_details_include_event_types(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-WEB-DAY', $context['branch'], $context['group']);
        AttendanceDailySummary::query()->create([
            'user_id' => $context['agent']->id,
            'branch_group_id' => $context['group']->id,
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

    public function test_terminal_names_enrich_unmapped_events_without_creating_employee_links(): void
    {
        $context = $this->context();
        $device = $this->device('NAMES-A', $context['branch'], $context['group']);
        $other = $this->device('NAMES-B');
        foreach (['NAMES-A', 'NAMES-B'] as $serial) {
            $this->postDevicePayload('/iclock/cdata?SN='.$serial.'&table=ATTLOG', "96\t2026-08-17 09:00:00\t0\t15\t0")->assertOk();
        }
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-A&table=ATTLOG', "97\t2026-08-17 10:00:00\t0\t15\t0")->assertOk();
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-A&table=OPERLOG', implode("\n", [
            "USER PIN=96\tName=Фируз Ҳамидов\tPasswd=private-password\tCard=private-card",
            "FP PIN=96\tName=Wrong name\tTMP=private-template",
            "FACE PIN=96\tTMP=private-face",
            "USER PIN=98\tPri=0",
            "USER PIN=\tName=Invalid",
        ]))->assertOk()->assertContent('OK: 1');
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-B&table=USERINFO', "PIN=96\tName=Другой сотрудник")->assertOk()->assertContent('OK: 1');
        // Repeated deliveries are idempotent, and a partial profile cannot erase a name.
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-A&table=OPERLOG', "USER PIN=96\tName=Фируз Ҳамидов\nUSER PIN=96\tPri=0")->assertOk();
        $this->assertDatabaseCount('attendance_device_user_profiles', 2);
        $this->assertDatabaseCount('attendance_device_users', 0);
        $this->assertDatabaseCount('attendance_events', 0);
        $this->assertDatabaseCount('attendance_ingest_requests', 3); // ATTLOG only.
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_id' => $device->id, 'device_user_id' => '96', 'name' => 'Фируз Ҳамидов']);
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_id' => $other->id, 'device_user_id' => '96', 'name' => 'Другой сотрудник']);

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/unmapped-events?grouped=true&device_id='.$device->id)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.device_user_name', null)
            ->assertJsonPath('data.1.device_user_name', 'Фируз Ҳамидов')
            ->assertJsonPath('data.1.events_count', 1);
        $this->getJson('/api/attendance/unmapped-events?device_id='.$other->id)
            ->assertOk()->assertJsonPath('data.0.device_user_name', 'Другой сотрудник');
        $this->getJson('/api/attendance/unmapped-events?grouped=true&device_id='.$other->id)
            ->assertOk()->assertJsonPath('data.0.device_user_name', 'Другой сотрудник');
        Sanctum::actingAs($context['agent']);
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertForbidden();
        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.device_user_name', 'Фируз Ҳамидов')
            ->assertJsonMissing(['device_user_name' => 'Другой сотрудник']);
    }

    public function test_mapping_names_match_the_exact_terminal_and_pin_without_replacing_aura_identity(): void
    {
        $context = $this->context();
        $device = $this->device('MAPPED-NAMES-A', $context['branch'], $context['group']);
        $other = $this->device('MAPPED-NAMES-B');
        $this->postDevicePayload('/iclock/cdata?SN=MAPPED-NAMES-A&table=USERINFO', "PIN=0096\tName=Имя с нулями\nPIN=96\tName=Имя без нулей")->assertOk();
        $this->postDevicePayload('/iclock/cdata?SN=MAPPED-NAMES-B&table=USERINFO', "PIN=0096\tName=Другой терминал")->assertOk();
        foreach ([[$device, '0096', true], [$device, '96', false], [$device, '97', true], [$other, '0096', true]] as [$terminal, $pin, $active]) {
            AttendanceDeviceUser::create([
                'device_id' => $terminal->id, 'device_user_id' => $pin,
                'user_id' => $context['agent']->id, 'is_active' => $active,
            ]);
        }

        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.device_user_name', 'Другой терминал')
            ->assertJsonPath('data.1.device_user_name', null)
            ->assertJsonPath('data.2.device_user_name', 'Имя без нулей')
            ->assertJsonPath('data.2.is_active', false)
            ->assertJsonPath('data.3.device_user_name', 'Имя с нулями')
            ->assertJsonPath('data.3.device_user_id', '0096')
            ->assertJsonPath('data.3.user.name', $context['agent']->name);
        $this->getJson('/api/attendance/device-users?device_id='.$device->id.'&per_page=1')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.device_user_name', null);
        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonMissing(['device_user_name' => 'Другой терминал']);
        Sanctum::actingAs($context['agent']);
        $this->getJson('/api/attendance/device-users')->assertForbidden();
    }

    public function test_terminal_profile_updates_validate_text_and_preserve_exact_ids(): void
    {
        $device = $this->device('NAMES-VALIDATION');
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-VALIDATION&table=OPERLOG', "\xEF\xBB\xBFUSER PIN=0096\tName=Первое Имя\r\nUSER PIN=96\tName=Другое Имя")->assertContent('OK: 2');
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-VALIDATION&table=OPERLOG', "USER PIN=0096\tName=Новое Имя")->assertContent('OK: 1');
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_id' => $device->id, 'device_user_id' => '0096', 'name' => 'Новое Имя']);
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-VALIDATION&table=OPERLOG', implode("\n", [
            "USER PIN=0096\tName=bad\xFF",
            "USER PIN=0096\tName=".str_repeat('я', 256),
            "USER PIN=".str_repeat('9', 101)."\tName=Invalid",
            "USER PIN=0096\tName=bad\x00name",
        ]))->assertContent('OK: 0');
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_user_id' => '0096', 'name' => 'Новое Имя']);
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-VALIDATION&table=OPERLOG', "USER PIN=0096\tName=\tPri=0")->assertContent('OK: 1');
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_user_id' => '0096', 'name' => null]);
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_user_id' => '96', 'name' => 'Другое Имя']);
        $this->assertDatabaseCount('attendance_device_user_profiles', 2);
        $this->assertDatabaseCount('attendance_ingest_requests', 0);
    }

    public function test_terminal_profile_sync_uses_authenticated_read_only_daily_query(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17T12:00:00+05:00'));
        $device = $this->device('NAMES-QUERY');
        $device->update(['communication_key' => 'test-device-key']);
        $this->get('/iclock/getrequest?SN=NAMES-QUERY')->assertForbidden();
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-QUERY&table=OPERLOG', "USER PIN=96\tName=Unauthorized")->assertForbidden();
        $this->assertDatabaseCount('attendance_device_user_profiles', 0);
        $url = '/iclock/getrequest?SN=NAMES-QUERY&PushCommKey=test-device-key';
        $this->get($url)->assertOk()->assertContent('C:'.now()->timestamp.":DATA QUERY USERINFO\n");
        $this->get($url)->assertOk()->assertContent('OK');
        $this->travel(23)->hours();
        $this->get($url)->assertContent('OK');
        $this->travel(1)->hours();
        $this->get($url)->assertContent('C:'.now()->timestamp.":DATA QUERY USERINFO\n");
        $this->postDevicePayload('/iclock/cdata?SN=NAMES-QUERY&PushCommKey=test-device-key&table=OPERLOG', "USER PIN=96\tName=Полученное имя")->assertContent('OK: 1');
        $this->assertDatabaseHas('attendance_device_user_profiles', ['device_id' => $device->id, 'name' => 'Полученное имя']);
        $device->update(['is_active' => false]);
        $this->get($url)->assertForbidden();
        $this->travelBack();
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

    public function test_non_employee_roles_are_excluded_from_attendance_reports_statistics_and_direct_details(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-NO-CLIENTS', $context['branch'], $context['group']);
        $clientRole = Role::query()->firstOrCreate(['slug' => 'client'], ['name' => 'Client']);
        $client = User::query()->create([
            'name' => 'Excluded Attendance Client',
            'phone' => '992000007778',
            'role_id' => $clientRole->id,
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
        $externalAgentRole = Role::query()->firstOrCreate(['slug' => 'external_agent'], ['name' => 'External agent']);
        $externalAgent = User::query()->create([
            'name' => 'Excluded External Agent',
            'phone' => '992000007779',
            'role_id' => $externalAgentRole->id,
            'branch_id' => $context['branch']->id,
            'branch_group_id' => $context['group']->id,
            'status' => User::STATUS_ACTIVE,
            'auth_method' => 'password',
        ]);
        foreach ([$context['agent'], $client, $externalAgent] as $user) {
            AttendanceDailySummary::query()->create([
                'user_id' => $user->id,
                'branch_group_id' => $context['group']->id,
                'work_date' => '2026-08-17',
                'late_minutes' => $user->id === $context['agent']->id ? 7 : 100,
                'status' => 'late',
            ]);
            AttendanceEvent::query()->create([
                'user_id' => $user->id,
                'branch_group_id' => $context['group']->id,
                'branch_id' => $context['branch']->id,
                'device_id' => $device->id,
                'device_user_id' => (string) $user->id,
                'event_type' => 'check_in',
                'occurred_at' => '2026-08-17 04:07:00',
                'verification_method' => 'face',
            ]);
        }

        // Non-employees remain excluded even if an older config cache has no exclusions.
        config(['attendance.excluded_table_user_roles' => []]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 12:00:00', 'Asia/Dushanbe'));
        try {
            foreach ([$context['admin'], $context['hr'], $context['rop'], $this->accountant($context['branch'])] as $viewer) {
                Sanctum::actingAs($viewer);
                $range = 'date_from=2026-08-17&date_to=2026-08-17';
                $matrix = $this->getJson('/api/attendance/matrix?'.$range)->assertOk()
                    ->assertJsonPath('meta.summary.late_today', 1)
                    ->assertJsonPath('meta.summary.checked_in_today', 1)
                    ->assertJsonPath('meta.summary.average_late_minutes', 7);
                $this->assertNotContains($client->id, array_column(array_column($matrix->json('data'), 'user'), 'id'));
                $this->assertNotContains($externalAgent->id, array_column(array_column($matrix->json('data'), 'user'), 'id'));
                foreach (['client', 'external_agent'] as $excludedRole) {
                    $this->getJson('/api/attendance/matrix?'.$range.'&role='.$excludedRole)->assertOk()
                        ->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.total', 0);
                }
                $branches = $this->getJson('/api/attendance/matrix?'.$range.'&view=branches')->assertOk();
                $lateCount = collect($branches->json('data'))->sum(fn ($row) => $row['days']['2026-08-17']['late_count']);
                $this->assertSame(1, $lateCount);
                foreach (['daily', 'events'] as $report) {
                    $this->getJson('/api/attendance/'.$report.'?'.$range)->assertOk()
                        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $context['agent']->id);
                    foreach ([$client, $externalAgent] as $excludedUser) {
                        $response = $this->getJson('/api/attendance/'.$report.'?'.$range.'&user_id='.$excludedUser->id);
                        if ($viewer->hasRole('rop')) $response->assertForbidden()->assertJsonPath('code', 'RBAC_GROUP_SCOPE_VIOLATION');
                        else $response->assertOk()->assertJsonCount(0, 'data');
                    }
                }
                foreach ([$client, $externalAgent] as $excludedUser) {
                    foreach (['daily', 'days/2026-08-17'] as $detail) {
                        $response = $this->getJson('/api/attendance/users/'.$excludedUser->id.'/'.$detail);
                        if ($viewer->hasRole('rop')) $response->assertNotFound();
                        else $response->assertForbidden()->assertJsonPath('code', 'ATTENDANCE_FORBIDDEN_SCOPE');
                    }
                    foreach (['schedule', 'leaves', 'duties'] as $detail) {
                        $this->getJson('/api/attendance/users/'.$excludedUser->id.'/'.$detail)->assertForbidden();
                    }
                }
                $csv = $this->get('/api/attendance/export?'.$range)->assertOk()->streamedContent();
                $this->assertStringContainsString($context['agent']->name, $csv);
                $this->assertStringNotContainsString($client->name, $csv);
                $this->assertStringNotContainsString($externalAgent->name, $csv);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertDatabaseHas('users', ['id' => $client->id]);
        $this->assertDatabaseHas('attendance_daily_summaries', ['user_id' => $client->id]);
        $this->assertDatabaseHas('users', ['id' => $externalAgent->id]);
        $this->assertDatabaseHas('attendance_daily_summaries', ['user_id' => $externalAgent->id]);
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

    public function test_hr_timesheet_export_accepts_31_calendar_days_and_rejects_32(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['admin']);

        $this->get('/api/attendance/export?format=xlsx&date_from=2026-08-01&date_to=2026-08-31&view=users&page=1&per_page=50')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->getJson('/api/attendance/export?format=xlsx&date_from=2026-08-01&date_to=2026-09-01&view=users&page=1&per_page=50')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Диапазон табеля не может превышать 31 день.');
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

    public function test_rop_dismissal_hides_current_and_historical_attendance_without_deleting_records(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $c = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 12:00:00', 'Asia/Dushanbe'));
        $device = $this->device('DISMISSAL-HISTORY', $c['branch'], $c['group']);
        $snapshot = ['schedule' => [
            '1' => ['start' => '09:00', 'end' => '18:00'],
            '3' => ['start' => '09:00', 'end' => '18:00'],
        ]];
        foreach ([$c['agent'], $c['otherAgent'], $c['mop']] as $employee) {
            foreach (['2026-08-17', '2026-08-19'] as $date) {
                AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $c['group']->id,
                    'role_slug' => $employee->role->slug, 'schedule_snapshot' => $snapshot,
                    'work_date' => $date, 'status' => 'late', 'late_minutes' => $employee->id === $c['mop']->id ? 7 : 91]);
                AttendanceEvent::create(['user_id' => $employee->id, 'device_id' => $device->id,
                    'branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id, 'role_slug' => $employee->role->slug,
                    'device_user_id' => (string) $employee->id, 'event_type' => 'check_in', 'occurred_at' => $date.'T04:07:00Z']);
            }
        }
        Sanctum::actingAs($c['rop']);
        $range = 'date_from=2026-08-17&date_to=2026-08-19';
        $this->getJson('/api/attendance/matrix?'.$range)->assertOk()->assertJsonCount(3, 'data');
        // One employee still has the current group, the other has only historical facts in it.
        DB::table('users')->whereIn('id', [$c['agent']->id, $c['otherAgent']->id])->update(['status' => User::STATUS_INACTIVE]);
        $before = [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('rop_branch_groups')->get()->toJson(),
            DB::table('attendance_daily_summaries')->orderBy('id')->get()->toJson(), DB::table('attendance_events')->orderBy('id')->get()->toJson()];
        $this->getJson('/api/attendance/matrix?'.$range)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $c['mop']->id)->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.summary.active_users', 1)->assertJsonPath('meta.summary.checked_in_today', 1)
            ->assertJsonPath('meta.summary.late_today', 1)->assertJsonPath('meta.summary.average_late_minutes', 7);
        $this->getJson('/api/attendance/matrix?'.$range.'&view=branches')->assertOk()
            ->assertJsonPath('data.0.days.2026-08-17.scheduled_users_count', 1)
            ->assertJsonPath('data.0.days.2026-08-17.checked_in_count', 1);
        $this->getJson('/api/attendance/presence')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $c['mop']->id);
        foreach (['daily', 'events'] as $endpoint) {
            $this->getJson('/api/attendance/'.$endpoint.'?'.$range)->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.user_id', $c['mop']->id)->assertJsonPath('data.1.user_id', $c['mop']->id);
        }
        foreach ([$c['agent'], $c['otherAgent']] as $employee) {
            $matrix = $this->getJson('/api/attendance/matrix?'.$range.'&user_id='.$employee->id);
            if ($employee->id === $c['agent']->id) $matrix->assertOk()->assertJsonCount(0, 'data');
            else $matrix->assertForbidden();
            $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&search='.$employee->id.'&branch_group_id='.$c['group']->id)
                ->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/attendance/users/'.$employee->id.'/days/2026-08-17')->assertNotFound();
            $this->getJson('/api/attendance/users/'.$employee->id.'/daily?'.$range)->assertNotFound();
            $events = $this->getJson('/api/attendance/events?'.$range.'&user_id='.$employee->id);
            if ($employee->id === $c['agent']->id) $events->assertOk()->assertJsonCount(0, 'data');
            else $events->assertForbidden();
        }
        $csv = $this->get('/api/attendance/export?'.$range)->assertOk()->streamedContent();
        $xlsx = $this->get('/api/attendance/export?'.$range.'&format=xlsx')->assertOk();
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheets = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString($c['mop']->name, $csv);
        $this->assertStringContainsString($c['mop']->name, $sheets);
        foreach ([$c['agent'], $c['otherAgent']] as $employee) {
            $this->assertStringNotContainsString($employee->name, $csv.$sheets);
        }
        $this->assertSame($before, [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('rop_branch_groups')->get()->toJson(),
            DB::table('attendance_daily_summaries')->orderBy('id')->get()->toJson(), DB::table('attendance_events')->orderBy('id')->get()->toJson()]);
        $this->travelBack();
    }

    public function test_rop_legacy_history_excludes_dismissed_users_and_retains_active_transferred_users(): void
    {
        $c = $this->context();
        foreach ([$c['agent'], $c['otherAgent']] as $employee) {
            AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $c['group']->id,
                'work_date' => '2026-08-17', 'status' => 'present']);
        }
        $c['agent']->update(['status' => User::STATUS_INACTIVE]);
        Sanctum::actingAs($c['rop']);
        $response = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17')->assertOk();
        $ids = collect($response->json('data'))->pluck('user.id')->all();
        $this->assertNotContains($c['agent']->id, $ids);
        $this->assertContains($c['otherAgent']->id, $ids);
        $this->getJson('/api/attendance/users/'.$c['otherAgent']->id.'/days/2026-08-17')->assertOk();
        $this->assertDatabaseCount('attendance_daily_summaries', 2);
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

    public function test_reprocessor_only_queues_pending_or_failed_events(): void
    {
        Queue::fake();
        $device = $this->device('ZAM230-STALE');
        $queued = AttendanceRawEvent::query()->create([
            'device_id' => $device->id,
            'event_hash' => str_repeat('a', 64),
            'device_user_id' => '777',
            'occurred_at_local' => '2026-08-16 09:00:00',
            'occurred_at_utc' => '2026-08-16 04:00:00',
            'raw_payload' => 'fixture',
            'received_at' => now()->subHour(),
            'processing_status' => 'queued',
        ]);
        $queued->forceFill(['updated_at' => now()->subHour()])->save();
        $pending = AttendanceRawEvent::query()->create([
            'device_id' => $device->id,
            'event_hash' => str_repeat('b', 64),
            'device_user_id' => '778',
            'occurred_at_local' => '2026-08-16 09:01:00',
            'occurred_at_utc' => '2026-08-16 04:01:00',
            'raw_payload' => 'fixture',
            'received_at' => now()->subHour(),
            'processing_status' => 'pending',
        ]);

        Artisan::call('attendance:reprocess-pending');

        Queue::assertPushed(ProcessAttendanceRawEvent::class, 1);
        Queue::assertPushed(ProcessAttendanceRawEvent::class, fn ($job) => $job->rawEventId === $pending->id);
        Queue::assertNotPushed(ProcessAttendanceRawEvent::class, fn ($job) => $job->rawEventId === $queued->id);
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

    public function test_dismissed_employee_mapping_can_be_disabled_but_not_enabled(): void
    {
        $context = $this->context();
        $device = $this->device('ZAM230-DISABLE', $context['branch'], $context['group']);
        $mapping = $this->map($device, $context['agent']);
        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-DISABLE&table=ATTLOG',
            $context['agent']->id."\t2026-08-16 09:00:00\t0\t15\t0")->assertOk();
        $context['agent']->update(['status' => User::STATUS_INACTIVE]);
        $this->postDevicePayload('/iclock/cdata?SN=ZAM230-DISABLE&table=ATTLOG',
            $context['agent']->id."\t2026-08-17 09:00:00\t0\t15\t0")->assertOk();
        Sanctum::actingAs($context['admin']);
        $payload = ['device_id' => $device->id, 'device_user_id' => (string) $context['agent']->id,
            'user_id' => $context['agent']->id, 'is_active' => false];
        $this->putJson('/api/attendance/device-users', $payload)->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $context['agent']->id]);
        $this->assertDatabaseHas('attendance_raw_events', ['processing_status' => 'unmapped']);
        $this->assertDatabaseHas('attendance_audit_logs', ['action' => 'attendance_mapping.updated']);
        $this->putJson('/api/attendance/device-users', array_merge($payload, ['is_active' => true]))->assertStatus(422);
        $this->putJson('/api/attendance/device-users', array_merge($payload, ['device_user_id' => '999']))->assertStatus(422);
        $this->assertDatabaseHas('attendance_device_users', ['id' => $mapping->id, 'is_active' => false]);
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

    private function accountant(Branch $branch): User
    {
        $role = Role::firstOrCreate(['slug' => 'accountant'], ['name' => 'Бухгалтер']);

        return User::create([
            'name' => 'Accountant', 'phone' => '992900000999', 'role_id' => $role->id,
            'branch_id' => $branch->id, 'status' => User::STATUS_ACTIVE, 'auth_method' => 'password',
        ]);
    }

    public function test_group_merge_repair_is_bounded_audited_idempotent_and_visible_to_the_new_rop(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 12:00:00', 'Asia/Dushanbe'));
        $c = $this->context(); $user = $c['agent'];
        $target = BranchGroup::create(['name' => 'Merged team', 'branch_id' => $c['branch']->id]);
        $device = $this->device('MERGE-REPAIR', $c['branch'], $c['group']);
        $before = ['branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id, 'role_id' => $user->role_id];
        DB::table('group_access_audit_logs')->insert(['subject_type' => 'user', 'subject_id' => $user->id, 'event' => 'user_organization_changed',
            'old_values' => json_encode($before), 'new_values' => json_encode($before), 'created_at' => '2026-08-16 08:00:00']);
        $audit = DB::table('group_access_audit_logs')->insertGetId(['subject_type' => 'users', 'subject_id' => $user->id, 'event' => 'group_consolidation_transfer',
            'old_values' => json_encode(['branch_group_id' => $c['group']->id]), 'new_values' => json_encode(['branch_group_id' => $target->id]), 'created_at' => '2026-08-17 12:00:00']);
        $user->update(['branch_group_id' => $target->id]);
        $c['rop']->supervisedGroups()->sync([$target->id]);
        $make = fn ($employee, $date, $type) => AttendanceEvent::create(['device_id' => $device->id, 'device_user_id' => (string) $employee->id,
            'user_id' => $employee->id, 'branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id, 'role_slug' => 'agent',
            'occurred_at' => CarbonImmutable::parse($date, 'Asia/Dushanbe')->utc(), 'event_type' => $type, 'verification_method' => 'face']);
        $old = $make($user, '2026-08-16 09:00:00', 'check_in');
        $early = $make($user, '2026-08-17 09:00:00', 'check_in');
        $boundary = $make($user, '2026-08-17 12:00:00', 'check_out');
        $morning = $make($user, '2026-08-18 09:07:00', 'check_in');
        $evening = $make($user, '2026-08-18 18:00:00', 'check_out');
        $foreign = $make($c['mop'], '2026-08-18 09:00:00', 'check_in');
        $future = $make($user, '2026-08-20 09:00:00', 'check_in');
        $service = app(\App\Services\Attendance\AttendanceSummaryService::class);
        $snapshot = ['timezone' => 'Asia/Dushanbe', 'schedule' => ['2' => ['start' => '09:00', 'end' => '18:00']], 'holidays' => []];
        foreach (['2026-08-16', '2026-08-17', '2026-08-18'] as $date) {
            AttendanceDailySummary::create(['user_id' => $user->id, 'work_date' => $date, 'schedule_snapshot' => $snapshot]);
            $service->recompute($user, $date);
        }
        $previousDay = AttendanceDailySummary::where('user_id', $user->id)->whereDate('work_date', '2026-08-16')->first()->getRawOriginal();
        $eventBefore = $morning->fresh()->getRawOriginal();
        Sanctum::actingAs($c['rop']);
        $url = '/api/attendance/matrix?date_from=2026-08-16&date_to=2026-08-18';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.days.2026-08-18.availability', 'no_data');
        $options = ['audit_id' => [(string) $audit]];
        $this->assertSame(0, Artisan::call('attendance:repair-group-merge', $options));
        $this->assertSame(3, json_decode(Artisan::output(), true)['events_to_change']);
        $this->assertSame($eventBefore, $morning->fresh()->getRawOriginal());
        $this->assertDatabaseCount('attendance_audit_logs', 0);
        $this->assertSame(1, Artisan::call('attendance:repair-group-merge', [...$options, '--apply' => true]));
        $path = sys_get_temp_dir().'/attendance-merge-'.bin2hex(random_bytes(8)).'.json';
        $again = $path.'.again';
        try {
            $this->assertSame(0, Artisan::call('attendance:repair-group-merge', [...$options, '--apply' => true, '--backup' => $path]));
            $this->assertSame(0600, fileperms($path) & 0777);
            $backup = json_decode(file_get_contents($path), true);
            $this->assertCount(3, $backup['events']);
            $this->assertSame($eventBefore, collect($backup['events'])->firstWhere('id', $morning->id));
            foreach ([$boundary, $morning, $evening] as $event) $this->assertSame($target->id, $event->fresh()->branch_group_id);
            foreach ([$old, $early, $foreign, $future] as $event) $this->assertSame($c['group']->id, $event->fresh()->branch_group_id);
            $this->assertSame($previousDay, AttendanceDailySummary::find($previousDay['id'])->getRawOriginal());
            $this->assertDatabaseHas('attendance_daily_summaries', ['user_id' => $user->id, 'work_date' => '2026-08-17', 'branch_group_id' => null]);
            $this->assertDatabaseHas('attendance_daily_summaries', ['user_id' => $user->id, 'work_date' => '2026-08-18', 'branch_group_id' => $target->id, 'role_slug' => 'agent', 'late_minutes' => 7]);
            $this->assertSame($snapshot, AttendanceDailySummary::where('user_id', $user->id)->whereDate('work_date', '2026-08-18')->first()->schedule_snapshot);
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.days.2026-08-18.events_count', 2)
                ->assertJsonPath('data.0.days.2026-08-18.first_in_at', '2026-08-18T04:07:00.000000Z')
                ->assertJsonPath('data.0.days.2026-08-16.availability', 'no_data');
            $this->getJson('/api/attendance/users/'.$user->id.'/days/2026-08-17')->assertOk()->assertJsonCount(1, 'data.events');
            $auditCount = DB::table('attendance_audit_logs')->count();
            $this->assertSame(5, $auditCount);
            $this->assertSame(1, Artisan::call('attendance:repair-group-merge', [...$options, '--apply' => true, '--backup' => $path]));
            $this->assertSame(0, Artisan::call('attendance:repair-group-merge', [...$options, '--apply' => true, '--backup' => $again]));
            $result = json_decode(Artisan::output(), true);
            $this->assertSame(0, $result['events_to_change']);
            $this->assertSame(0, $result['summaries_changed']);
            $this->assertSame($auditCount, DB::table('attendance_audit_logs')->count());
        } finally { @unlink($path); @unlink($again); }
        // New and delayed terminal deliveries use the same merge-aware history.
        $this->map($device, $user);
        $this->postDevicePayload('/iclock/cdata?SN=MERGE-REPAIR&table=ATTLOG', $user->id."\t2026-08-19 09:00:00\t0\t15\t0")->assertOk();
        $this->assertDatabaseHas('attendance_events', ['user_id' => $user->id, 'occurred_at' => '2026-08-19 04:00:00', 'branch_group_id' => $target->id, 'role_slug' => 'agent']);
    }

    public function test_group_merge_repair_rolls_back_events_and_audit_if_day_rebuild_fails(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-08-18 12:00:00', 'Asia/Dushanbe'));
        $c = $this->context(); $user = $c['agent'];
        $target = BranchGroup::create(['name' => 'Merged team', 'branch_id' => $c['branch']->id]);
        $before = ['branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id, 'role_id' => $user->role_id];
        $audit = DB::table('group_access_audit_logs')->insertGetId(['subject_type' => 'user', 'subject_id' => $user->id, 'event' => 'user_group_merged',
            'old_values' => json_encode($before), 'new_values' => json_encode([...$before, 'branch_group_id' => $target->id]), 'created_at' => '2026-08-17 12:00:00']);
        $device = $this->device('MERGE-ROLLBACK', $c['branch'], $c['group']);
        $event = AttendanceEvent::create(['device_id' => $device->id, 'device_user_id' => (string) $user->id, 'user_id' => $user->id,
            'branch_id' => $c['branch']->id, 'branch_group_id' => $c['group']->id, 'role_slug' => 'agent',
            'occurred_at' => CarbonImmutable::parse('2026-08-17 13:00:00', 'Asia/Dushanbe')->utc(), 'event_type' => 'check_in']);
        $original = $event->fresh()->getRawOriginal();
        DB::unprepared("CREATE TRIGGER refuse_summary BEFORE INSERT ON attendance_daily_summaries BEGIN SELECT RAISE(ABORT, 'summary refused'); END");
        $path = sys_get_temp_dir().'/attendance-merge-rollback-'.bin2hex(random_bytes(8)).'.json';
        try {
            try {
                Artisan::call('attendance:repair-group-merge', ['audit_id' => [$audit], '--apply' => true, '--backup' => $path]);
                $this->fail('Rebuilding the day must fail.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('summary refused', $error->getMessage());
            }
            $this->assertFileExists($path);
            $this->assertSame($original, $event->fresh()->getRawOriginal());
            $this->assertDatabaseCount('attendance_daily_summaries', 0);
            $this->assertDatabaseCount('attendance_audit_logs', 0);
        } finally { @unlink($path); }
    }

    public function test_saved_attendance_role_and_schedule_survive_employee_role_and_group_changes(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $context = $this->context();
        $employee = $context['agent'];
        $snapshot = ['timezone' => 'Asia/Dushanbe', 'schedule' => ['1' => ['start' => '09:00', 'end' => '18:00']]];
        $summary = AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $context['group']->id,
            'role_slug' => 'agent', 'schedule_snapshot' => $snapshot, 'work_date' => '2026-08-17', 'status' => 'late', 'late_minutes' => 12]);
        AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $context['group']->id,
            'work_date' => '2026-08-18', 'status' => 'late', 'late_minutes' => 99]);
        $employee->forceFill(['role_id' => $context['hr']->role_id,
            'branch_id' => $context['otherAgent']->branch_id, 'branch_group_id' => $context['otherAgent']->branch_group_id])->save();
        Sanctum::actingAs($context['rop']);
        $range = 'date_from=2026-08-17&date_to=2026-08-18&role=agent&branch_group_id='.$context['group']->id;
        $this->getJson('/api/attendance/daily?'.$range)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.late_minutes', 12);
        $matrix = $this->getJson('/api/attendance/matrix?'.$range)->assertOk();
        $row = collect($matrix->json('data'))->firstWhere('user.id', $employee->id);
        $this->assertSame(12, $row['days']['2026-08-17']['late_minutes']);
        $this->assertNull($row['days']['2026-08-18']['late_minutes']);
        $this->getJson('/api/attendance/users/'.$employee->id.'/days/2026-08-17')->assertOk()
            ->assertJsonPath('data.schedule.starts_at', '09:00');
        app(\App\Services\Attendance\AttendanceSummaryService::class)->recompute($employee, '2026-08-17');
        $this->assertSame('agent', $summary->fresh()->role_slug);
        $this->assertSame($snapshot, $summary->fresh()->schedule_snapshot);
        $this->assertSame($context['group']->id, (int) $summary->fresh()->branch_group_id);
    }

    public function test_historical_group_limits_daily_matrix_comments_and_exports_after_employee_moves(): void
    {
        $context = $this->context();
        $employee = $context['agent'];
        $newGroup = $context['otherAgent']->branch_group_id;
        AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $context['group']->id,
            'work_date' => '2026-08-17', 'status' => 'late', 'late_minutes' => 7]);
        AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $newGroup,
            'work_date' => '2026-08-18', 'status' => 'late', 'late_minutes' => 93]);
        \App\Models\AttendanceDailyComment::create(['user_id' => $employee->id, 'work_date' => '2026-08-18',
            'comment' => 'FOREIGN_GROUP_HR_SECRET', 'author_id' => $context['hr']->id, 'version' => 1]);
        $employee->forceFill(['branch_id' => $context['otherAgent']->branch_id, 'branch_group_id' => $newGroup])->save();
        Sanctum::actingAs($context['rop']);
        $range = 'date_from=2026-08-17&date_to=2026-08-18';
        $this->getJson('/api/attendance/daily?'.$range)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.late_minutes', 7);
        $this->getJson('/api/attendance/users/'.$employee->id.'/daily?'.$range)->assertOk()->assertJsonCount(1, 'data');
        $matrix = $this->getJson('/api/attendance/matrix?'.$range)->assertOk();
        $row = collect($matrix->json('data'))->firstWhere('user.id', $employee->id);
        $this->assertSame(7, $row['days']['2026-08-17']['late_minutes']);
        $this->assertNull($row['days']['2026-08-18']['late_minutes']);
        $this->assertFalse($row['days']['2026-08-18']['has_comment']);
        $this->assertSame(['id' => $employee->id, 'name' => $employee->name], $row['user']);
        $csv = $this->get('/api/attendance/export?'.$range)->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-08-17', $csv);
        $this->assertStringNotContainsString('2026-08-18', $csv);
        $xlsx = $this->get('/api/attendance/export?'.$range.'&format=xlsx')->assertOk();
        $zip = new \ZipArchive;
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $this->assertTrue($zip->open($path));
        $contents = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $this->assertStringNotContainsString('FOREIGN_GROUP_HR_SECRET', $contents);
        $this->assertStringNotContainsString('Branch B', $contents);
        @unlink($path);
        $this->assertDatabaseHas('attendance_daily_summaries', ['user_id' => $employee->id,
            'work_date' => '2026-08-17', 'branch_group_id' => $context['group']->id]);
    }

    public function test_rop_history_has_record_labels_and_no_current_actions_or_false_weekends(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $context = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-08-19 12:00:00', 'Asia/Dushanbe'));
        $foreign = BranchGroup::create(['name' => 'Private current group', 'branch_id' => $context['branch']->id]);
        $snapshot = ['schedule' => ['1' => ['start' => '09:00', 'end' => '18:00']]];
        $employees = [$context['agent'], $context['otherAgent']];
        foreach ($employees as $employee) {
            AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $context['group']->id,
                'role_slug' => 'agent', 'schedule_snapshot' => $snapshot, 'work_date' => '2026-08-17', 'status' => 'late', 'late_minutes' => 7]);
            AttendanceDailySummary::create(['user_id' => $employee->id, 'branch_group_id' => $foreign->id,
                'role_slug' => 'agent', 'work_date' => '2026-08-18', 'status' => 'late', 'late_minutes' => 91]);
            $employee->forceFill(['branch_id' => $context['branch']->id, 'branch_group_id' => $foreign->id])->save();
        }
        // The saved role is authoritative even after today's account role changes.
        $employees[1]->forceFill(['role_id' => $context['hr']->role_id])->save();
        $before = DB::table('users')->whereIn('id', collect($employees)->pluck('id')->push($context['rop']->id))->orderBy('id')->get()->toJson();
        Sanctum::actingAs($context['rop']);
        $range = 'date_from=2026-08-17&date_to=2026-08-19';
        $response = $this->getJson('/api/attendance/matrix?'.$range)->assertOk();
        foreach ($employees as $employee) {
            $row = collect($response->json('data'))->firstWhere('user.id', $employee->id);
            $this->assertSame(['id' => $employee->id, 'name' => $employee->name], $row['user']);
            $this->assertSame('historical', $row['employee_scope']);
            $this->assertSame('agent', $row['history_contexts'][0]['role']);
            $this->assertSame($context['branch']->name, $row['history_contexts'][0]['branch_name']);
            $this->assertSame($context['group']->name, $row['history_contexts'][0]['branch_group_name']);
            $this->assertSame(['2026-08-17'], $row['history_contexts'][0]['dates']);
            $this->assertSame([false, false, false, false], array_values($row['actions']));
            $this->assertSame('available', $row['days']['2026-08-17']['availability']);
            $this->assertTrue($row['days']['2026-08-17']['schedule_known']);
            $this->assertTrue($row['days']['2026-08-17']['can_open']);
            foreach (['2026-08-18', '2026-08-19'] as $date) {
                $this->assertSame('restricted', $row['days'][$date]['availability']);
                $this->assertFalse($row['days'][$date]['schedule_known']);
                $this->assertFalse($row['days'][$date]['can_open']);
                $this->getJson('/api/attendance/users/'.$employee->id.'/days/'.$date)->assertNotFound();
            }
            $this->getJson('/api/attendance/users/'.$employee->id.'/days/2026-08-17')->assertOk()
                ->assertJsonPath('data.employee_scope', 'historical')
                ->assertJsonPath('data.history_contexts.0.branch_group_name', $context['group']->name)
                ->assertJsonPath('data.summary.schedule_known', true)
                ->assertJsonPath('data.schedule.starts_at', '09:00');
        }
        $current = collect($response->json('data'))->firstWhere('user.id', $context['mop']->id);
        $this->assertSame('current', $current['employee_scope']);
        $this->assertSame('no_data', $current['days']['2026-08-17']['availability']);
        $this->assertFalse($current['days']['2026-08-17']['schedule_known']);
        $this->assertTrue($current['days']['2026-08-17']['can_open']);
        $this->getJson('/api/attendance/users/'.$context['mop']->id.'/days/2026-08-17')->assertOk()
            ->assertJsonPath('data.summary.schedule_known', false)->assertJsonPath('data.schedule', null);
        $xlsx = $this->get('/api/attendance/export?'.$range.'&format=xlsx')->assertOk();
        $zip = new \ZipArchive;
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $details = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('исторические записи', $sheet);
        $this->assertStringContainsString($context['group']->name, $sheet);
        $this->assertStringContainsString('Данные недоступны', $details);
        $this->assertStringNotContainsString('Private current group', $sheet.$details);
        $after = DB::table('users')->whereIn('id', collect($employees)->pluck('id')->push($context['rop']->id))->orderBy('id')->get()->toJson();
        $this->assertSame($before, $after);
        $this->travelBack();
    }

    public function test_branch_totals_keep_saved_marks_when_historical_schedule_is_unknown(): void
    {
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $context = $this->context();
        AttendanceDailySummary::create(['user_id' => $context['agent']->id, 'branch_group_id' => $context['group']->id,
            'role_slug' => 'agent', 'work_date' => '2026-08-17', 'status' => 'late', 'late_minutes' => 7]);
        Sanctum::actingAs($context['rop']);
        $this->getJson('/api/attendance/matrix?view=branches&date_from=2026-08-17&date_to=2026-08-17')->assertOk()
            ->assertJsonPath('data.0.days.2026-08-17.scheduled_users_count', 0)
            ->assertJsonPath('data.0.days.2026-08-17.checked_in_count', 1)
            ->assertJsonPath('data.0.days.2026-08-17.late_count', 1)
            ->assertJsonPath('data.0.days.2026-08-17.attendance_pct', null);
        $this->getJson('/api/attendance/users/'.$context['agent']->id.'/days/2026-08-17')->assertOk()
            ->assertJsonPath('data.summary.status', 'late')->assertJsonPath('data.summary.schedule_known', false);
    }

    public function test_csv_export_rechecks_scope_before_sending_any_bytes(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['rop']);
        $response = $this->get('/api/attendance/export')->assertOk();
        app(\App\Services\GroupAccess\RopGroupAssignments::class)->replace(
            $context['admin'], $context['rop']->id, [], (int) $context['rop']->access_scope_version
        );
        ob_start();
        try {
            $response->baseResponse->sendContent();
            $this->fail('Revoked export was sent');
        } catch (\Illuminate\Routing\Exceptions\StreamedResponseException $error) {
            $cause = $error->getInnerException();
            $this->assertInstanceOf(\Symfony\Component\HttpKernel\Exception\HttpException::class, $cause);
            $this->assertSame(409, $cause->getStatusCode());
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_xlsx_is_discarded_if_scope_changes_during_generation(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['rop']);
        $before = glob(sys_get_temp_dir().'/attendance-xlsx-*');
        $changed = false;
        DB::listen(function ($query) use (&$changed, $context) {
            if (! $changed && str_contains($query->sql, 'attendance_work_schedules') && str_starts_with($query->sql, 'select')) {
                $changed = true;
                DB::table('users')->where('id', $context['rop']->id)->increment('access_scope_version');
            }
        });
        $this->getJson('/api/attendance/export?format=xlsx&date_from=2026-08-17&date_to=2026-08-17')
            ->assertStatus(409);
        $this->assertTrue($changed);
        $this->assertSame($before, glob(sys_get_temp_dir().'/attendance-xlsx-*'));
    }

    public function test_generated_xlsx_rechecks_scope_at_send_time_and_removes_revoked_file(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['rop']);
        $response = $this->get('/api/attendance/export?format=xlsx&date_from=2026-08-17&date_to=2026-08-17')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($path);
        app(\App\Services\GroupAccess\RopGroupAssignments::class)->replace(
            $context['admin'], $context['rop']->id, [], (int) $context['rop']->access_scope_version
        );
        ob_start();
        try {
            $response->baseResponse->sendContent();
            $this->fail('Generated revoked file was sent');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('', ob_get_contents());
            $this->assertFileDoesNotExist($path);
        } finally {
            ob_end_clean();
        }
    }

    public function test_hr_report_counts_schedule_leave_holiday_lateness_and_missing_days(): void
    {
        $context = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-08-22 12:00:00', 'Asia/Dushanbe'));
        $agent = $context['agent'];
        AttendanceWorkSchedule::query()->create(['user_id' => $agent->id, 'timezone' => 'Asia/Dushanbe', 'schedule' => config('attendance.default_schedule')]);
        AttendanceHoliday::query()->create(['holiday_date' => '2026-08-19', 'name' => 'Праздник', 'created_by' => $context['hr']->id]);
        AttendanceLeave::query()->create(['user_id' => $agent->id, 'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'created_by' => $context['hr']->id]);
        foreach ([['2026-08-17', 'present', 0], ['2026-08-18', 'incomplete', 12], ['2026-08-19', 'absent', 0], ['2026-08-20', 'absent', 0], ['2026-08-21', 'absent', 0], ['2026-08-22', 'absent', 0], ['2026-08-24', 'absent', 0]] as [$date, $status, $late]) {
            AttendanceDailySummary::query()->create(['user_id' => $agent->id, 'work_date' => $date, 'status' => $status, 'late_minutes' => $late]);
        }
        Sanctum::actingAs($context['hr']);
        $url = '/api/attendance/hr-report?date_from=2026-08-17&date_to=2026-08-24&search=Agent%20A';
        $response = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.working_days', 5)->assertJsonPath('data.0.present_days', 2)
            ->assertJsonPath('data.0.absent_days', 1)->assertJsonPath('data.0.on_time_days', 1)
            ->assertJsonPath('data.0.pending_days', 2)->assertJsonPath('data.0.missing_days', 0);
        AttendanceDailySummary::query()->where('user_id', $agent->id)->where('work_date', '2026-08-21')->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.absent_days', 0)->assertJsonPath('data.0.missing_days', 1);
        $this->travelBack();
    }

    public function test_hr_report_duties_and_schedule_snapshots_are_counted(): void
    {
        Schema::table('attendance_daily_summaries', fn (Blueprint $table) => $table->json('schedule_snapshot')->nullable());
        $context = $this->context();
        $agent = $context['agent'];
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        AttendanceDuty::query()->create(['user_id' => $agent->id, 'date_from' => '2026-08-23', 'date_to' => '2026-08-23', 'created_by' => $context['hr']->id]);
        foreach (['2026-08-22', '2026-08-23'] as $date) AttendanceDailySummary::query()->create([
            'user_id' => $agent->id, 'work_date' => $date, 'status' => 'incomplete', 'late_minutes' => 0,
            'schedule_snapshot' => ['schedule' => [], 'timezone' => 'Asia/Dushanbe'],
        ]);
        Sanctum::actingAs($context['hr']);
        $this->getJson('/api/attendance/hr-report?date_from=2026-08-22&date_to=2026-08-23&search=Agent%20A')
            ->assertOk()->assertJsonPath('data.0.working_days', 1)->assertJsonPath('data.0.present_days', 1)->assertJsonPath('data.0.on_time_days', 1);
        $this->travelBack();
    }

    public function test_hr_report_enforces_roles_filters_and_period_limits(): void
    {
        $context = $this->context();
        $url = '/api/attendance/hr-report?date_from=2026-08-01&date_to=2026-08-31';
        foreach (['agent', 'mop', 'rop'] as $role) {
            Sanctum::actingAs($context[$role]);
            $this->getJson($url)->assertForbidden();
            $this->getJson($url.'&format=xlsx')->assertForbidden();
        }
        Sanctum::actingAs($context['hr']);
        $this->getJson($url.'&branch_id='.$context['otherAgent']->branch_id.'&role=agent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $context['otherAgent']->id);
        $this->getJson($url.'&branch_group_id='.$context['group']->id.'&search=Agent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $context['agent']->id);
        $context['agent']->update(['status' => User::STATUS_INACTIVE]);
        $this->getJson($url.'&branch_group_id='.$context['group']->id.'&search=Agent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/hr-report?date_from=2026-08-02&date_to=2026-08-01')->assertUnprocessable();
        $this->getJson('/api/attendance/hr-report?date_from=2025-01-01&date_to=2026-08-01')->assertUnprocessable();
    }

    public function test_hr_report_excel_matches_json_and_has_six_template_columns(): void
    {
        $context = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        AttendanceDailySummary::query()->create(['user_id' => $context['agent']->id, 'work_date' => '2026-08-17', 'status' => 'present', 'late_minutes' => 0]);
        Sanctum::actingAs($context['hr']);
        $url = '/api/attendance/hr-report?date_from=2026-08-17&date_to=2026-08-17&search=Agent%20A';
        $json = $this->getJson($url)->assertOk()->json();
        $response = $this->get($url.'&format=xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->assertCount(6, $sheet->xpath('//s:row[@r="4"]/s:c'));
        foreach (\App\Services\Attendance\AttendanceHrReport::HEADERS as $index => $header) $this->assertSame($header, (string) $sheet->xpath('//s:row[@r="4"]/s:c/s:is/s:t')[$index]);
        foreach (['working_days', 'present_days', 'absent_days', 'on_time_days'] as $index => $key) $this->assertSame($json['data'][0][$key], (int) $sheet->xpath('//s:c[@r="'.chr(67 + $index).'5"]/s:v')[0]);
        $this->assertFalse($zip->locateName('xl/worksheets/sheet2.xml'));
        $zip->close();
        unlink($path);
        $this->travelBack();
    }

    public function test_rop_unmapped_events_use_mapping_terminal_scope_before_pagination(): void
    {
        $c = $this->context();
        $otherGroup = BranchGroup::create(['branch_id' => $c['branch']->id, 'name' => 'Other group']);
        $shared = $this->device('UNMAPPED-SHARED', $c['branch']);
        $own = $this->device('UNMAPPED-OWN', $c['branch'], $c['group']);
        $other = $this->device('UNMAPPED-OTHER-GROUP', $c['branch'], $otherGroup);
        $foreign = $this->device('UNMAPPED-FOREIGN', $c['otherAgent']->branch);
        $unassigned = $this->device('UNMAPPED-NO-BRANCH');
        foreach ([$shared, $own, $other, $foreign, $unassigned] as $index => $device) {
            $this->postDevicePayload('/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG',
                "0096\t2026-08-17 09:0{$index}:00\t0\t15\t0")->assertOk();
            $this->postDevicePayload('/iclock/cdata?SN='.$device->serial_number.'&table=USERINFO',
                "PIN=0096\tName=".$device->name)->assertOk();
        }
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $c['rop']->role->forceFill(['permissions' => ['attendance.mappings.manage', 'data.scope.assigned_groups']])->save();
        Sanctum::actingAs($c['rop']->fresh());
        foreach (['true', 'false'] as $grouped) {
            $url = '/api/attendance/unmapped-events?grouped='.$grouped.'&per_page=1';
            $response = $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_id', $own->id)
                ->assertJsonPath('data.0.device_user_id', '0096')->assertJsonPath('data.0.device_user_name', $own->name);
            foreach (['raw_payload', 'request_meta', 'source_ip', 'processing_error', 'device.last_ip', 'device.communication_key'] as $field) {
                $response->assertJsonMissingPath('data.0.'.$field);
            }
            $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.device_id', $shared->id);
            foreach ([$other, $foreign, $unassigned] as $device) {
                $this->getJson($url.'&device_id='.$device->id)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
            }
        }
        $c['rop']->supervisedGroups()->detach();
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_id', $shared->id);
        $c['rop']->update(['branch_id' => null]);
        Sanctum::actingAs($c['rop']->fresh());
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()->assertJsonCount(0, 'data');
        $c['rop']->role->forceFill(['permissions' => ['data.scope.assigned_groups']])->save();
        Sanctum::actingAs($c['rop']->fresh());
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertForbidden();
        Sanctum::actingAs($c['hr']);
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()->assertJsonPath('total', 5);
        $this->assertDatabaseCount('attendance_device_users', 0);
    }

    public function test_rop_can_link_visible_unmapped_events_only_to_active_team_employee(): void
    {
        $c = $this->context();
        $device = $this->device('ROP-UNMAPPED-LINK', $c['branch']);
        $this->postDevicePayload('/iclock/cdata?SN=ROP-UNMAPPED-LINK&table=ATTLOG', implode("\n", [
            "0096\t2026-08-17 09:00:00\t0\t15\t0",
            "0096\t2026-08-17 18:00:00\t1\t15\t0",
        ]))->assertOk();
        Sanctum::actingAs($c['rop']);
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.events_count', 2);
        $data = ['device_id' => $device->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id];
        $this->putJson('/api/attendance/device-users', [...$data, 'user_id' => $c['otherAgent']->id])->assertNotFound();
        $otherGroup = BranchGroup::create(['branch_id' => $c['branch']->id, 'name' => 'Other team']);
        $c['otherAgent']->update(['branch_id' => $c['branch']->id, 'branch_group_id' => $otherGroup->id]);
        $this->putJson('/api/attendance/device-users', [...$data, 'user_id' => $c['otherAgent']->id])->assertNotFound();
        $c['agent']->update(['status' => User::STATUS_INACTIVE]);
        $this->putJson('/api/attendance/device-users', $data)->assertNotFound();
        $this->assertDatabaseCount('attendance_device_users', 0);
        $this->assertDatabaseCount('attendance_events', 0);
        $c['agent']->update(['status' => User::STATUS_ACTIVE]);
        $this->putJson('/api/attendance/device-users', $data)->assertOk()
            ->assertJsonPath('data.device_user_id', '0096')->assertJsonPath('reprocessed.processed', 2);
        $this->getJson('/api/attendance/unmapped-events?grouped=true')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(2, AttendanceEvent::query()->where('user_id', $c['agent']->id)->count());
        $this->assertSame($c['group']->id, $c['agent']->fresh()->branch_group_id);
        $this->assertSame([$c['group']->id], $c['rop']->supervisedGroups()->pluck('branch_groups.id')->all());
    }

    public function test_rop_links_only_team_users_and_cannot_take_another_users_terminal_id(): void
    {
        $c = $this->context();
        $device = $this->device('ROP-MAPPING', $c['branch'], $c['group']);
        $foreignDevice = $this->device('FOREIGN-MAPPING', $c['otherAgent']->branch, $c['otherAgent']->branchGroup);
        $existing = $this->map($device, $c['otherAgent']);
        Sanctum::actingAs($c['rop']);
        $data = ['device_id' => $device->id, 'device_user_id' => '00123', 'user_id' => $c['agent']->id];
        $this->putJson('/api/attendance/device-users', $data)->assertOk()->assertJsonPath('data.device_user_id', '00123');
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/attendance/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/attendance/device-users', [...$data, 'user_id' => $c['otherAgent']->id])->assertNotFound();
        $this->putJson('/api/attendance/device-users', [...$data, 'device_id' => $foreignDevice->id])->assertNotFound();
        $this->putJson('/api/attendance/device-users', [...$data, 'device_user_id' => $existing->device_user_id])->assertForbidden();
        $this->deleteJson('/api/attendance/device-users/'.$existing->id)->assertForbidden();
        $intern = $c['agent']->replicate();
        $intern->phone = '900000999'; $intern->role_id = Role::firstOrCreate(['slug' => 'intern'], ['name' => 'Intern'])->id; $intern->save();
        $this->putJson('/api/attendance/device-users', [...$data, 'device_user_id' => '00999', 'user_id' => $intern->id])->assertOk();
        $c['rop']->supervisedGroups()->detach();
        $this->putJson('/api/attendance/device-users', $data)->assertNotFound();
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($c['otherAgent']->id, (int) $existing->fresh()->user_id);
    }

    public function test_custom_role_attendance_scopes_and_group_subjects_are_configurable(): void
    {
        $c = $this->context();
        $role = Role::create(['name' => 'Attendance observer', 'slug' => 'attendance_observer']);
        $c['hr']->forceFill(['role_id' => $role->id])->save();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $access = app(\App\Services\Attendance\AttendanceAccessService::class);
        $url = '/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users';
        foreach ([null => 1, 'attendance.scope.group' => 2, 'attendance.scope.branch' => 5, 'attendance.all_branches.view' => 6] as $scope => $count) {
            $role->forceFill(['permissions' => ['attendance.table.view', ...($scope ? [$scope] : [])]])->save();
            $actor = $c['hr']->fresh();
            Sanctum::actingAs($actor);
            $this->getJson($url)->assertOk()->assertJsonCount($count, 'data');
            $this->assertSame($count, $access->visibleUsersQuery($actor)->count());
            $this->getJson('/api/attendance/users/'.$c['otherAgent']->id.'/days/2026-08-17')
                ->assertStatus($scope === 'attendance.all_branches.view' ? 200 : 403);
        }
        $role->forceFill(['permissions' => ['attendance.table.view', 'attendance.scope.group']])->save();
        $subjectRole = Role::create(['name' => 'Custom participant', 'slug' => 'attendance_participant']);
        $c['agent']->forceFill(['role_id' => $subjectRole->id])->save();
        foreach ([[], ['attendance.subject.group'], []] as $rights) {
            $subjectRole->forceFill(['permissions' => $rights])->save();
            Sanctum::actingAs($c['hr']->fresh());
            $this->getJson($url)->assertOk()->assertJsonCount($rights ? 2 : 1, 'data');
            $this->getJson('/api/attendance/users/'.$c['agent']->id.'/days/2026-08-17')
                ->assertStatus($rights ? 200 : 403);
        }
        // Losing the group must never expand the selection to other unassigned employees.
        $c['hr']->forceFill(['branch_group_id' => null])->save();
        Sanctum::actingAs($c['hr']->fresh());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $role->forceFill(['permissions' => ['attendance.scope.branch']])->save();
        Sanctum::actingAs($c['hr']->fresh());
        $this->getJson($url)->assertForbidden();
        // Existing named roles also obey changes in their configured scope.
        $c['mop']->role->forceFill(['permissions' => ['attendance.table.view']])->save();
        Sanctum::actingAs($c['mop']->fresh());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_assigned_group_attendance_subject_policies_are_independent_and_revocable(): void
    {
        $c = $this->context();
        $role = Role::create(['name' => 'Custom attendance employee', 'slug' => 'custom_attendance_employee']);
        $c['agent']->forceFill(['role_id' => $role->id])->save();
        $c['otherAgent']->forceFill(['role_id' => $role->id])->save();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $access = app(\App\Services\Attendance\AttendanceAccessService::class);
        $viewer = $c['rop']->fresh();
        $url = '/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17&view=users';
        foreach ([[], ['attendance.subject.mapping_assigned_groups'], ['attendance.subject.assigned_groups'],
            ['attendance.subject.mapping_assigned_groups', 'attendance.subject.assigned_groups'],
            ['attendance.subject.assigned_groups', 'attendance.subject.exclude_table'], []] as $rights) {
            $role->forceFill(['permissions' => $rights])->save();
            $mapping = $access->mappingUsersQuery($viewer)->pluck('users.id')->all();
            $visible = $access->visibleUsersQuery($viewer)->pluck('users.id')->all();
            $this->assertSame(in_array('attendance.subject.mapping_assigned_groups', $rights), in_array($c['agent']->id, $mapping));
            $expected = in_array('attendance.subject.assigned_groups', $rights) && ! in_array('attendance.subject.exclude_table', $rights);
            $this->assertSame($expected, in_array($c['agent']->id, $visible));
            $this->assertNotContains($c['otherAgent']->id, $mapping);
            $this->assertNotContains($c['otherAgent']->id, $visible);
            Sanctum::actingAs($viewer);
            $response = $this->getJson($url)->assertOk();
            $this->assertSame($expected, in_array($c['agent']->id, collect($response->json('data'))->pluck('user.id')->all()));
        }
        // An exclusion also applies to an observer with access to every branch.
        $c['hr']->role->forceFill(['permissions' => ['attendance.all_branches.view']])->save();
        foreach ([['attendance.subject.exclude_table'], []] as $rights) {
            $role->forceFill(['permissions' => $rights])->save();
            $this->assertSame($rights === [], $access->visibleUsersQuery($c['hr']->fresh())->whereKey($c['agent']->id)->exists());
        }
        $this->assertNotNull($c['agent']->fresh());
    }

    public function test_custom_attendance_history_uses_saved_role_and_preserves_group_boundaries(): void
    {
        $c = $this->context();
        (require database_path('migrations/2026_09_09_010000_add_attendance_historical_context.php'))->up();
        $role = Role::create(['name' => 'Historical employee', 'slug' => 'historical_attendance_employee']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $summary = AttendanceDailySummary::create(['user_id' => $c['otherAgent']->id,
            'branch_group_id' => $c['group']->id, 'role_slug' => $role->slug,
            'work_date' => '2026-08-17', 'status' => 'present']);
        $foreign = AttendanceDailySummary::create(['user_id' => $c['agent']->id,
            'branch_group_id' => $c['otherAgent']->branch_group_id, 'role_slug' => $role->slug,
            'work_date' => '2026-08-17', 'status' => 'present']);
        $scope = app(\App\Services\Attendance\AttendanceGroupScope::class);
        foreach ([[], ['attendance.subject.assigned_groups'],
            ['attendance.subject.assigned_groups', 'attendance.subject.exclude_table'], []] as $rights) {
            $role->forceFill(['permissions' => $rights])->save();
            $ids = $scope->apply(AttendanceDailySummary::query(), $c['rop']->fresh())->pluck('id')->all();
            $this->assertSame($rights === ['attendance.subject.assigned_groups'], in_array($summary->id, $ids));
            $this->assertNotContains($foreign->id, $ids);
        }
        $this->assertSame($role->slug, $summary->fresh()->role_slug);
        $this->assertSame(2, AttendanceDailySummary::count());
    }

    public function test_mapping_picker_uses_own_permission_subject_policy_and_search(): void
    {
        $c = $this->context();
        $role = Role::create(['name' => 'Custom participant', 'slug' => 'custom_mapping_picker']);
        $c['agent']->forceFill(['role_id' => $role->id])->save();
        $c['otherAgent']->forceFill(['role_id' => $role->id])->save();
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        Sanctum::actingAs($c['rop']->fresh());
        $url = '/api/attendance/mapping-users?search='.$c['agent']->id;
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $role->forceFill(['permissions' => ['attendance.subject.mapping_assigned_groups']])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $c['agent']->id)->assertJsonMissingPath('data.0.email');
        $this->getJson('/api/attendance/mapping-users?search='.$c['otherAgent']->id)->assertOk()->assertJsonCount(0, 'data');
        $role->forceFill(['permissions' => ['attendance.subject.mapping_assigned_groups', 'attendance.subject.exclude_mapping_picker']])->save();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $c['hr']->role->forceFill(['permissions' => ['attendance.mappings.manage']])->save();
        Sanctum::actingAs($c['hr']->fresh());
        $role->forceFill(['permissions' => []])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $c['agent']->id);
        $c['agent']->forceFill(['status' => 'inactive'])->save();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $c['hr']->role->forceFill(['permissions' => []])->save();
        Sanctum::actingAs($c['hr']->fresh());
        $this->getJson($url)->assertForbidden();
    }

    public function test_mapping_right_alone_loads_scoped_terminal_picker_and_saves_mapping(): void
    {
        $c = $this->context();
        $own = $this->device('PICKER-OWN', $c['branch'], $c['group']);
        $foreign = $this->device('PICKER-FOREIGN', $c['otherAgent']->branch, $c['otherAgent']->branchGroup);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $c['rop']->role->forceFill(['permissions' => ['attendance.mappings.manage', 'data.scope.assigned_groups']])->save();
        Sanctum::actingAs($c['rop']->fresh());
        $this->getJson('/api/attendance/access')->assertOk()->assertJsonPath('permissions.can_manage_mappings', true)
            ->assertJsonPath('permissions.can_view_attendance_table', false)->assertJsonPath('permissions.can_view_devices', false);
        $this->getJson('/api/attendance/matrix')->assertForbidden();
        $this->getJson('/api/attendance/devices')->assertForbidden();
        $result = $this->getJson('/api/attendance/mapping-devices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->json('data.0');
        $this->assertArrayNotHasKey('last_ip', $result);
        $this->assertArrayNotHasKey('communication_key', $result);
        $payload = ['device_id' => $own->id, 'device_user_id' => '00012', 'user_id' => $c['agent']->id];
        $this->putJson('/api/attendance/device-users', $payload)->assertOk();
        $this->putJson('/api/attendance/device-users', [...$payload, 'device_id' => $foreign->id])->assertNotFound();
        $c['rop']->role->forceFill(['permissions' => ['data.scope.assigned_groups']])->save();
        Sanctum::actingAs($c['rop']->fresh());
        $this->getJson('/api/attendance/mapping-devices')->assertForbidden();
        $this->putJson('/api/attendance/device-users', $payload)->assertForbidden();
    }

    public function test_branch_director_sync_lists_only_own_branch_employees_terminals_and_links(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        $own = $this->device('DIRECTOR-OWN', $c['branch'], $c['group']);
        $shared = $this->device('DIRECTOR-SHARED', $c['branch']);
        $foreign = $this->device('DIRECTOR-FOREIGN', $c['otherAgent']->branch);
        $unassigned = $this->device('DIRECTOR-UNASSIGNED');
        $ownLink = $this->map($own, $c['agent']);
        $this->map($own, $c['otherAgent']);
        $this->map($foreign, $c['agent']);
        $this->map($foreign, $c['otherAgent']);
        $this->map($unassigned, $c['agent']);
        Sanctum::actingAs($director);
        $this->getJson('/api/attendance/access')->assertOk()->assertJsonPath('permissions.can_manage_mappings', true)
            ->assertJsonPath('permissions.can_view_devices', false)->assertJsonPath('permissions.can_manage_devices', false);
        $this->getJson('/api/attendance/devices')->assertForbidden();
        $this->getJson('/api/attendance/mapping-devices')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.1.id', $shared->id);
        $this->getJson('/api/attendance/mapping-users?search='.$c['otherAgent']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/mapping-users?search='.$c['agent']->id)->assertOk()->assertJsonPath('data.0.id', $c['agent']->id);
        $this->getJson('/api/attendance/device-users?per_page=1')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ownLink->id);
        $this->getJson('/api/attendance/device-users?user_id='.$c['otherAgent']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/attendance/device-users?device_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        // All groups in the branch are available, not only the director's own group.
        $c['otherAgent']->update(['branch_id' => $c['branch']->id, 'branch_group_id' => null]);
        $this->getJson('/api/attendance/mapping-users?search='.$c['otherAgent']->id)->assertOk()->assertJsonPath('data.0.id', $c['otherAgent']->id);
    }

    public function test_branch_director_can_sync_and_disable_local_links_but_cannot_modify_foreign_links(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        $own = $this->device('DIRECTOR-LINK', $c['branch']);
        $foreign = $this->device('DIRECTOR-OTHER', $c['otherAgent']->branch);
        $unassigned = $this->device('DIRECTOR-NONE');
        $foreignLink = $this->map($own, $c['otherAgent']);
        $remoteLink = $this->map($foreign, $c['agent']);
        $this->postDevicePayload('/iclock/cdata?SN=DIRECTOR-LINK&table=ATTLOG',
            "0096\t2026-08-17 09:00:00\t0\t15\t0")->assertOk();
        Sanctum::actingAs($director);
        $payload = ['device_id' => $own->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id];
        $this->putJson('/api/attendance/device-users', [...$payload, 'user_id' => $c['otherAgent']->id])->assertNotFound();
        foreach ([$foreign, $unassigned] as $device) {
            $this->putJson('/api/attendance/device-users', [...$payload, 'device_id' => $device->id])->assertNotFound();
        }
        $this->putJson('/api/attendance/device-users', [...$payload, 'device_user_id' => $foreignLink->device_user_id])->assertNotFound();
        foreach ([$foreignLink, $remoteLink] as $link) {
            $this->deleteJson('/api/attendance/device-users/'.$link->id)->assertNotFound();
            $this->assertDatabaseHas('attendance_device_users', ['id' => $link->id, 'user_id' => $link->user_id, 'is_active' => true]);
        }
        $response = $this->putJson('/api/attendance/device-users', $payload)->assertOk()->assertJsonPath('reprocessed.processed', 1);
        $localId = $response->json('data.id');
        $c['agent']->update(['status' => User::STATUS_INACTIVE]);
        $this->getJson('/api/attendance/device-users')->assertOk()->assertJsonPath('data.0.id', $localId);
        $this->putJson('/api/attendance/device-users', [...$payload, 'is_active' => false])->assertOk();
        $this->putJson('/api/attendance/device-users', [...$payload, 'is_active' => true])->assertUnprocessable();
        $this->deleteJson('/api/attendance/device-users/'.$localId)->assertOk();
        $this->assertDatabaseMissing('attendance_device_users', ['id' => $localId]);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $c['agent']->id, 'device_id' => $own->id]);
    }

    public function test_branch_director_cannot_reuse_terminal_id_with_another_employees_history(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        $own = $this->device('DIRECTOR-HISTORY', $c['branch']);
        $link = $this->map($own, $c['otherAgent']);
        $this->postDevicePayload('/iclock/cdata?SN=DIRECTOR-HISTORY&table=ATTLOG',
            $link->device_user_id."\t2026-08-17 09:00:00\t0\t15\t0")->assertOk();
        $link->delete();
        Sanctum::actingAs($director);
        $this->putJson('/api/attendance/device-users', [
            'device_id' => $own->id, 'device_user_id' => $link->device_user_id, 'user_id' => $c['agent']->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('attendance_device_users', 0);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $c['otherAgent']->id]);
    }

    public function test_branch_director_unmapped_events_are_scoped_before_pagination_and_deletion(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        $own = $this->device('DIRECTOR-RAW-OWN', $c['branch']);
        $foreign = $this->device('DIRECTOR-RAW-FOREIGN', $c['otherAgent']->branch);
        $unassigned = $this->device('DIRECTOR-RAW-NONE');
        foreach ([$own, $foreign, $unassigned] as $device) {
            $this->postDevicePayload('/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG',
                "0096\t2026-08-17 09:00:00\t0\t15\t0")->assertOk();
        }
        Sanctum::actingAs($director);
        foreach (['true', 'false'] as $grouped) {
            $url = '/api/attendance/unmapped-events?grouped='.$grouped.'&per_page=1';
            $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.device_id', $own->id)
                ->assertJsonMissingPath('data.0.raw_payload')->assertJsonMissingPath('data.0.device.communication_key');
            $this->getJson($url.'&device_id='.$foreign->id)->assertOk()->assertJsonPath('total', 0);
        }
        foreach ([$foreign, $unassigned] as $device) {
            $this->deleteJson('/api/attendance/unmapped-events', [
                'device_id' => $device->id, 'device_user_id' => '0096', 'last_raw_event_id' => AttendanceRawEvent::max('id'),
            ])->assertNotFound();
        }
        $this->deleteJson('/api/attendance/unmapped-events', [
            'device_id' => $own->id, 'device_user_id' => '0096', 'last_raw_event_id' => AttendanceRawEvent::max('id'),
        ])->assertOk()->assertJsonPath('deleted_count', 1);
        $this->assertDatabaseCount('attendance_raw_events', 2);
    }

    public function test_branch_mapping_scope_is_independent_of_table_scope_and_revocation_fails_closed(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        $own = $this->device('DIRECTOR-SCOPE', $c['branch']);
        $foreign = $this->device('DIRECTOR-SCOPE-OTHER', $c['otherAgent']->branch);
        $link = $this->map($own, $c['agent']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        // The restriction also works with custom role names and broader table visibility.
        $director->role->forceFill(['slug' => 'custom_branch_sync', 'permissions' => [
            'attendance.mappings.manage', 'attendance.mappings.scope.branch', 'attendance.all_branches.view',
        ]])->save();
        Sanctum::actingAs($director->fresh());
        $this->getJson('/api/attendance/mapping-devices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/attendance/mapping-users?search='.$c['otherAgent']->id)->assertOk()->assertJsonCount(0, 'data');
        $director->update(['branch_id' => null]);
        Sanctum::actingAs($director->fresh());
        foreach (['mapping-devices', 'mapping-users', 'device-users', 'unmapped-events'] as $endpoint) {
            $this->getJson('/api/attendance/'.$endpoint)->assertOk()->assertJsonCount(0, 'data');
        }
        $payload = ['device_id' => $own->id, 'device_user_id' => '0096', 'user_id' => $c['agent']->id];
        $this->putJson('/api/attendance/device-users', $payload)->assertNotFound();
        $this->deleteJson('/api/attendance/device-users/'.$link->id)->assertNotFound();
        $director->update(['branch_id' => $c['branch']->id]);
        $director->role->forceFill(['permissions' => ['attendance.mappings.scope.branch']])->save();
        Sanctum::actingAs($director->fresh());
        $this->getJson('/api/attendance/access')->assertOk()->assertJsonPath('permissions.can_manage_mappings', false);
        $this->getJson('/api/attendance/mapping-devices')->assertForbidden();
        $this->putJson('/api/attendance/device-users', $payload)->assertForbidden();
        // HR keeps its existing global mapping access.
        Sanctum::actingAs($c['hr']->fresh());
        $this->getJson('/api/attendance/mapping-devices')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/attendance/device-users', [...$payload, 'device_id' => $foreign->id, 'user_id' => $c['otherAgent']->id])->assertOk();
    }

    public function test_branch_director_sync_grant_migration_preserves_permissions_and_is_audited_and_idempotent(): void
    {
        $c = $this->context();
        $director = $this->branchDirector($c['branch']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $before = ['attendance.table.view', 'attendance.scope.branch', 'roles.view'];
        $director->role->forceFill(['permissions' => $before])->save();
        $hrBefore = $c['hr']->fresh()->role->permissionKeys();
        $migration = require database_path('migrations/2026_10_20_120000_enable_branch_director_attendance_sync.php');
        $migration->up();
        $expected = [...$before, 'attendance.mappings.manage', 'attendance.mappings.scope.branch'];
        $this->assertSame($expected, $director->role->fresh()->permissionKeys());
        $this->assertSame($hrBefore, $c['hr']->fresh()->role->permissionKeys());
        $audit = DB::table('role_permission_audits')->sole();
        $this->assertSame($before, json_decode($audit->before, true));
        $this->assertSame($expected, json_decode($audit->after, true));
        $this->assertSame(2, $director->role->fresh()->permissions_version);
        $migration->up();
        $this->assertDatabaseCount('role_permission_audits', 1);
        $migration->down();
        $this->assertSame($before, $director->role->fresh()->permissionKeys());
        $this->assertSame(3, $director->role->fresh()->permissions_version);
    }

    private function branchDirector(Branch $branch): User
    {
        $role = Role::firstOrCreate(['slug' => 'branch_director'], ['name' => 'Директор филиала']);
        return User::create([
            'name' => 'Director', 'phone' => '992900000991', 'role_id' => $role->id,
            'branch_id' => $branch->id, 'status' => User::STATUS_ACTIVE, 'auth_method' => 'password',
        ]);
    }

    public function test_rop_broad_attendance_grants_never_override_assigned_groups(): void
    {
        $c = $this->context();
        $own = AttendanceDailySummary::create(['user_id' => $c['agent']->id, 'branch_group_id' => $c['group']->id,
            'work_date' => '2026-08-17', 'status' => 'present']);
        $foreign = AttendanceDailySummary::create(['user_id' => $c['otherAgent']->id, 'branch_group_id' => $c['otherAgent']->branch_group_id,
            'work_date' => '2026-08-17', 'status' => 'present']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $rights = $c['rop']->role->permissionKeys();
        foreach ([['attendance.all_branches.view'], ['attendance.scope.assigned_branches'], ['attendance.all_branches.view', 'attendance.scope.assigned_branches']] as $extra) {
            $c['rop']->role->forceFill(['permissions' => array_values(array_unique([...$rights, ...$extra]))])->save();
            Sanctum::actingAs($c['rop']->fresh());
            $response = $this->getJson('/api/attendance/matrix?date_from=2026-08-17&date_to=2026-08-17')->assertOk()
                ->assertJsonPath('meta.permissions.can_view_all_branches', false)->assertJsonPath('meta.selectable_branches', null);
            $ids = collect($response->json('data'))->pluck('user.id')->all();
            $this->assertContains($c['agent']->id, $ids);
            $this->assertNotContains($c['otherAgent']->id, $ids);
            $visible = app(\App\Services\Attendance\AttendanceGroupScope::class)->apply(AttendanceDailySummary::query(), $c['rop']->fresh())->pluck('id')->all();
            $this->assertContains($own->id, $visible); $this->assertNotContains($foreign->id, $visible);
            $this->getJson('/api/attendance/users/'.$c['otherAgent']->id.'/days/2026-08-17')->assertNotFound();
        }
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

        $context = [
            'branch' => $branchA,
            'group' => $groupA,
            'agent' => $make('agent', 'Agent A', $branchA, $groupA),
            'mop' => $make('mop', 'MOP A', $branchA, $groupA),
            'rop' => $make('rop', 'ROP A', $branchA, $groupA),
            'admin' => $make('admin', 'Admin', $branchA, $groupA),
            'hr' => $make('hr', 'HR', $branchA, $groupA),
            'otherAgent' => $make('agent', 'Agent B', $branchB, $groupB),
        ];
        $context['rop']->supervisedGroups()->syncWithoutDetaching([$groupA->id]);
        return $context;
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
