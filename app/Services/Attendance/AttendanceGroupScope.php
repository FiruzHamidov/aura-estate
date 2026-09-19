<?php

namespace App\Services\Attendance;

use App\Models\{AttendanceDailySummary, AttendanceEvent, User};
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Builder;

/** Group of the fact, independent of the employee's current organizational assignment. */
final class AttendanceGroupScope
{
    public function __construct(private readonly RopGroupAccess $groups) {}

    public function apply(Builder $query, User $viewer): Builder
    {
        if ($viewer->hasRole('security')) {
            $access = app(AttendanceAccessService::class);
            $table = $query->getModel()->getTable();
            $query->whereIn($table.'.user_id', $access->visibleUsersQuery($viewer)->select('users.id'));
            if ($table === 'attendance_events') {
                $query->whereIn($table.'.branch_id', $access->securityBranchIds($viewer));
            }
            return $query;
        }
        if (! $viewer->hasRole('rop')) return $query;
        $table = $query->getModel()->getTable();
        if (in_array($table, ['attendance_events', 'attendance_daily_summaries'], true)) {
            if (\Schema::hasColumn($table, 'role_slug')) {
                $query->whereIn($table.'.role_slug', ['agent', 'mop']);
                if (request()->filled('role')) $query->where($table.'.role_slug', request()->input('role'));
            } else {
                $query->whereHas('user.role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop']));
                if (request()->filled('role')) $query->whereHas('user.role', fn (Builder $roles) => $roles->where('slug', request()->input('role')));
            }
        }
        if ($table !== 'attendance_daily_comments' && request()->filled('branch_group_id')) {
            $query->where($table.'.branch_group_id', request()->input('branch_group_id'));
        }
        if ($table === 'attendance_daily_comments') {
            return $query->whereExists($this->apply(AttendanceDailySummary::query(), $viewer)->selectRaw('1')
                ->whereColumn('attendance_daily_summaries.user_id', 'attendance_daily_comments.user_id')
                ->whereColumn('attendance_daily_summaries.work_date', 'attendance_daily_comments.work_date'));
        }
        return $this->groups->scope($query, $viewer, $table.'.branch_group_id', $table === 'attendance_events' ? $table.'.branch_id' : null);
    }

    public function users(User $viewer, ?string $from = null, ?string $to = null): Builder
    {
        $current = app(AttendanceAccessService::class)->visibleUsersQuery($viewer);
        if (! $viewer->hasRole('rop')) return $current;
        $summaries = $this->apply(AttendanceDailySummary::query(), $viewer)
            ->when($from, fn ($q) => $q->whereDate('work_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to));
        return User::query()->with(['role', 'branch', 'branchGroup'])->where(function (Builder $users) use ($current, $summaries) {
            $users->whereIn('users.id', $current->select('users.id'))->orWhereIn('users.id', $summaries->select('user_id'));
        });
    }

    public function assertHistoryUser(User $viewer, User $target): void
    {
        if (! $viewer->hasRole('rop')) {
            app(AttendanceAccessService::class)->assertCanViewUser($viewer, $target);
            return;
        }
        app(AttendanceAccessService::class)->assertCanViewModule($viewer);
        abort_unless($this->users($viewer)->whereKey($target->id)->exists()
            || $this->apply(AttendanceEvent::query()->where('user_id', $target->id), $viewer)->exists(), 404, 'NOT_FOUND');
    }

    public function filterUsers(Builder $query, User $viewer, array $filters, ?string $from = null, ?string $to = null): void
    {
        if (! $viewer->hasRole('rop') || (! isset($filters['branch_group_id']) && ! isset($filters['role']))) return;
        $current = $this->groups->employees($viewer);
        if (isset($filters['branch_group_id'])) $current->where('users.branch_group_id', $filters['branch_group_id']);
        if (isset($filters['role'])) $current->whereHas('role', fn ($roles) => $roles->where('slug', $filters['role']));
        $history = $this->apply(AttendanceDailySummary::query(), $viewer)
            ->when($from, fn ($facts) => $facts->whereDate('work_date', '>=', $from))
            ->when($to, fn ($facts) => $facts->whereDate('work_date', '<=', $to));
        if (isset($filters['branch_group_id'])) $history->where('branch_group_id', $filters['branch_group_id']);
        if (isset($filters['role'])) {
            if (\Schema::hasColumn('attendance_daily_summaries', 'role_slug')) {
                $history->where('role_slug', $filters['role']);
            } else {
                $history->whereHas('user.role', fn ($roles) => $roles->where('slug', $filters['role']));
            }
        }
        $query->where(fn ($users) => $users->whereIn('users.id', $current->select('users.id'))->orWhereIn('users.id', $history->select('user_id')));
    }
}
