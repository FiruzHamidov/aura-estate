<?php

namespace App\Services\Attendance;

use App\Models\AttendanceGlobalSchedule;
use App\Models\AttendanceWorkSchedule;
use App\Models\User;

final class AttendanceScheduleResolver
{
    private bool $globalLoaded = false;
    private ?AttendanceGlobalSchedule $globalSchedule = null;

    public function global(): ?AttendanceGlobalSchedule
    {
        if (! $this->globalLoaded) {
            $this->globalSchedule = AttendanceGlobalSchedule::query()->first();
            $this->globalLoaded = true;
        }

        return $this->globalSchedule;
    }

    public function forUser(User|int $user): AttendanceWorkSchedule|AttendanceGlobalSchedule|null
    {
        $userId = $user instanceof User ? $user->id : $user;

        return AttendanceWorkSchedule::query()->where('user_id', $userId)->first() ?? $this->global();
    }

    public function schedule(?AttendanceWorkSchedule $individual = null): array
    {
        return $individual?->schedule ?? $this->global()?->schedule ?? config('attendance.default_schedule', []);
    }

    public function timezone(AttendanceWorkSchedule|AttendanceGlobalSchedule|null $settings = null): string
    {
        return $settings?->timezone ?: (string) ($this->global()?->timezone ?: config('attendance.timezone', 'Asia/Dushanbe'));
    }

    public function forgetGlobal(): void
    {
        $this->globalLoaded = false;
        $this->globalSchedule = null;
    }
}
