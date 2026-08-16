<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDailySummary;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRawEvent;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceIngestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttendanceReportController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceIngestionService $ingestion,
    ) {}

    public function events(Request $request)
    {
        $validated = $this->filters($request);
        $query = AttendanceEvent::query()->with(['user.role', 'device']);
        $this->scopeEvents($query, $request->user());
        $this->applyEventFilters($query, $validated);

        return response()->json($query->orderByDesc('occurred_at')->paginate($validated['per_page'] ?? 50));
    }

    public function daily(Request $request)
    {
        $validated = $this->filters($request);
        $visibleIds = $this->access->visibleUsersQuery($request->user())->pluck('users.id');
        $query = AttendanceDailySummary::query()->with(['user.role', 'user.branch', 'user.branchGroup'])
            ->whereIn('user_id', $visibleIds);
        $this->applySummaryFilters($query, $validated);

        return response()->json($query->orderByDesc('work_date')->orderBy('user_id')->paginate($validated['per_page'] ?? 50));
    }

    public function me(Request $request)
    {
        $request->merge(['user_id' => $request->user()->id]);

        return $this->daily($request);
    }

    public function userDaily(Request $request, User $user)
    {
        $this->access->assertCanViewUser($request->user(), $user);
        $request->merge(['user_id' => $user->id]);

        return $this->daily($request);
    }

    public function unmapped(Request $request)
    {
        $this->access->assertCanAdminister($request->user());
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = AttendanceRawEvent::query()->with('device')->where('processing_status', 'unmapped');
        if (isset($validated['device_id'])) {
            $query->where('device_id', $validated['device_id']);
        }

        return response()->json($query->orderByDesc('occurred_at_utc')->paginate($validated['per_page'] ?? 50));
    }

    public function reprocess(Request $request)
    {
        $this->access->assertCanAdminister($request->user());
        $data = $request->validate(['raw_event_id' => ['required', 'integer', 'exists:attendance_raw_events,id']]);
        $raw = AttendanceRawEvent::query()->findOrFail($data['raw_event_id']);

        return response()->json(['data' => ['result' => $this->ingestion->reprocess($raw)]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $this->filters($request);
        $visibleIds = $this->access->visibleUsersQuery($request->user())->pluck('users.id');
        $query = AttendanceDailySummary::query()->with('user')->whereIn('user_id', $visibleIds);
        $this->applySummaryFilters($query, $validated);

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['date', 'user_id', 'employee', 'first_in_at', 'last_out_at', 'worked_minutes', 'late_minutes', 'status']);
            $query->orderBy('work_date')->orderBy('user_id')->chunk(500, function ($rows) use ($stream) {
                foreach ($rows as $row) {
                    fputcsv($stream, [
                        $row->work_date?->toDateString(), $row->user_id, $this->spreadsheetSafe($row->user?->name),
                        $row->first_in_at?->toISOString(), $row->last_out_at?->toISOString(),
                        $row->worked_minutes, $row->late_minutes, $row->status,
                    ]);
                }
            });
            fclose($stream);
        }, 'attendance.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function spreadsheetSafe(?string $value): ?string
    {
        return $value !== null && preg_match('/^\s*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function filters(Request $request): array
    {
        $this->access->assertCanViewModule($request->user());

        return $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['nullable', 'integer', 'exists:branch_groups,id'],
            'device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'status' => ['nullable', 'in:present,late,absent,incomplete'],
            'late' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
    }

    private function scopeEvents(Builder $query, User $viewer): void
    {
        $query->whereIn('user_id', $this->access->visibleUsersQuery($viewer)->pluck('users.id'));
    }

    private function applyEventFilters(Builder $query, array $filters): void
    {
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', \Carbon\CarbonImmutable::parse($filters['date_from'], config('attendance.timezone'))->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<=', \Carbon\CarbonImmutable::parse($filters['date_to'], config('attendance.timezone'))->endOfDay()->utc());
        }
        foreach (['user_id', 'branch_id', 'branch_group_id', 'device_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
    }

    private function applySummaryFilters(Builder $query, array $filters): void
    {
        if (isset($filters['date_from'])) {
            $query->whereDate('work_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('work_date', '<=', $filters['date_to']);
        }
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['branch_id'])) {
            $query->whereHas('user', fn (Builder $users) => $users->where('branch_id', $filters['branch_id']));
        }
        if (isset($filters['branch_group_id'])) {
            $query->whereHas('user', fn (Builder $users) => $users->where('branch_group_id', $filters['branch_group_id']));
        }
        if (isset($filters['device_id'])) {
            $query->whereJsonContains('device_ids', (int) $filters['device_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (array_key_exists('late', $filters)) {
            $filters['late'] ? $query->where('late_minutes', '>', 0) : $query->where('late_minutes', 0);
        }
    }
}
