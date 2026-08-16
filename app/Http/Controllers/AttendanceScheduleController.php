<?php

namespace App\Http\Controllers;

use App\Models\AttendanceWorkSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendanceParticipantService;
use App\Services\Attendance\AttendanceScheduleResolver;
use Illuminate\Http\Request;

final class AttendanceScheduleController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
        private readonly AttendanceParticipantService $participants,
        private readonly AttendanceScheduleResolver $resolver,
    ) {}

    public function showGlobal(Request $request)
    {
        $this->access->assertCanManageSchedules($request->user());
        $schedule = $this->resolver->global();

        return response()->json(['data' => $schedule ? [
            ...$schedule->only(['id', 'timezone', 'schedule', 'change_reason', 'configured_by', 'updated_at']),
            'source' => 'global',
        ] : [
            'timezone' => config('attendance.timezone'),
            'schedule' => config('attendance.default_schedule'),
            'change_reason' => null,
            'source' => 'config',
        ]]);
    }

    public function updateGlobal(Request $request)
    {
        $this->access->assertCanManageSchedules($request->user());
        $data = $this->validatedSchedule($request, false);
        $existing = $this->resolver->global();
        $old = $existing?->only(['timezone', 'schedule', 'change_reason']) ?? [];
        $schedule = \App\Models\AttendanceGlobalSchedule::query()->updateOrCreate(
            ['id' => $existing?->id ?? 1],
            [...$data, 'configured_by' => $request->user()->id]
        );
        $this->resolver->forgetGlobal();
        $this->audit->record(
            $request->user(),
            $old === [] ? 'attendance_global_schedule.created' : 'attendance_global_schedule.updated',
            $schedule,
            $old,
            $schedule->only(['timezone', 'schedule', 'change_reason']),
            $request
        );

        return response()->json(['data' => [...$schedule->toArray(), 'source' => 'global']]);
    }

    public function show(Request $request, User $user)
    {
        $this->access->assertCanManageSchedules($request->user());
        $this->access->assertCanViewUser($request->user(), $user);
        $schedule = AttendanceWorkSchedule::query()->where('user_id', $user->id)->first();
        $global = $schedule ? null : $this->resolver->global();

        return response()->json(['data' => $schedule ? [...$schedule->toArray(), 'source' => 'individual'] : [
            'user_id' => $user->id,
            'timezone' => $global?->timezone ?? config('attendance.timezone'),
            'schedule' => $global?->schedule ?? config('attendance.default_schedule'),
            'holidays' => [],
            'source' => $global ? 'global' : 'config',
        ]]);
    }

    public function update(Request $request, User $user)
    {
        $this->access->assertCanManageSchedules($request->user());
        $this->participants->assertEligible($user);
        $this->access->assertCanViewUser($request->user(), $user);
        $data = $this->validatedSchedule($request, true);
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

    private function validatedSchedule(Request $request, bool $withHolidays): array
    {
        $rules = [
            'timezone' => ['required', 'timezone'],
            'schedule' => ['required', 'array:1,2,3,4,5,6,7'],
            'schedule.*' => ['nullable', 'array:start,end,grace_minutes'],
            'schedule.*.start' => ['required_with:schedule.*', 'date_format:H:i'],
            'schedule.*.end' => ['required_with:schedule.*', 'date_format:H:i'],
            'schedule.*.grace_minutes' => ['nullable', 'integer', 'between:0,240'],
            'change_reason' => ['required', 'string', 'max:500'],
        ];
        if ($withHolidays) {
            $rules['holidays'] = ['nullable', 'array'];
            $rules['holidays.*'] = ['date_format:Y-m-d'];
        }

        return $request->validate($rules);
    }
}
