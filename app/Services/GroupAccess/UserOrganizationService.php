<?php

namespace App\Services\GroupAccess;

use App\Models\BranchGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\InternationalPhone;
use App\Support\RopGroupAccess;
use App\Support\UserPhoneIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class UserOrganizationService
{
    public const CLOSED_PROPERTY_STATUSES = ['sold', 'rented', 'sold_by_owner', 'deleted', 'archived'];

    public function __construct(private readonly GroupAccessAudit $audit) {}

    public function update(User $actor, User $subject, array $data): User
    {
        return $this->applyUpdate($actor, $subject->id, $data);
    }

    public function findRecruitableClient(User $actor, string $phone): User
    {
        $this->authorizeRecruitment($actor);
        $number = InternationalPhone::e164($phone);
        if ($number === null || (str_starts_with($number, '+992') && strlen($number) !== 13)) {
            throw ValidationException::withMessages(['phone' => 'Введите полный номер телефона.']);
        }
        $client = UserPhoneIdentity::resolve($number);
        abort_unless($client && $this->isRecruitableClient($client), 404, 'Клиент для перевода по этому номеру не найден.');

        return $client;
    }

    private function authorizeRecruitment(User $actor): void
    {
        abort_unless($actor->status === User::STATUS_ACTIVE && ! $actor->isDeletedAccount()
            && $actor->usesAssignedGroupScope() && $actor->hasPermission('users.create')
            && $actor->hasPermission('users.update') && ($actor->hasPermission('users.assign_role.agent') || $actor->hasPermission('users.assign_any_role')),
            403, 'Нет права переводить клиентов в агенты.');
        abort_unless(BranchGroup::whereIn('id', app(RopGroupAccess::class)->groupsQuery($actor))->exists(),
            403, 'Нет закреплённой группы для назначения.');
    }

    private function isRecruitableClient(User $user): bool
    {
        return $user->role?->slug === 'client' && $user->status === User::STATUS_ACTIVE && ! $user->isDeletedAccount()
            && $user->branch_id === null && $user->branch_group_id === null;
    }

    public function recruitAgent(User $actor, int $clientId, string $phone, int $groupId): User
    {
        $client = $this->findRecruitableClient($actor, $phone);
        abort_unless((int) $client->id === $clientId, 404, 'Клиент для перевода по этому номеру не найден.');

        return $this->applyUpdate($actor, $clientId, ['branch_group_id' => $groupId], $phone);
    }

    private function applyUpdate(User $actor, int $subjectId, array $data, ?string $recruitmentPhone = null): User
    {
        return DB::transaction(function () use ($actor, $subjectId, $data, $recruitmentPhone) {
            $users = User::query()->whereIn('id', [$actor->id, $subjectId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subject = $users->get($subjectId);
            $actor = $users->get($actor->id);
            abort_unless($subject && $actor, 404, 'NOT_FOUND');
            if ($recruitmentPhone !== null) {
                $this->authorizeRecruitment($actor);
                abort_unless($this->isRecruitableClient($subject)
                    && InternationalPhone::e164($subject->phone) === InternationalPhone::e164($recruitmentPhone),
                    409, 'Данные клиента изменились. Повторите поиск.');
                app(RopGroupAccess::class)->ensureGroup($actor, $data['branch_group_id']);
                $agent = Role::where('slug', 'agent')->firstOrFail();
                abort_unless($agent->hasPermission('users.subject.team'), 403, 'Роль агента недоступна для назначения в группу.');
                $data['role_id'] = $agent->id;
                $data['branch_id'] = $actor->branch_id;
            }
            $before = $subject->only(['role_id', 'branch_id', 'branch_group_id']);
            $previousRole = $subject->role;
            $subject->fill($data);
            $nextRole = $subject->isDirty('role_id')
                ? Role::find($subject->role_id) : $previousRole;
            // Promotion within the same team preserves existing responsibility.
            // Supervised groups are still assigned explicitly; this does not make
            // the new supervisor eligible to receive other employees' records.
            $keepsRecordsAsSupervisor = $previousRole?->hasPermission('users.subject.record_recipient')
                && $nextRole?->usesAssignedGroupScope()
                && $subject->status === User::STATUS_ACTIVE
                && $subject->branch_group_id !== null
                && ! $subject->isDirty(['branch_id', 'branch_group_id', 'status']);
            // Existing work still needs a handover even after recipient eligibility is revoked.
            if (($subject->isDirty('role_id') && ! $nextRole?->hasPermission('users.subject.record_recipient') && ! $keepsRecordsAsSupervisor)
                || ($subject->isDirty('status') && $subject->status !== User::STATUS_ACTIVE)) {
                abort_if($this->hasActiveRecords($subject->id, lock: true),
                    409, 'EMPLOYEE_TRANSFER_REQUIRED');
            }
            $changed = $subject->isDirty(['role_id', 'branch_id', 'branch_group_id']);
            if ($changed) {
                if ($recruitmentPhone === null && $actor->usesAssignedGroupScope()) {
                    app(RopGroupAccess::class)->ensureVisible($actor, $subject->fresh());
                    abort_unless($actor->id !== $subject->id
                        && $previousRole?->hasPermission('users.subject.team')
                        && $nextRole?->hasPermission('users.subject.team')
                        && ! $subject->isDirty(['branch_id', 'branch_group_id', 'status']), 403, 'FORBIDDEN_ACTION');
                }
                if ($subject->branch_group_id) {
                    $group = BranchGroup::query()->lockForUpdate()->find($subject->branch_group_id);
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

    public function activeQueries(?int $userId): array
    {
        $queries = [];
        foreach (['properties' => 'agent_id', 'clients' => 'responsible_agent_id', 'leads' => 'responsible_agent_id',
            'crm_deals' => 'responsible_agent_id', 'bookings' => 'agent_id', 'crm_tasks' => 'assignee_id'] as $table => $responsible) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $responsible)) {
                continue;
            }
            $query = DB::table($table);
            if ($userId !== null) {
                if ($table === 'properties') {
                    $query->where(fn ($q) => $q->where('agent_id', $userId)
                        ->orWhere(fn ($unassigned) => $unassigned->whereNull('agent_id')->where('created_by', $userId)));
                } else $query->where($responsible, $userId);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            match ($table) {
                'properties' => $query->where(fn ($properties) => $properties
                    ->whereNull('moderation_status')
                    ->orWhereNotIn('moderation_status', self::CLOSED_PROPERTY_STATUSES)),
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
