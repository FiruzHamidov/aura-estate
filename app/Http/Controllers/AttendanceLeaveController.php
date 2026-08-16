<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLeave;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendanceParticipantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceLeaveController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
        private readonly AttendanceParticipantService $participants,
    ) {}

    public function index(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);

        return response()->json(['data' => AttendanceLeave::query()
            ->where('user_id', $user->id)
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->limit(100)
            ->get()]);
    }

    public function store(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);
        $data = $this->validated($request);

        $leave = DB::transaction(function () use ($request, $user, $data) {
            $this->assertNoOverlap($user, $data['date_from'], $data['date_to']);
            $leave = AttendanceLeave::query()->create([
                ...$data,
                'user_id' => $user->id,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record($request->user(), 'attendance_leave.created', $leave, [], $leave->only(['user_id', 'date_from', 'date_to', 'note']), $request);

            return $leave;
        });

        return response()->json(['data' => $leave], 201);
    }

    public function destroy(Request $request, User $user, AttendanceLeave $leave)
    {
        $this->authorizeTarget($request, $user);
        abort_unless((int) $leave->user_id === (int) $user->id, 404);
        $old = $leave->only(['user_id', 'date_from', 'date_to', 'note']);
        $this->audit->record($request->user(), 'attendance_leave.deleted', $leave, $old, [], $request);
        $leave->delete();

        return response()->noContent();
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        $this->access->assertCanManageLeaves($request->user());
        $this->participants->assertEligible($user);
        $this->access->assertCanViewUser($request->user(), $user);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function assertNoOverlap(User $user, string $from, string $to): void
    {
        $overlaps = AttendanceLeave::query()->where('user_id', $user->id)
            ->whereDate('date_from', '<=', $to)
            ->whereDate('date_to', '>=', $from)
            ->lockForUpdate()
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['date_from' => ['Этот период пересекается с уже назначенным отпуском.']]);
        }
    }
}
