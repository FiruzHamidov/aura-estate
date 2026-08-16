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
        $this->assertCanViewModule($user);
        if (! in_array($this->role($user), config('attendance.administrator_roles', []), true)) {
            $this->deny('ATTENDANCE_ADMIN_FORBIDDEN', 'Нет права управлять терминалами посещаемости.');
        }
    }

    public function assertCanViewTable(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.table_roles', []), 'ATTENDANCE_TABLE_FORBIDDEN', 'Нет права просматривать общую таблицу посещаемости.');
    }

    public function assertCanManageMappings(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.mapping_roles', []), 'ATTENDANCE_MAPPING_FORBIDDEN', 'Нет права управлять сопоставлениями терминала.');
    }

    public function assertCanComment(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.comment_roles', []), 'ATTENDANCE_COMMENT_FORBIDDEN', 'Нет права изменять HR-комментарий.');
    }

    public function assertCanManageSchedules(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.schedule_roles', []), 'ATTENDANCE_SCHEDULE_FORBIDDEN', 'Нет права изменять рабочий график.');
    }

    public function assertCanManageLeaves(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.leave_roles', []), 'ATTENDANCE_LEAVE_FORBIDDEN', 'Нет права назначать отпуска.');
    }

    public function assertCanManageDuties(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.duty_roles', []), 'ATTENDANCE_DUTY_FORBIDDEN', 'Нет права назначать дежурных.');
    }

    public function assertCanManageHolidays(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.holiday_roles', []), 'ATTENDANCE_HOLIDAY_FORBIDDEN', 'Нет права управлять праздничными днями.');
    }

    public function assertCanViewDevices(User $user): void
    {
        $this->assertRoleIn($user, config('attendance.device_viewer_roles', []), 'ATTENDANCE_DEVICE_FORBIDDEN', 'Нет права просматривать терминалы посещаемости.');
    }

    public function permissions(User $user): array
    {
        $role = $this->role($user);

        return [
            'can_view_attendance_table' => in_array($role, config('attendance.table_roles', []), true),
            'can_view_all_branches' => in_array($role, ['hr', 'admin', 'superadmin', 'owner'], true),
            'can_comment_late_day' => in_array($role, config('attendance.comment_roles', []), true),
            'can_manage_mappings' => in_array($role, config('attendance.mapping_roles', []), true),
            'can_manage_schedules' => in_array($role, config('attendance.schedule_roles', []), true),
            'can_manage_leaves' => in_array($role, config('attendance.leave_roles', []), true),
            'can_manage_duties' => in_array($role, config('attendance.duty_roles', []), true),
            'can_manage_holidays' => in_array($role, config('attendance.holiday_roles', []), true),
            'can_view_devices' => in_array($role, config('attendance.device_viewer_roles', []), true),
            'can_manage_devices' => in_array($role, config('attendance.administrator_roles', []), true),
        ];
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

    private function assertRoleIn(User $user, array $roles, string $code, string $message): void
    {
        $this->assertCanViewModule($user);
        if (! in_array($this->role($user), $roles, true)) {
            $this->deny($code, $message);
        }
    }
}
