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
        if (! $user->hasPermission('attendance.devices.manage')) {
            $this->deny('ATTENDANCE_ADMIN_FORBIDDEN', 'Нет права управлять терминалами посещаемости.');
        }
    }

    public function assertCanViewTable(User $user): void
    {
        $this->assertPermission($user, 'attendance.table.view', 'ATTENDANCE_TABLE_FORBIDDEN', 'Нет права просматривать общую таблицу посещаемости.');
    }

    public function assertCanManageMappings(User $user): void
    {
        $this->assertPermission($user, 'attendance.mappings.manage', 'ATTENDANCE_MAPPING_FORBIDDEN', 'Нет права управлять сопоставлениями терминала.');
    }

    public function mappingUsersQuery(User $viewer): Builder
    {
        $query = $this->participants->query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q));
        if ($viewer->usesAssignedGroupScope()) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $viewer, 'users.branch_group_id', 'users.branch_id');
            $query->whereHas('role', fn ($roles) => $roles->withPermission('attendance.subject.mapping_assigned_groups'));
        }
        return $query;
    }

    public function mappingDevicesQuery(User $viewer): Builder
    {
        $query = \App\Models\AttendanceDevice::query();
        if ($viewer->usesAssignedGroupScope()) {
            $query->where('branch_id', $viewer->branch_id ?? 0)->where(function ($devices) use ($viewer) {
                $devices->whereNull('branch_group_id')->orWhereIn('branch_group_id', app(\App\Support\RopGroupAccess::class)->groupsQuery($viewer));
            });
        }
        return $query;
    }

    public function assertCanViewPresence(User $user): void
    {
        $this->assertPermission($user, 'attendance.presence.view', 'ATTENDANCE_PRESENCE_FORBIDDEN', 'Нет права просматривать местонахождение сотрудников.');
    }

    public function assertCanViewHrReport(User $user): void
    {
        $this->assertPermission($user, 'attendance.hr_report.view', 'ATTENDANCE_HR_REPORT_FORBIDDEN', 'Нет права просматривать отчёт HR.');
    }

    public function assertCanComment(User $user): void
    {
        $this->assertPermission($user, 'attendance.comments.manage', 'ATTENDANCE_COMMENT_FORBIDDEN', 'Нет права изменять HR-комментарий.');
    }

    public function assertCanManageSchedules(User $user): void
    {
        $this->assertPermission($user, 'attendance.schedules.manage', 'ATTENDANCE_SCHEDULE_FORBIDDEN', 'Нет права изменять рабочий график.');
    }

    public function assertCanManageLeaves(User $user): void
    {
        $this->assertPermission($user, 'attendance.leaves.manage', 'ATTENDANCE_LEAVE_FORBIDDEN', 'Нет права назначать отпуска.');
    }

    public function assertCanManageDuties(User $user): void
    {
        $this->assertPermission($user, 'attendance.duties.manage', 'ATTENDANCE_DUTY_FORBIDDEN', 'Нет права назначать дежурных.');
    }

    public function assertCanManageHolidays(User $user): void
    {
        $this->assertPermission($user, 'attendance.holidays.manage', 'ATTENDANCE_HOLIDAY_FORBIDDEN', 'Нет права управлять праздничными днями.');
    }

    public function assertCanViewDevices(User $user): void
    {
        $this->assertPermission($user, 'attendance.devices.view', 'ATTENDANCE_DEVICE_FORBIDDEN', 'Нет права просматривать терминалы посещаемости.');
    }

    public function permissions(User $user): array
    {
        $role = $this->role($user);

        return [
            'can_view_presence' => $user->hasPermission('attendance.presence.view'),
            'can_view_hr_report' => $user->hasPermission('attendance.hr_report.view'),
            'can_view_attendance_table' => $user->hasPermission('attendance.table.view'),
            'can_view_all_branches' => !$user->usesAssignedGroupScope() && $user->hasPermission('attendance.all_branches.view'),
            'can_comment_late_day' => $user->hasPermission('attendance.comments.manage'),
            'can_manage_mappings' => $user->hasPermission('attendance.mappings.manage'),
            'can_manage_schedules' => $user->hasPermission('attendance.schedules.manage'),
            'can_manage_leaves' => $user->hasPermission('attendance.leaves.manage'),
            'can_manage_duties' => $user->hasPermission('attendance.duties.manage'),
            'can_manage_holidays' => $user->hasPermission('attendance.holidays.manage'),
            'can_view_devices' => $user->hasPermission('attendance.devices.view'),
            'can_manage_devices' => $user->hasPermission('attendance.devices.manage'),
        ];
    }

    /** An explicit empty list grants nothing; legacy accounts retain their primary branch. */
    public function securityBranchIds(User $viewer): array
    {
        $ids = $viewer->security_attendance_branch_ids;
        return array_values(array_unique(array_map('intval', $ids ?? ($viewer->branch_id ? [$viewer->branch_id] : []))));
    }

    public function visibleUsersQuery(User $viewer): Builder
    {
        $this->assertCanViewModule($viewer);
        // Apply the same employee scope to the table, totals, reports and day details.
        $query = $this->participants->query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->whereHas('role', fn (Builder $roles) => $roles->withPermission('attendance.subject.exclude_table', false))
            ->with(['role', 'branch', 'branchGroup']);

        if ($viewer->usesAssignedGroupScope()) {
            return app(\App\Support\RopGroupAccess::class)->scope($query, $viewer, 'users.branch_group_id', 'users.branch_id')
                ->whereHas('role', fn (Builder $roles) => $roles->withPermission('attendance.subject.assigned_groups'));
        }

        if ($viewer->hasPermission('attendance.all_branches.view')) {
            return $query;
        }

        if ($viewer->hasPermission('attendance.scope.assigned_branches')) {
            return $query->whereIn('users.branch_id', $this->securityBranchIds($viewer));
        }
        if ($viewer->hasPermission('attendance.scope.branch')) {
            return $viewer->branch_id === null
                ? $query->whereKey($viewer->id)
                : $query->where('users.branch_id', $viewer->branch_id);
        }
        if ($viewer->hasPermission('attendance.scope.group')) {
            $roleIds = \App\Models\Role::query()->get()
                ->filter(fn ($role) => $role->hasPermission('attendance.subject.group'))->modelKeys();
            return $query->where(function (Builder $scope) use ($viewer, $roleIds) {
                $scope->whereKey($viewer->id);
                if ($viewer->branch_group_id !== null) {
                    $scope->orWhere(fn (Builder $employees) => $employees
                        ->where('users.branch_group_id', $viewer->branch_group_id)
                        ->whereIn('users.role_id', $roleIds));
                }
            });
        }
        return $query->whereKey($viewer->id);
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

    private function assertPermission(User $user, string $permission, string $code, string $message): void
    {
        $this->assertCanViewModule($user);
        if (! $user->hasPermission($permission)) {
            $this->deny($code, $message);
        }
    }
}
