<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AttendanceAccessService
{
    public function __construct(private readonly AttendanceParticipantService $participants) {}

    public function role(User $user): ?string
    {
        $user->loadMissing('role');

        return $user->role?->slug;
    }

    public function assertCanViewModule(User $user): void
    {
        if (! $this->participants->isEligible($user)) {
            $this->deny('ATTENDANCE_INACTIVE_USER', 'Учёт посещаемости доступен только активным пользователям.');
        }
    }

    public function assertCanAdminister(User $user): void
    {
        if (! in_array($this->role($user), config('attendance.administrator_roles', []), true)) {
            $this->deny('ATTENDANCE_ADMIN_FORBIDDEN', 'Нет права управлять терминалами посещаемости.');
        }
    }

    public function visibleUsersQuery(User $viewer): Builder
    {
        $this->assertCanViewModule($viewer);
        $query = $this->participants->query()->with(['role', 'branch', 'branchGroup']);

        return match ($this->role($viewer)) {
            'agent', 'intern' => $query->whereKey($viewer->id),
            'mop' => $query->where(function (Builder $scope) use ($viewer) {
                $scope->whereKey($viewer->id);
                if ($viewer->branch_group_id !== null) {
                    $scope->orWhere(function (Builder $agents) use ($viewer) {
                        $agents->where('branch_group_id', $viewer->branch_group_id)
                            ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'intern']));
                    });
                }
            }),
            'rop', 'branch_director' => $viewer->branch_id === null
                ? $query->whereKey($viewer->id)
                : $query->where('branch_id', $viewer->branch_id),
            'hr', 'admin', 'superadmin', 'owner' => $query,
            default => $query->whereKey($viewer->id),
        };
    }

    public function assertCanViewUser(User $viewer, User $target): void
    {
        if (! $this->visibleUsersQuery($viewer)->whereKey($target->id)->exists()) {
            $this->deny('ATTENDANCE_FORBIDDEN_SCOPE', 'Нет доступа к посещениям сотрудника.');
        }
    }

    public function deny(string $code, string $message, int $status = 403): never
    {
        abort(response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) [],
            'trace_id' => request()->attributes->get('trace_id'),
        ], $status));
    }
}
