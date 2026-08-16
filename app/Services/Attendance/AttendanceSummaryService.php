<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDailySummary;
use App\Models\AttendanceEvent;
use App\Models\AttendanceLeave;
use App\Models\AttendanceWorkSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AttendanceSummaryService
{
    public function __construct(private readonly AttendanceHolidayCalendar $holidays) {}

    public function recompute(User $user, string $workDate): AttendanceDailySummary
    {
        $settings = $this->settings($user);
        $timezone = $this->timezone($settings);
        $localStart = CarbonImmutable::parse($workDate, $timezone)->startOfDay();
        $localEnd = $localStart->endOfDay();
        $events = AttendanceEvent::query()
            ->where('user_id', $user->id)
            ->where('is_duplicate', false)
            ->whereBetween('occurred_at', [$localStart->utc(), $localEnd->utc()])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $punches = $events->where('event_type', 'punch');
        $first = $events->firstWhere('event_type', 'check_in');
        if ($first === null && $punches->isNotEmpty()) {
            $first = $punches->first();
        }
        $last = $events->where('event_type', 'check_out')->last();
        if ($last === null && $punches->count() > 1) {
            $last = $punches->last();
        }
        if ($first === null || $last?->is($first)) {
            $last = null;
        }

        $firstAt = $first?->occurred_at;
        $lastAt = $last?->occurred_at;
        $workedMinutes = null;
        if ($firstAt && $lastAt && $lastAt->greaterThanOrEqualTo($firstAt)) {
            $grossMinutes = (int) $firstAt->diffInMinutes($lastAt);
            $workedMinutes = max(0, $grossMinutes - $this->completedBreakMinutes($events, $firstAt->toImmutable(), $lastAt->toImmutable()));
        }
        $lateMinutes = $this->lateMinutes(
            $settings?->schedule ?? config('attendance.default_schedule', []),
            $firstAt?->toImmutable(),
            $localStart
        );
        $status = $events->isEmpty()
            ? 'absent'
            : ($firstAt === null || $lastAt === null ? 'incomplete' : ($lateMinutes > 0 ? 'late' : 'present'));

        return AttendanceDailySummary::query()->updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $workDate],
            [
                'first_in_at' => $firstAt,
                'last_out_at' => $lastAt,
                'first_event_id' => $first?->id,
                'last_event_id' => $last?->id,
                'events_count' => $events->count(),
                'device_ids' => $events->pluck('device_id')->unique()->sort()->values()->all(),
                'worked_minutes' => $workedMinutes,
                'late_minutes' => $lateMinutes,
                'status' => $status,
            ]
        );
    }

    public function isWorkingDay(User $user, string $workDate): bool
    {
        if ($this->holidays->isHoliday($workDate)) {
            return false;
        }
        if (AttendanceLeave::query()->where('user_id', $user->id)
            ->whereDate('date_from', '<=', $workDate)->whereDate('date_to', '>=', $workDate)->exists()) {
            return false;
        }
        $settings = $this->settings($user);
        $timezone = $this->timezone($settings);
        $day = CarbonImmutable::parse($workDate, $timezone);
        if ($settings && in_array($day->toDateString(), $settings->holidays ?? [], true)) {
            return false;
        }

        return is_array(($settings?->schedule ?? config('attendance.default_schedule', []))[(string) $day->dayOfWeekIso] ?? null);
    }

    public function timezoneFor(User $user): string
    {
        return $this->timezone($this->settings($user));
    }

    private function completedBreakMinutes(Collection $events, CarbonImmutable $firstAt, CarbonImmutable $lastAt): int
    {
        $breakStartedAt = null;
        $minutes = 0;

        foreach ($events as $event) {
            $occurredAt = $event->occurred_at?->toImmutable();
            if ($occurredAt === null || $occurredAt->lessThan($firstAt) || $occurredAt->greaterThan($lastAt)) {
                continue;
            }

            if ($event->event_type === 'break_out' && $breakStartedAt === null) {
                $breakStartedAt = $occurredAt;

                continue;
            }

            if ($event->event_type === 'break_in' && $breakStartedAt !== null && $occurredAt->greaterThan($breakStartedAt)) {
                $minutes += (int) $breakStartedAt->diffInMinutes($occurredAt);
                $breakStartedAt = null;
            }
        }

        return $minutes;
    }

    private function lateMinutes(array $weeklySchedule, ?CarbonImmutable $firstAtUtc, CarbonImmutable $localDay): int
    {
        if ($firstAtUtc === null) {
            return 0;
        }

        $schedule = $weeklySchedule[(string) $localDay->dayOfWeekIso] ?? null;
        if (! is_array($schedule) || empty($schedule['start'])) {
            return 0;
        }

        $start = CarbonImmutable::parse(
            $localDay->toDateString().' '.$schedule['start'],
            $localDay->timezone
        )->addMinutes((int) ($schedule['grace_minutes'] ?? 0));
        $firstLocal = $firstAtUtc->setTimezone($localDay->timezone);

        return $firstLocal->greaterThan($start) ? (int) $start->diffInMinutes($firstLocal) : 0;
    }

    private function settings(User $user): ?AttendanceWorkSchedule
    {
        return AttendanceWorkSchedule::query()->where('user_id', $user->id)->first();
    }

    private function timezone(?AttendanceWorkSchedule $settings): string
    {
        return $settings?->timezone ?: (string) config('attendance.timezone', 'Asia/Dushanbe');
    }
}
