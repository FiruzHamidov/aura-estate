<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/** Full attendance history follows an explicitly merged employee while that membership lasts. */
final class AttendanceMergedHistory
{
    public function users(User $viewer): Builder
    {
        $users = app(AttendanceAccessService::class)->visibleUsersQuery($viewer);
        if (! $viewer->usesAssignedGroupScope() || ! Schema::hasTable('group_access_audit_logs')) {
            return $users->whereRaw('1 = 0');
        }
        // Filters select the receiving team/current role, not the old ownership of its history.
        if (request()->filled('branch_group_id')) $users->where('users.branch_group_id', request()->input('branch_group_id'));
        if (request()->filled('role')) $users->whereHas('role', fn ($roles) => $roles->where('slug', request()->input('role')));

        return $users->whereExists(function (QueryBuilder $receipt) {
            $receipt->selectRaw('1')->from('group_access_audit_logs as attendance_merge')
                ->whereColumn('attendance_merge.subject_id', 'users.id')
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('attendance_merge.subject_type', 'user')->where('attendance_merge.event', 'user_group_merged'))
                    ->orWhere(fn ($q) => $q->where('attendance_merge.subject_type', 'users')->where('attendance_merge.event', 'group_consolidation_transfer')))
                ->where('attendance_merge.created_at', '<=', now())
                ->whereColumn('attendance_merge.new_values->branch_group_id', 'users.branch_group_id')
                ->whereColumn('attendance_merge.old_values->branch_group_id', '<>', 'attendance_merge.new_values->branch_group_id')
                // Validate the old group too; archived groups still establish merge provenance.
                ->whereExists(fn ($source) => $source->selectRaw('1')->from('branch_groups as attendance_source')
                    ->whereColumn('attendance_source.id', 'attendance_merge.old_values->branch_group_id')
                    ->whereColumn('attendance_source.branch_id', 'users.branch_id'))
                ->whereNotExists(function (QueryBuilder $later) {
                    $later->selectRaw('1')->from('group_access_audit_logs as attendance_later')
                        ->whereColumn('attendance_later.subject_id', 'users.id')
                        ->where('attendance_later.created_at', '<=', now())
                        ->where(fn ($q) => $q->whereColumn('attendance_later.created_at', '>', 'attendance_merge.created_at')
                            ->orWhere(fn ($q) => $q->whereColumn('attendance_later.created_at', 'attendance_merge.created_at')
                                ->whereColumn('attendance_later.id', '>', 'attendance_merge.id')))
                        ->where(fn ($q) => $q->where(fn ($q) => $q->where('attendance_later.subject_type', 'user')
                            ->whereIn('attendance_later.event', ['user_organization_changed', 'employee_group_transferred', 'user_group_merged']))
                            ->orWhere(fn ($q) => $q->where('attendance_later.subject_type', 'users')->where('attendance_later.event', 'group_consolidation_transfer')))
                        ->where(function (QueryBuilder $changed) {
                            foreach (['branch_id', 'branch_group_id'] as $field) {
                                $old = 'attendance_later.old_values->'.$field;
                                $new = 'attendance_later.new_values->'.$field;
                                $changed->orWhereColumn($old, '<>', $new)
                                    ->orWhere(fn ($q) => $q->whereNull($old)->whereNotNull($new))
                                    ->orWhere(fn ($q) => $q->whereNotNull($old)->whereNull($new));
                            }
                        });
                });
        });
    }
}
