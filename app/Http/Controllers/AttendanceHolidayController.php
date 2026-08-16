<?php

namespace App\Http\Controllers;

use App\Models\AttendanceHoliday;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendanceHolidayCalendar;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AttendanceHolidayController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
        private readonly AttendanceHolidayCalendar $calendar,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertCanManageHolidays($request->user());
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($data['year'] ?? now(config('attendance.timezone'))->year);

        return response()->json(['data' => AttendanceHoliday::query()
            ->whereYear('holiday_date', $year)->orderBy('holiday_date')->get()]);
    }

    public function store(Request $request)
    {
        $this->access->assertCanManageHolidays($request->user());
        $data = $this->validated($request, null);
        $holiday = AttendanceHoliday::query()->create([
            ...$data,
            'kind' => 'custom',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->calendar->forget($data['holiday_date']);
        $this->audit->record($request->user(), 'attendance_holiday.created', $holiday, [], $holiday->only(['holiday_date', 'name', 'kind', 'note']), $request);

        return response()->json(['data' => $holiday], 201);
    }

    public function update(Request $request, AttendanceHoliday $holiday)
    {
        $this->access->assertCanManageHolidays($request->user());
        $old = $holiday->only(['holiday_date', 'name', 'kind', 'note']);
        $oldDate = $holiday->holiday_date->toDateString();
        $data = $this->validated($request, $holiday);
        $holiday->fill([...$data, 'updated_by' => $request->user()->id])->save();
        $this->calendar->forget($oldDate);
        $this->calendar->forget($holiday->holiday_date->toDateString());
        $this->audit->record($request->user(), 'attendance_holiday.updated', $holiday, $old, $holiday->only(['holiday_date', 'name', 'kind', 'note']), $request);

        return response()->json(['data' => $holiday->fresh()]);
    }

    public function destroy(Request $request, AttendanceHoliday $holiday)
    {
        $this->access->assertCanManageHolidays($request->user());
        $old = $holiday->only(['holiday_date', 'name', 'kind', 'note']);
        $date = $holiday->holiday_date->toDateString();
        $this->audit->record($request->user(), 'attendance_holiday.deleted', $holiday, $old, [], $request);
        $holiday->delete();
        $this->calendar->forget($date);

        return response()->noContent();
    }

    private function validated(Request $request, ?AttendanceHoliday $holiday): array
    {
        return $request->validate([
            'holiday_date' => ['required', 'date_format:Y-m-d', Rule::unique('attendance_holidays', 'holiday_date')->ignore($holiday?->id)],
            'name' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
