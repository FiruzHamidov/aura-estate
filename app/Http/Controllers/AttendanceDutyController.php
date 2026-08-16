<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDuty;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendanceParticipantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceDutyController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
        private readonly AttendanceParticipantService $participants,
    ) {}

    public function index(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);

        return response()->json(['data' => AttendanceDuty::query()
            ->where('user_id', $user->id)
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->limit(100)
            ->get()]);
    }

    public function store(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);
        $data = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $duty = DB::transaction(function () use ($request, $user, $data) {
            $overlaps = AttendanceDuty::query()->where('user_id', $user->id)
                ->whereDate('date_from', '<=', $data['date_to'])
                ->whereDate('date_to', '>=', $data['date_from'])
                ->lockForUpdate()->exists();
            if ($overlaps) {
                throw ValidationException::withMessages(['date_from' => ['Этот период пересекается с уже назначенным дежурством.']]);
            }
            $duty = AttendanceDuty::query()->create([
                ...$data,
                'user_id' => $user->id,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record($request->user(), 'attendance_duty.created', $duty, [], $duty->only(['user_id', 'date_from', 'date_to', 'note']), $request);

            return $duty;
        });

        return response()->json(['data' => $duty], 201);
    }

    public function destroy(Request $request, User $user, AttendanceDuty $duty)
    {
        $this->authorizeTarget($request, $user);
        abort_unless((int) $duty->user_id === (int) $user->id, 404);
        $old = $duty->only(['user_id', 'date_from', 'date_to', 'note']);
        $this->audit->record($request->user(), 'attendance_duty.deleted', $duty, $old, [], $request);
        $duty->delete();

        return response()->noContent();
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        $this->access->assertCanManageDuties($request->user());
        $this->participants->assertEligible($user);
        $this->access->assertCanViewUser($request->user(), $user);
    }
}
