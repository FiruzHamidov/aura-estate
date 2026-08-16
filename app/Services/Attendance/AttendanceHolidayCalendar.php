<?php

namespace App\Services\Attendance;

use App\Models\AttendanceHoliday;
use Illuminate\Support\Collection;

final class AttendanceHolidayCalendar
{
    private array $cache = [];

    public function holiday(string $date): ?AttendanceHoliday
    {
        if (! array_key_exists($date, $this->cache)) {
            $this->cache[$date] = AttendanceHoliday::query()->whereDate('holiday_date', $date)->first();
        }

        return $this->cache[$date];
    }

    public function isHoliday(string $date): bool
    {
        return $this->holiday($date) !== null;
    }

    public function between(string $from, string $to): Collection
    {
        return AttendanceHoliday::query()->whereBetween('holiday_date', [$from, $to])
            ->orderBy('holiday_date')->get()->keyBy(fn (AttendanceHoliday $holiday) => $holiday->holiday_date->toDateString());
    }

    public function forget(string $date): void
    {
        unset($this->cache[$date]);
    }
}
