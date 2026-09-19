<?php

namespace App\Services\GroupAccess;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UserOrganizationService
{
    public function __construct(private readonly GroupAccessAudit $audit) {}

    public function update(User $actor, User $subject, array $data): User
    {
        return DB::transaction(function () use ($actor, $subject, $data) {
            $users = User::query()->whereIn('id', [$actor->id, $subject->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subject = $users->get($subject->id);
            $actor = $users->get($actor->id);
            abort_unless($subject && $actor, 404, 'NOT_FOUND');
            $before = $subject->only(['role_id', 'branch_id', 'branch_group_id']);
            $previousRole = $subject->role?->slug;
            $subject->fill($data);
            $nextRole = $subject->isDirty('role_id')
                ? \App\Models\Role::whereKey($subject->role_id)->value('slug') : $previousRole;
            if (in_array($previousRole, ['agent', 'mop'], true)
                && ((! in_array($nextRole, ['agent', 'mop'], true))
                    || ($subject->isDirty('status') && $subject->status !== User::STATUS_ACTIVE))) {
                abort_if($this->hasActiveRecords($subject->id, lock: true, classifiedOnly: true),
                    409, 'EMPLOYEE_TRANSFER_REQUIRED');
            }
            $changed = $subject->isDirty(['role_id', 'branch_id', 'branch_group_id']);
            if ($changed) {
                abort_if($actor->hasRole('rop'), 403, 'FORBIDDEN_ACTION');
                if ($subject->branch_group_id) {
                    $group = \App\Models\BranchGroup::query()->lockForUpdate()->find($subject->branch_group_id);
                    abort_unless($group, 422, 'INVALID_GROUP');
                    abort_unless($subject->branch_id && (int) $group->branch_id === (int) $subject->branch_id,
                        422, 'GROUP_BRANCH_MISMATCH');
                }
                if ($subject->isDirty(['branch_id', 'branch_group_id'])) {
                    abort_if($this->hasActiveRecords($subject->id, lock: true), 409, 'EMPLOYEE_TRANSFER_REQUIRED');
                }
                if ($subject->isDirty(['role_id', 'branch_id']) && Schema::hasTable('rop_branch_groups')) {
                    $groups = $subject->supervisedGroups()->pluck('branch_groups.id')->all();
                    $subject->supervisedGroups()->detach();
                    if ($groups !== []) {
                        $this->audit->record($actor, 'rop_groups_revoked', 'user', $subject->id, ['group_ids' => $groups], ['group_ids' => []], 'Role or branch changed');
                    }
                }
                if (Schema::hasColumn('users', 'access_scope_version')) {
                    $subject->access_scope_version = (int) $subject->access_scope_version + 1;
                }
            }
            $subject->save();
            if ($changed && Schema::hasTable('group_access_audit_logs')) {
                $this->audit->record($actor, 'user_organization_changed', 'user', $subject->id, $before, $subject->only(array_keys($before)));
            }

            return $subject;
        });
    }

    public function hasActiveRecords(int $userId, bool $lock = false, bool $classifiedOnly = false): bool
    {
        foreach ($this->activeQueries($userId) as $table => $query) {
            if ($classifiedOnly) {
                if (! Schema::hasColumn($table, 'branch_group_id')) continue;
                $query->whereNotNull('branch_group_id');
            }
            // A caller may already have a REPEATABLE READ snapshot from before
            // the employee lock was acquired. Mutations need a current read.
            if ($lock ? $query->lockForUpdate()->first(['id']) !== null : $query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function activeQueries(int $userId): array
    {
        $queries = [];
        foreach (['properties' => 'agent_id', 'clients' => 'responsible_agent_id', 'leads' => 'responsible_agent_id',
            'crm_deals' => 'responsible_agent_id', 'bookings' => 'agent_id', 'crm_tasks' => 'assignee_id'] as $table => $responsible) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $responsible)) {
                continue;
            }
            $query = DB::table($table)->where($responsible, $userId);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            match ($table) {
                'properties' => $query->where(fn ($properties) => $properties
                    ->whereNull('moderation_status')
                    ->orWhereNotIn('moderation_status', ['sold', 'rented', 'sold_by_owner', 'deleted', 'archived'])),
                'clients' => $query->where('status', 'active'),
                'leads', 'crm_deals' => $query->whereNull('closed_at'),
                'bookings' => $query->where('end_time', '>', now()),
                'crm_tasks' => app(TaskGroupOwnership::class)->active($query),
            };
            $queries[$table] = $query;
        }

        return $queries;
    }
}
