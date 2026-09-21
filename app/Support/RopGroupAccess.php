<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Explicit data boundary, independent of role abilities and HTTP transport. */
final class RopGroupAccess
{
    public function applies(?User $actor): bool
    {
        return $actor?->hasRole('rop') ?? false;
    }

    public function groupsQuery(User $actor): QueryBuilder
    {
        return DB::table('rop_branch_groups as rbg')
            ->join('branch_groups as rg', 'rg.id', '=', 'rbg.branch_group_id')
            ->join('users as ru', 'ru.id', '=', 'rbg.rop_id')
            ->join('roles as rr', 'rr.id', '=', 'ru.role_id')
            ->where('ru.id', $actor->id)
            ->where('ru.status', User::STATUS_ACTIVE)
            ->where('rr.slug', 'rop')
            ->whereColumn('rg.branch_id', 'ru.branch_id')
            ->select('rg.id');
    }

    public function groupIds(User $actor): array
    {
        return $this->groupsQuery($actor)->orderBy('rg.id')->pluck('rg.id')->map(fn ($id) => (int) $id)->all();
    }

    public function describe(User $actor): array
    {
        $isRop = $this->applies($actor);
        $ids = $isRop ? $this->groupIds($actor) : [];
        $code = $isRop && $ids === []
            ? ($actor->branch_id ? 'ROP_GROUPS_NOT_ASSIGNED' : 'ROP_BRANCH_NOT_ASSIGNED') : null;

        return [
            'scope_type' => $isRop ? 'groups' : 'role',
            'branch_id' => $actor->branch_id,
            'branch_group_ids' => $ids,
            'version' => (int) $actor->access_scope_version,
            'notice' => $code ? ['code' => $code, 'message' => config('moderation-messages.'.$code)] : null,
        ];
    }

    public function scope(Builder|QueryBuilder $query, User $actor, string $groupColumn, ?string $branchColumn = null): Builder|QueryBuilder
    {
        $query->whereIn($groupColumn, $this->groupsQuery($actor));
        if ($branchColumn !== null) {
            $query->where($branchColumn, $actor->branch_id ?? 0);
        }

        return $query;
    }

    public function employees(User $actor): Builder
    {
        return $this->scope(User::query(), $actor, 'users.branch_group_id', 'users.branch_id')
            ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop']));
    }

    public function allowsGroup(User $actor, ?int $groupId): bool
    {
        return $groupId !== null && $this->groupsQuery($actor)->where('rg.id', $groupId)->exists();
    }

    public function allows(User $actor, Model $record): bool
    {
        if (! $this->allowsGroup($actor, ($record->getAttributes()['branch_group_id'] ?? null))) {
            return false;
        }

        return ! array_key_exists('branch_id', $record->getAttributes())
            || ($record->branch_id !== null && (int) $record->branch_id === (int) $actor->branch_id);
    }

    public function ensureVisible(User $actor, Model $record): void
    {
        if ($this->applies($actor)) {
            abort_unless($this->allows($actor, $record), 404, 'ROP_RECORD_NOT_ACCESSIBLE');
        }
    }

    public function ensureGroup(User $actor, ?int $groupId): void
    {
        abort_unless($this->allowsGroup($actor, $groupId), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
    }

    public function hasVisibleTaskHistory(User $actor, int $assigneeId): bool
    {
        return $this->applies($actor) && $this->scope(\App\Models\CrmTask::query(), $actor, 'crm_tasks.branch_group_id')
            ->where('assignee_id', $assigneeId)->exists();
    }

    public function hasVisibleDailyReportHistory(User $actor, int $employeeId): bool
    {
        return $this->applies($actor) && $this->scope(\App\Models\DailyReport::query(), $actor, 'daily_reports.branch_group_id')
            ->where('user_id', $employeeId)->exists();
    }

    public function ensureEmployee(User $actor, int $id, ?int $groupId = null, bool $assignable = false): void
    {
        $query = $this->employees($actor)->whereKey($id);
        if ($groupId !== null) {
            $query->where('branch_group_id', $groupId);
        }
        if ($assignable) {
            $query->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at');
        }
        $exists = DB::transactionLevel() > 0 ? $query->lockForUpdate()->first(['users.id']) !== null : $query->exists();
        abort_unless($exists, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
    }

    public function creationGroup(User $actor, ?int $requested): int
    {
        if ($requested !== null) {
            $this->ensureGroup($actor, $requested);

            return $requested;
        }
        $groups = $this->groupIds($actor);
        abort_unless(count($groups) === 1, 422, 'BRANCH_GROUP_REQUIRED');

        return $groups[0];
    }
}
