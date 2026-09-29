<?php

namespace App\Console\Commands;

use App\Models\AttendanceAuditLog;
use App\Models\AttendanceEvent;
use App\Models\User;
use App\Services\Attendance\AttendanceEventClassifier;
use App\Services\Attendance\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReclassifyAttendanceFieldworkCommand extends Command
{
    protected $signature = 'attendance:reclassify-fieldwork {--apply : Persist changes; otherwise preview only}';

    protected $description = 'Reclassify terminal marks since the configured fieldwork cutover and rebuild affected days';

    public function handle(AttendanceEventClassifier $classifier, AttendanceSummaryService $summaries): int
    {
        $configured = config('attendance.fieldwork_started_at');
        if (! $configured) {
            $this->error('ATTENDANCE_FIELDWORK_STARTED_AT is not configured.');
            return self::FAILURE;
        }
        $from = CarbonImmutable::parse($configured, config('attendance.timezone'))->utc();
        $until = CarbonImmutable::now('UTC');
        $serials = config('attendance.fieldwork_device_serials', []);
        $window = max(0, (int) config('attendance.duplicate_window_seconds', 10));
        $apply = (bool) $this->option('apply');
        $changed = 0;
        $days = 0;

        $users = User::query()->whereIn('id', AttendanceEvent::query()->select('user_id')
            ->whereBetween('occurred_at', [$from, $until])
            ->when($serials !== [], fn ($query) => $query->whereHas('device', fn ($devices) => $devices->whereIn('serial_number', $serials))));
        foreach ($users->orderBy('id')->lazyById(100) as $user) {
            [$userChanged, $userDays] = DB::transaction(function () use ($user, $classifier, $summaries, $from, $until, $window, $apply, $serials) {
                // Ingestion uses the same user lock; avoid a partial day during replay.
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $events = AttendanceEvent::query()->with('device')->where('user_id', $user->id)
                    ->whereBetween('occurred_at', [$from->subSeconds($window), $until])
                    ->orderBy('occurred_at')->orderBy('id')->get();
                $previousByType = [];
                $affected = [];
                $count = 0;
                foreach ($events as $event) {
                    $original = $event->only(['event_type', 'direction', 'is_duplicate']);
                    $code = $event->meta['attendance_status'] ?? null;
                    $eligible = $event->occurred_at->greaterThanOrEqualTo($from) && $event->device !== null
                        && $code !== null && ($serials === [] || in_array($event->device->serial_number, $serials, true));
                    if ($eligible) {
                        $event->event_type = $classifier->classify($event->device, (string) $code, $event->occurred_at);
                        $event->direction = $classifier->direction($event->event_type);
                        $previous = $previousByType[$event->event_type] ?? null;
                        $event->is_duplicate = $previous !== null && $previous->diffInSeconds($event->occurred_at) <= $window;
                    }
                    $previousByType[$event->event_type] = $event->occurred_at;
                    if (! $eligible || ! $event->isDirty(['event_type', 'direction', 'is_duplicate'])) continue;
                    $count++;
                    $affected[$event->occurred_at->setTimezone($summaries->timezoneFor($lockedUser))->toDateString()] = true;
                    if ($apply) {
                        $event->save();
                        AttendanceAuditLog::query()->create([
                            'action' => 'attendance_event.fieldwork_reclassified',
                            'auditable_type' => AttendanceEvent::class,
                            'auditable_id' => $event->id,
                            'old_values' => $original,
                            'new_values' => $event->only(['event_type', 'direction', 'is_duplicate']),
                            'user_agent' => 'attendance:reclassify-fieldwork',
                        ]);
                    }
                }
                if ($apply) {
                    foreach (array_keys($affected) as $date) $summaries->recompute($lockedUser, $date);
                }
                return [$count, count($affected)];
            });
            $changed += $userChanged;
            $days += $userDays;
        }
        $this->info(($apply ? 'Applied' : 'Preview').": {$changed} events, {$days} employee-days. Cutover: {$from->toISOString()}");
        return self::SUCCESS;
    }
}
