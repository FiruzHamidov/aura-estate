<?php

namespace App\Http\Controllers;

use App\Models\AttendanceWorkSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AttendanceScheduleController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function show(Request $request, User $user)
    {
        $this->access->assertCanAdminister($request->user());
        $schedule = AttendanceWorkSchedule::query()->where('user_id', $user->id)->first();

        return response()->json(['data' => $schedule ?? [
            'user_id' => $user->id,
            'timezone' => config('attendance.timezone'),
            'schedule' => config('attendance.default_schedule'),
            'holidays' => [],
            'source' => 'default',
        ]]);
    }

    public function update(Request $request, User $user)
    {
        $this->access->assertCanAdminister($request->user());
        $user->loadMissing('role');
        if (! in_array($user->role?->slug, config('attendance.tracked_roles', []), true)) {
            throw ValidationException::withMessages([
                'user_id' => ['Роль сотрудника не участвует в учёте посещаемости.'],
            ]);
        }
        $data = $request->validate([
            'timezone' => ['required', 'timezone'],
            'schedule' => ['required', 'array:1,2,3,4,5,6,7'],
            'schedule.*' => ['nullable', 'array:start,end,grace_minutes'],
            'schedule.*.start' => ['required_with:schedule.*', 'date_format:H:i'],
            'schedule.*.end' => ['required_with:schedule.*', 'date_format:H:i'],
            'schedule.*.grace_minutes' => ['nullable', 'integer', 'between:0,240'],
            'holidays' => ['nullable', 'array'],
            'holidays.*' => ['date_format:Y-m-d'],
            'change_reason' => ['required', 'string', 'max:500'],
        ]);
        $existing = AttendanceWorkSchedule::query()->where('user_id', $user->id)->first();
        $old = $existing?->only(['timezone', 'schedule', 'holidays', 'change_reason']) ?? [];
        $schedule = AttendanceWorkSchedule::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                ...$data,
                'holidays' => array_values(array_unique($data['holidays'] ?? [])),
                'configured_by' => $request->user()->id,
            ]
        );
        $this->audit->record(
            $request->user(),
            $old === [] ? 'attendance_schedule.created' : 'attendance_schedule.updated',
            $schedule,
            $old,
            $schedule->only(['timezone', 'schedule', 'holidays', 'change_reason']),
            $request
        );

        return response()->json(['data' => $schedule]);
    }
}
