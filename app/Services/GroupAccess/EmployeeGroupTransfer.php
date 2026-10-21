<?php

namespace App\Services\GroupAccess;

use App\Models\{BranchGroup, User};
use Illuminate\Support\Facades\DB;

/** Every active root needs an explicit disposition; readers see either side of one transaction. */
final class EmployeeGroupTransfer
{
    public function __construct(
        private readonly UserOrganizationService $organization,
        private readonly GroupRecordTransfer $records,
        private readonly GroupAccessAudit $audit,
    ) {}

    private function authorize(User $actor, User $employee): void
    {
        if (app(\App\Support\RopGroupAccess::class)->canAssignUngroupedEmployee($actor, $employee)) return;
        abort_unless($actor->hasPermission('users.group_transfer'), 403, 'FORBIDDEN_ACTION');
        abort_unless($employee->hasPermission('users.subject.group_transfer'), 422, 'EMPLOYEE_ROLE_REQUIRED');
        if ($actor->usesAssignedGroupScope()) {
            abort_unless($actor->id !== $employee->id && $employee->status === User::STATUS_ACTIVE && ! $employee->isDeletedAccount(), 403, 'FORBIDDEN_ACTION');
            app(\App\Support\RopGroupAccess::class)->ensureVisible($actor, $employee);
        }
        if (!$actor->hasPermission('users.group_transfer.all_branches')) {
            abort_unless($actor->branch_id && (int) $employee->branch_id === (int) $actor->branch_id, 404, 'NOT_FOUND');
        }
    }

    public function destinationQuery(User $actor, bool $initialAssignment = false): \Illuminate\Database\Eloquent\Builder
    {
        if ($initialAssignment) {
            abort_unless($actor->usesAssignedGroupScope() && $actor->hasPermission('users.ungrouped.view')
                && $actor->hasPermission('users.ungrouped.assign'), 403, 'FORBIDDEN_ACTION');
            return BranchGroup::query()->where('branch_id', $actor->branch_id ?? 0)
                ->whereIn('id', app(\App\Support\RopGroupAccess::class)->groupsQuery($actor));
        }
        abort_unless($actor->usesAssignedGroupScope() && $actor->hasPermission('users.group_transfer'), 403, 'FORBIDDEN_ACTION');
        return BranchGroup::query()->where('branch_id', $actor->branch_id ?? 0)
            ->whereHas('rops', fn ($rops) => $rops->where('users.status', User::STATUS_ACTIVE)
                ->whereNull('users.deleted_at')->whereColumn('users.branch_id', 'branch_groups.branch_id')
                ->whereHas('role', fn ($roles) => $roles->usingAssignedGroups()));
    }

    private function inventory(User $employee, bool $lock = false): array
    {
        $result = [];
        $types = [];
        foreach (GroupRecordTransfer::MODELS as $type => $class) $types[(new $class)->getTable()] = $type;
        foreach ($this->organization->activeQueries($employee->id) as $table => $query) {
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                $type = $types[$table];
                $record = (new (GroupRecordTransfer::MODELS[$type]))->newFromBuilder((array) $row);
                $result[] = ['type' => $type, 'id' => (int) $row->id,
                    'title' => $row->title ?? $row->full_name ?? '#'.$row->id,
                    'branch_group_id' => $row->branch_group_id ?? null,
                    'revision' => $this->records->revision($record)];
            }
        }
        return $result;
    }

    /** Classify before changing the employee; never grant access to disputed records. */
    private function classify(User $actor, User $employee, array $inventory, bool $initialAssignment): array
    {
        if ($initialAssignment) {
            foreach ($inventory as &$item) {
                $this->records->authorize($actor, $this->records->model($item['type'], $item['id']), employeeTransfer: true, ungroupedEmployeeId: $employee->id);
                $item['review_reason'] = null;
            }
            return $inventory;
        }
        foreach ($inventory as &$item) {
            $record = $this->records->model($item['type'], $item['id']);
            $branch = $record->getAttributes()['branch_id'] ?? null;
            $groupBranch = DB::table('branch_groups')->where('id', $item['branch_group_id'] ?? 0)->value('branch_id');
            $item['review_reason'] = null;
            if (($branch && (int) $branch !== (int) $employee->branch_id)
                || ($groupBranch && (int) $groupBranch !== (int) $employee->branch_id)) {
                $item['review_reason'] = 'branch_conflict';
                continue;
            }
            try {
                $this->records->authorize($actor, $record, employeeTransfer: true, ungroupedEmployeeId: $initialAssignment ? $employee->id : null);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) throw $error;
                $item['review_reason'] = 'outside_transfer_scope';
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
                if (! in_array($error->getResponse()->getStatusCode(), [403, 404], true)) throw $error;
                $item['review_reason'] = 'outside_transfer_scope';
            }
        }
        unset($item);
        // A task must stay with a parent that is not part of this transfer.
        $movable = [];
        foreach ($inventory as $item) if (! $item['review_reason']) {
            $movable[(new (GroupRecordTransfer::MODELS[$item['type']]))->getTable().':'.$item['id']] = true;
        }
        foreach ($inventory as &$item) {
            if ($item['type'] !== 'tasks' || $item['review_reason']) continue;
            try {
                $parent = app(TaskGroupOwnership::class)->parent($this->records->model('tasks', $item['id']));
                if ($parent && ! isset($movable[$parent->getTable().':'.$parent->id])) $item['review_reason'] = 'parent_not_transferred';
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                $item['review_reason'] = 'parent_not_transferred';
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 422) throw $error;
                $item['review_reason'] = 'parent_not_transferred';
            }
        }
        unset($item);
        // Parent transfer cascades to tasks. Keep the parent too when a dependent task is disputed.
        $parentsToKeep = [];
        foreach ($inventory as $item) {
            if ($item['type'] !== 'tasks' || ! $item['review_reason']) continue;
            try {
                $parent = app(TaskGroupOwnership::class)->parent($this->records->model('tasks', $item['id']));
                if ($parent) $parentsToKeep[$parent->getTable().':'.$parent->id] = true;
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                // Already classified above; an absent parent cannot be moved.
            }
        }
        unset($item);
        foreach ($inventory as &$item) {
            $key = (new (GroupRecordTransfer::MODELS[$item['type']]))->getTable().':'.$item['id'];
            if (isset($parentsToKeep[$key])) $item['review_reason'] = 'parent_not_transferred';
            if ($item['type'] === 'tasks' && ! $item['review_reason']) {
                $parent = app(TaskGroupOwnership::class)->parent($this->records->model('tasks', $item['id']));
                if ($parent && isset($parentsToKeep[$parent->getTable().':'.$parent->id])) $item['review_reason'] = 'parent_not_transferred';
            }
        }
        return $inventory;
    }

    private function revision(User $employee, array $records): string
    {
        return hash('sha256', json_encode([$employee->only(['id', 'role_id', 'branch_id', 'branch_group_id', 'access_scope_version']), $records], JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor, User $employee): array
    {
        $this->authorize($actor, $employee);
        $initialAssignment = app(\App\Support\RopGroupAccess::class)->canAssignUngroupedEmployee($actor, $employee);
        $records = $this->classify($actor, $employee, $this->inventory($employee), $initialAssignment);
        return ['user_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'branch_group_id' => $employee->branch_group_id,
            'records' => array_values(array_filter($records, fn ($item) => ! $item['review_reason'])),
            'deferred_records' => array_values(array_map(fn ($item) => array_intersect_key($item, array_flip(['type', 'id', 'review_reason'])),
                array_filter($records, fn ($item) => $item['review_reason']))),
            'revision' => $this->revision($employee, $records)];
    }

    public function transfer(User $actor, User $employee, array $data): array
    {
        return DB::transaction(function () use ($actor, $employee, $data) {
            $userIds = [$actor->id, $employee->id, ...array_column($data['records'], 'responsible_user_id')];
            $users = User::query()->whereIn('id', array_filter($userIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $employee = $users->get($employee->id);
            abort_unless($actor && $employee, 404, 'NOT_FOUND');
            $this->authorize($actor, $employee);
            $initialAssignment = app(\App\Support\RopGroupAccess::class)->canAssignUngroupedEmployee($actor, $employee);
            // Use the same users → groups → records order as individual record transfers.
            // The unlocked inventory only identifies locks; validate the locked inventory below.
            $groupIds = [$data['branch_group_id'], ...array_column($this->inventory($employee), 'branch_group_id')];
            $groups = BranchGroup::query()->whereIn('id', array_filter($groupIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $destination = $groups->get((int) $data['branch_group_id']);
            abort_unless($destination, 404, 'NOT_FOUND');
            if (!$actor->hasPermission('users.group_transfer.all_branches')) {
                abort_unless((int) $destination->branch_id === (int) $actor->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            }
            if ($actor->usesAssignedGroupScope()) {
                abort_unless($this->destinationQuery($actor, $initialAssignment)->whereKey($destination->id)->exists(), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
                abort_if(! $initialAssignment && ! $employee->hasPermission('users.subject.group_transfer_across') && (int) $destination->id !== (int) $employee->branch_group_id, 403, 'Роль сотрудника не допускает перевод в другую группу этим руководителем.');
            }
            $sameGroup = (int) $destination->id === (int) $employee->branch_group_id;
            $inventory = $this->classify($actor, $employee, $this->inventory($employee, true), $initialAssignment);
            abort_unless(hash_equals($this->revision($employee, $inventory), $data['revision']), 409, 'EMPLOYEE_TRANSFER_VERSION_CONFLICT');
            $deferred = array_values(array_filter($inventory, fn ($item) => $item['review_reason']));
            $inventory = array_values(array_filter($inventory, fn ($item) => ! $item['review_reason']));
            $plans = [];
            foreach ($data['records'] as $plan) {
                $key = $plan['type'].':'.$plan['id'];
                abort_if(isset($plans[$key]), 422, 'DUPLICATE_TRANSFER_RECORD');
                $plans[$key] = $plan;
            }
            $expected = array_map(fn ($item) => $item['type'].':'.$item['id'], $inventory);
            $actual = array_keys($plans); sort($expected); sort($actual);
            abort_unless($actual === $expected, 422, 'COMPLETE_TRANSFER_PLAN_REQUIRED');
            if ($sameGroup) {
                abort_if($inventory === [] || collect($plans)->contains(fn ($plan) => $plan['action'] !== 'retain'),
                    422, 'REPLACEMENT_EMPLOYEE_REQUIRED');
            }
            foreach ($inventory as $item) $this->records->authorize($actor, $this->records->model($item['type'], $item['id']), employeeTransfer: true, ungroupedEmployeeId: $initialAssignment ? $employee->id : null);
            if ($initialAssignment) {
                // Initial assignment never redistributes records to another employee.
                abort_if(collect($plans)->contains(fn ($plan) => $plan['action'] !== 'move'), 422, 'COMPLETE_TRANSFER_PLAN_REQUIRED');
            }
            foreach ($deferred as $item) {
                $table = (new (GroupRecordTransfer::MODELS[$item['type']]))->getTable();
                DB::table('group_access_review_items')->updateOrInsert(
                    ['source_table' => $table, 'source_id' => $item['id'], 'reason' => 'employee_transfer_dispute'],
                    ['branch_id' => null, 'detected_at' => now(), 'resolved_at' => null],
                );
            }
            $before = $employee->only(['role_id', 'branch_id', 'branch_group_id']);
            $employee->forceFill(['branch_id' => $destination->branch_id, 'branch_group_id' => $destination->id,
                'access_scope_version' => (int) $employee->access_scope_version + 1])->save();
            foreach ($inventory as $item) {
                $plan = $plans[$item['type'].':'.$item['id']];
                $move = $plan['action'] === 'move';
                $responsible = $move ? $employee->id : ($plan['responsible_user_id'] ?? null);
                abort_unless($responsible && ($move || (int) $responsible !== (int) $employee->id), 422, 'REPLACEMENT_EMPLOYEE_REQUIRED');
                $record = $this->records->model($item['type'], $item['id'], true);
                try {
                    $this->records->transfer($actor, $item['type'], $item['id'], [
                        'branch_group_id' => $move ? $destination->id : $item['branch_group_id'],
                        'responsible_user_id' => $responsible, 'reason' => $data['reason'],
                        'revision' => $this->records->revision($record),
                        ...array_intersect_key($plan, array_flip(['pipeline_id', 'stage_id'])),
                    ], employeeTransfer: true, ungroupedEmployeeId: $initialAssignment ? $employee->id : null);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 422) throw $error;
                    $messages = [
                        'TRANSFER_PIPELINE_REQUIRED' => 'Выберите доступную воронку и этап для новой группы.',
                        'TRANSFER_STAGE_STATE_MISMATCH' => 'Выберите этап с тем же состоянием сделки.',
                        'TASK_MUST_FOLLOW_PARENT_GROUP' => 'Задача должна перейти в ту же группу, что и связанная запись.',
                        'INVALID_TRANSFER_TARGET' => 'Выберите действующего ответственного из нужной группы.',
                    ];
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'code' => $error->getMessage(),
                        'records.'.$item['type'].':'.$item['id'] => $messages[$error->getMessage()] ?? $error->getMessage(),
                    ]);
                }
            }
            // Includes records newly assigned by a concurrent writer before our final consistency check.
            foreach ($this->organization->activeQueries($employee->id) as $table => $query) {
                $deferredIds = array_column(array_filter($deferred, fn ($item) => (new (GroupRecordTransfer::MODELS[$item['type']]))->getTable() === $table), 'id');
                abort_if($query->whereNotIn('id', $deferredIds)->when(! $sameGroup, fn ($query) => $query->where(fn ($q) => $q->whereNull('branch_group_id')->orWhere('branch_group_id', '!=', $destination->id)))
                    ->lockForUpdate()->first(['id']) !== null, 409, 'EMPLOYEE_TRANSFER_RECORD_CONFLICT');
            }
            $after = $employee->only(['role_id', 'branch_id', 'branch_group_id']);
            $this->audit->record($actor, 'employee_group_transferred', 'user', $employee->id, $before,
                [...$after, 'records' => $data['records'], 'deferred_records' => $deferred], $data['reason']);
            return ['user_id' => $employee->id, ...$after, 'transferred_records' => count($inventory), 'deferred_records_count' => count($deferred),
                'version' => (int) $employee->access_scope_version];
        }, 3);
    }
}
