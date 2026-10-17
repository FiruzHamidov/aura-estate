<?php

namespace App\Services\Attendance;

use App\Models\{AttendanceDailySummary, BranchGroup, Role, User};
use App\Support\RopGroupAccess;
use Illuminate\Support\Collection;

/** Labels describe already-authorized facts; this service never expands their scope. */
final class AttendancePresentation
{
    public function forUsers(User $viewer, Collection $users, Collection $facts): Collection
    {
        $access = app(AttendanceAccessService::class);
        $currentIds = $access->visibleUsersQuery($viewer)->whereIn('users.id', $users->pluck('id'))->pluck('users.id')->flip();
        $groupScoped = $viewer->usesAssignedGroupScope();
        $emptyDayIds = $groupScoped
            ? app(RopGroupAccess::class)->employees($viewer)->whereIn('users.id', $users->pluck('id'))->pluck('users.id')->flip()
            : $currentIds;
        $groups = BranchGroup::withoutGlobalScope('active')->with('branch')->whereIn('id', $facts->pluck('branch_group_id')->filter()->unique())->get()->keyBy('id');
        $roles = Role::query()->whereIn('slug', $facts->pluck('role_slug')->filter()->unique())->get()->keyBy('slug');
        $byUser = $facts->groupBy('user_id');
        $permissions = $access->permissions($viewer);

        return $users->mapWithKeys(function (User $user) use ($groupScoped, $currentIds, $emptyDayIds, $groups, $roles, $byUser, $permissions) {
            $current = $currentIds->has($user->id);
            $manageable = $current && $user->status === User::STATUS_ACTIVE;
            $contexts = $byUser->get($user->id, collect())
                ->groupBy(fn ($fact) => $fact->branch_group_id.'|'.$fact->role_slug)
                ->map(function (Collection $records) use ($groups, $roles) {
                    $fact = $records->first();
                    $group = $groups->get($fact->branch_group_id);
                    return [
                        'branch_id' => $group?->branch_id,
                        'branch_name' => $group?->branch?->name,
                        'branch_group_id' => $fact->branch_group_id,
                        'branch_group_name' => $group?->name,
                        'role' => $fact->role_slug,
                        'role_name' => $roles->get($fact->role_slug)?->name,
                        // These are dates of visible records, not an inferred employment interval.
                        'dates' => $records->map(fn ($record) => $record instanceof AttendanceDailySummary
                            ? $record->work_date->toDateString()
                            : $record->occurred_at->copy()->setTimezone(config('attendance.timezone', 'Asia/Dushanbe'))->toDateString())
                            ->unique()->sort()->values()->all(),
                    ];
                })->sortBy(fn ($context) => ($context['dates'][0] ?? '').'|'.$context['branch_group_id'].'|'.$context['role'])->values()->all();

            return [$user->id => [
                'employee_scope' => $groupScoped && ! $current ? 'historical' : 'current',
                'history_contexts' => $contexts,
                'actions' => [
                    'can_manage_schedules' => $manageable && $permissions['can_manage_schedules'],
                    'can_manage_leaves' => $manageable && $permissions['can_manage_leaves'],
                    'can_manage_duties' => $manageable && $permissions['can_manage_duties'],
                    'can_open_empty_day' => $emptyDayIds->has($user->id),
                ],
            ]];
        });
    }

    public function dayState(User $viewer, array $presentation, string $date, ?AttendanceDailySummary $summary, bool $hasFacts): array
    {
        $groupScoped = $viewer->usesAssignedGroupScope();
        $historical = $presentation['employee_scope'] === 'historical';
        $scheduleKnown = ! $groupScoped || is_array($summary?->schedule_snapshot)
            || (! $historical && $date >= now(config('attendance.timezone', 'Asia/Dushanbe'))->toDateString());

        return [
            'availability' => $hasFacts ? 'available' : ($historical ? 'restricted' : ($groupScoped ? 'no_data' : 'available')),
            'schedule_known' => $scheduleKnown,
            'can_open' => $hasFacts || (! $historical && $presentation['actions']['can_open_empty_day']),
        ];
    }

    /** Spreadsheet labels can also describe a particular day without using today's assignment. */
    public function labels(User $user, array $presentation, ?string $date = null, bool $preferHistory = false): array
    {
        $historical = $presentation['employee_scope'] === 'historical';
        $contexts = collect($presentation['history_contexts'])->when($date, fn ($rows) => $rows->filter(fn ($context) => in_array($date, $context['dates'], true)));
        $currentDateWithoutFacts = $date !== null && $date >= now(config('attendance.timezone', 'Asia/Dushanbe'))->toDateString() && $contexts->isEmpty();
        if (! $historical && (! $preferHistory || $currentDateWithoutFacts)) {
            return [$user->role?->name ?? 'Роль не указана', $user->branch?->name ?? 'Без филиала', $user->branchGroup?->name ?? 'Без группы'];
        }
        return [
            $contexts->map(fn ($context) => $context['role_name'] ?? $context['role'] ?? 'Роль не сохранена')->unique()->implode(' / ') ?: 'Роль не сохранена',
            $contexts->pluck('branch_name')->filter()->unique()->implode(' / ') ?: 'Исторический филиал не указан',
            $contexts->pluck('branch_group_name')->filter()->unique()->implode(' / ') ?: 'Историческая группа не указана',
        ];
    }
}
