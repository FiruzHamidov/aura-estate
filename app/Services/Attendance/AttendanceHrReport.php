<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDailySummary;
use App\Models\AttendanceDuty;
use App\Models\AttendanceLeave;
use App\Models\AttendanceWorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class AttendanceHrReport
{
    public const HEADERS = ['СОТРУДНИК', 'ДОЛЖНОСТЬ', 'КОЛИЧЕСТВО РАБОЧИХ ДНЕЙ', 'БЫЛ', 'НЕ БЫЛ', 'БЕЗ ОПОЗДАНИЙ'];

    public const RULES = 'Рабочие дни — по графику и дежурствам, без отпусков и праздников. Был — явки в рабочие дни, включая дни без отметки ухода. Без опозданий — явки с нулевым опозданием. Будущие дни не считаются отсутствием. Дни без данных учитываются отдельно.';

    public function __construct(private readonly AttendanceScheduleResolver $schedules, private readonly AttendanceHolidayCalendar $holidays) {}

    public function build(Collection $users, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $users->pluck('id');
        $dates = collect(CarbonPeriod::create($from, $to))->map(fn ($date) => $date->toDateString());
        $summaries = AttendanceDailySummary::query()->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn ($rows) => $rows->keyBy(fn ($row) => $row->work_date->toDateString()));
        $periods = fn ($model) => $model::query()->whereIn('user_id', $ids)
            ->whereDate('date_from', '<=', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())->get()->groupBy('user_id');
        $leaves = $periods(AttendanceLeave::class);
        $duties = $periods(AttendanceDuty::class);
        $settings = AttendanceWorkSchedule::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $holidays = $this->holidays->between($from->toDateString(), $to->toDateString());
        $today = CarbonImmutable::now(config('attendance.timezone'))->toDateString();
        $inPeriod = fn ($periods, $date) => $periods->contains(fn ($period) => $period->date_from->toDateString() <= $date && $period->date_to->toDateString() >= $date);
        $rows = $users->map(function ($user) use ($dates, $summaries, $settings, $holidays, $leaves, $duties, $today, $inPeriod) {
            $counts = ['working_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'on_time_days' => 0, 'missing_days' => 0, 'pending_days' => 0];
            foreach ($dates as $date) {
                $summary = $summaries->get($user->id, collect())->get($date);
                if ($holidays->has($date) || $inPeriod($leaves->get($user->id, collect()), $date)) continue;
                if (! $this->schedules->isWorkingDate($date, $settings->get($user->id), $summary?->schedule_snapshot)
                    && ! $inPeriod($duties->get($user->id, collect()), $date)) continue;
                $counts['working_days']++;
                if ($date > $today) { $counts['pending_days']++; continue; }
                if (in_array($summary?->status, ['present', 'late', 'incomplete'], true)) {
                    $counts['present_days']++;
                    if ($summary->status !== 'late' && $summary->late_minutes !== null && (int) $summary->late_minutes === 0) $counts['on_time_days']++;
                } elseif ($summary?->status === 'absent' && $date < $today) {
                    $counts['absent_days']++;
                } elseif ($date === $today) {
                    $counts['pending_days']++;
                } else {
                    $counts['missing_days']++;
                }
            }
            return [
                'user_id' => $user->id, 'name' => $user->name,
                'position' => $user->role?->name ?? '—',
                'branch' => $user->branch?->name ?? 'Без филиала',
                'group' => $user->branchGroup?->name ?? 'Без группы',
                ...$counts,
            ];
        })->values();
        $totals = [];
        foreach (['working_days', 'present_days', 'absent_days', 'on_time_days', 'missing_days', 'pending_days'] as $key) $totals[$key] = $rows->sum($key);
        return ['data' => $rows->all(), 'meta' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'as_of' => $today, 'employee_count' => $rows->count(), 'totals' => $totals, 'rules' => self::RULES]];
    }
}
