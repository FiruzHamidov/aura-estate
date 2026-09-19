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
        abort_unless(in_array($actor->role?->slug, ['admin', 'superadmin', 'branch_director'], true), 403, 'FORBIDDEN_ACTION');
        abort_unless(in_array($employee->role?->slug, ['agent', 'mop'], true), 422, 'EMPLOYEE_ROLE_REQUIRED');
        if ($actor->hasRole('branch_director')) {
            abort_unless($actor->branch_id && (int) $employee->branch_id === (int) $actor->branch_id, 404, 'NOT_FOUND');
        }
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

    private function revision(User $employee, array $records): string
    {
        return hash('sha256', json_encode([$employee->only(['id', 'role_id', 'branch_id', 'branch_group_id', 'access_scope_version']), $records], JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor, User $employee): array
    {
        $this->authorize($actor, $employee);
        $records = $this->inventory($employee);
        foreach ($records as $item) $this->records->authorize($actor, $this->records->model($item['type'], $item['id']));
        return ['user_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'branch_group_id' => $employee->branch_group_id, 'records' => $records,
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
            // Use the same users → groups → records order as individual record transfers.
            // The unlocked inventory only identifies locks; validate the locked inventory below.
            $groupIds = [$data['branch_group_id'], ...array_column($this->inventory($employee), 'branch_group_id')];
            $groups = BranchGroup::query()->whereIn('id', array_filter($groupIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $destination = $groups->get((int) $data['branch_group_id']);
            abort_unless($destination, 404, 'NOT_FOUND');
            if ($actor->hasRole('branch_director')) {
                abort_unless((int) $destination->branch_id === (int) $actor->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            }
            $sameGroup = (int) $destination->id === (int) $employee->branch_group_id;
            $inventory = $this->inventory($employee, true);
            abort_unless(hash_equals($this->revision($employee, $inventory), $data['revision']), 409, 'EMPLOYEE_TRANSFER_VERSION_CONFLICT');
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
            foreach ($inventory as $item) $this->records->authorize($actor, $this->records->model($item['type'], $item['id']));
            $before = $employee->only(['role_id', 'branch_id', 'branch_group_id']);
            $employee->forceFill(['branch_id' => $destination->branch_id, 'branch_group_id' => $destination->id,
                'access_scope_version' => (int) $employee->access_scope_version + 1])->save();
            foreach ($inventory as $item) {
                $plan = $plans[$item['type'].':'.$item['id']];
                $move = $plan['action'] === 'move';
                $responsible = $move ? $employee->id : ($plan['responsible_user_id'] ?? null);
                abort_unless($responsible && ($move || (int) $responsible !== (int) $employee->id), 422, 'REPLACEMENT_EMPLOYEE_REQUIRED');
                $record = $this->records->model($item['type'], $item['id'], true);
                $this->records->transfer($actor, $item['type'], $item['id'], [
                    'branch_group_id' => $move ? $destination->id : $item['branch_group_id'],
                    'responsible_user_id' => $responsible, 'reason' => $data['reason'],
                    'revision' => $this->records->revision($record),
                    ...array_intersect_key($plan, array_flip(['pipeline_id', 'stage_id'])),
                ]);
            }
            // Includes records newly assigned by a concurrent writer before our final consistency check.
            foreach ($this->organization->activeQueries($employee->id) as $table => $query) {
                abort_if($query->when(! $sameGroup, fn ($query) => $query->where(fn ($q) => $q->whereNull('branch_group_id')->orWhere('branch_group_id', '!=', $destination->id)))
                    ->lockForUpdate()->first(['id']) !== null, 409, 'EMPLOYEE_TRANSFER_RECORD_CONFLICT');
            }
            $after = $employee->only(['role_id', 'branch_id', 'branch_group_id']);
            $this->audit->record($actor, 'employee_group_transferred', 'user', $employee->id, $before,
                [...$after, 'records' => $data['records']], $data['reason']);
            return ['user_id' => $employee->id, ...$after, 'transferred_records' => count($inventory),
                'version' => (int) $employee->access_scope_version];
        }, 3);
    }
}
