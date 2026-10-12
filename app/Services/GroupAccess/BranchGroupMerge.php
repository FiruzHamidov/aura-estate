<?php

namespace App\Services\GroupAccess;

use App\Models\{BranchGroup, CrmTask, User};
use App\Observers\GroupOwnedRecordObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Consolidate current work without rewriting historical group snapshots. */
final class BranchGroupMerge
{
    public function authorize(User $actor, BranchGroup $source, BranchGroup $target): void
    {
        abort_unless($actor->hasPermission('branch_groups.merge') && !$actor->usesAssignedGroupScope(), 403, 'FORBIDDEN_ACTION');
        foreach ([$source, $target] as $group) {
            $sameBranch = $actor->branch_id && (int) $actor->branch_id === (int) $group->branch_id;
            abort_unless(($actor->hasPermission('branch_groups.scope.all') || ($sameBranch && $actor->hasPermission('branch_groups.scope.branch')))
                && ($actor->hasPermission('branch_groups.manage.all') || ($sameBranch && $actor->hasPermission('branch_groups.manage.branch'))), 403, 'FORBIDDEN_ACTION');
        }
        abort_if($source->id === $target->id, 422, 'Выберите другую группу назначения.');
        abort_unless((int) $source->branch_id === (int) $target->branch_id, 422, 'Объединять можно только группы одного филиала.');
    }

    private function inventory(BranchGroup $source, BranchGroup $target, bool $lock = false): array
    {
        $employees = User::query()->where('branch_group_id', $source->id)->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $ids = $employees->modelKeys();
        $blockers = [];
        foreach ($employees as $employee) {
            if ((int) $employee->branch_id !== (int) $source->branch_id) $blockers[] = "Сотрудник #{$employee->id}: филиал не совпадает с группой.";
        }
        $records = [];
        // The same active-state predicates are used by individual employee transfers.
        foreach (app(UserOrganizationService::class)->activeQueries(null) as $table => $query) {
            $field = GroupOwnedRecordObserver::RESPONSIBLES[$table];
            if ($table === 'crm_deals' && Schema::hasColumn('crm_deal_pipelines', 'code')) {
                // Recruitment is shared HR work and must never acquire a sales group.
                $query->where(fn ($q) => $q->whereNull('pipeline_id')->orWhereNotIn('pipeline_id',
                    DB::table('crm_deal_pipelines')->select('id')->where('code', 'hr_recruitment')));
            }
            $query->where(fn ($q) => $q->where('branch_group_id', $source->id)->orWhereIn($field, $ids));
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                // An employee's existing work in the target already belongs there.
                if ((int) $row->branch_group_id === (int) $target->id) continue;
                if ($row->branch_group_id && (int) $row->branch_group_id !== (int) $source->id) {
                    $blockers[] = "{$table} #{$row->id}: запись сотрудника относится к другой группе.";
                    continue;
                }
                if (isset($row->branch_id) && (int) $row->branch_id !== (int) $source->branch_id) {
                    $blockers[] = "{$table} #{$row->id}: запись относится к другому филиалу.";
                }
                $records[$table][$row->id] = (array) $row;
            }
        }
        // Active property-control cards inherit their property's group, not the SB assignee's group.
        if (!empty($records['properties']) && Schema::hasColumn('crm_deals', 'control_kind')) {
            $controls = DB::table('crm_deals')->where('control_kind', 'security_property_closure')
                ->whereIn('primary_property_id', array_keys($records['properties']))->whereNull('closed_at');
            if (Schema::hasColumn('crm_deals', 'deleted_at')) $controls->whereNull('deleted_at');
            foreach ($controls->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                if ($row->branch_group_id && !in_array((int) $row->branch_group_id, [$source->id, $target->id], true)) {
                    $blockers[] = "Карточка контроля #{$row->id}: относится к другой группе.";
                }
                $records['crm_deals'][$row->id] = (array) $row;
            }
        }
        // Include active linked tasks even when they have no assignee/group.
        $parents = ['properties' => ['property', 'ad'], 'clients' => ['client'], 'leads' => ['lead'], 'crm_deals' => ['deal'], 'bookings' => ['showing']];
        if (Schema::hasTable('crm_tasks')) foreach ($parents as $table => $types) {
            if (empty($records[$table])) continue;
            $query = app(TaskGroupOwnership::class)->active(DB::table('crm_tasks'))
                ->whereIn('related_entity_type', $types)->whereIn('related_entity_id', array_keys($records[$table]));
            if (Schema::hasColumn('crm_tasks', 'deleted_at')) $query->whereNull('deleted_at');
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                if ($row->branch_group_id && !in_array((int) $row->branch_group_id, [$source->id, $target->id], true)) {
                    $blockers[] = "Задача #{$row->id}: связана с другой группой.";
                }
                $records['crm_tasks'][$row->id] = (array) $row;
            }
        }
        $ownerIds = [];
        foreach ($records as $table => $rows) foreach ($rows as $row) $ownerIds[] = $row[GroupOwnedRecordObserver::RESPONSIBLES[$table]] ?? null;
        $owners = User::query()->with('role')->whereIn('id', array_filter($ownerIds))->get()->keyBy('id');
        foreach ($records as $table => $rows) foreach ($rows as $row) {
            $responsible = $row[GroupOwnedRecordObserver::RESPONSIBLES[$table]] ?? null;
            $owner = $responsible ? $owners->get($responsible) : null;
            if (isset($row['branch_id']) && (int) $row['branch_id'] !== (int) $source->branch_id) {
                $blockers[] = "{$table} #{$row['id']}: запись относится к другому филиалу.";
            }
            if ($responsible && !$owner) $blockers[] = "{$table} #{$row['id']}: ответственный не найден.";
            if ($owner && $owner->hasPermission('users.subject.assignment_group_required')
                && (!in_array($owner->id, $ids, true) && (int) $owner->branch_group_id !== (int) $target->id)) {
                $blockers[] = "{$table} #{$row['id']}: ответственный не входит в объединяемую команду. Сначала передайте запись.";
            }
            if ($table === 'crm_deals' && ($row['control_kind'] ?? null) === 'security_property_closure') {
                $propertyId = $row['primary_property_id'] ?? null;
                if (!$propertyId || (!isset($records['properties'][$propertyId])
                    && (int) DB::table('properties')->where('id', $propertyId)->value('branch_group_id') !== (int) $target->id)) {
                    $blockers[] = "Карточка контроля #{$row['id']}: объект остаётся в прежней группе.";
                }
            }
            if ($table === 'crm_tasks' && ($row['related_entity_id'] ?? null)) {
                $parent = app(TaskGroupOwnership::class)->parent((new CrmTask)->newFromBuilder($row), $lock);
                if ($parent && (int) $parent->branch_group_id !== (int) $target->id && !isset($records[$parent->getTable()][$parent->id])) {
                    $blockers[] = "Задача #{$row['id']}: родительская запись остаётся в прежней группе.";
                }
            }
        }
        foreach ($records as &$rows) ksort($rows);
        unset($rows);
        ksort($records);
        $snapshot = [$source->getAttributes(), $target->getAttributes(), $employees->map->getAttributes()->all(), $records];
        return ['employees' => $employees, 'records' => $records, 'blockers' => array_values(array_unique($blockers)),
            'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function summary(BranchGroup $source, BranchGroup $target, array $inventory): array
    {
        return ['source' => $source->only(['id', 'name', 'branch_id']), 'target' => $target->only(['id', 'name', 'branch_id']),
            'employees' => $inventory['employees']->map->only(['id', 'name'])->values()->all(),
            'counts' => array_map('count', $inventory['records']), 'blockers' => $inventory['blockers'], 'revision' => $inventory['revision']];
    }

    public function preview(User $actor, BranchGroup $source, BranchGroup $target): array
    {
        $this->authorize($actor, $source, $target);
        return $this->summary($source, $target, $this->inventory($source, $target));
    }

    public function merge(User $actor, BranchGroup $source, BranchGroup $target, string $revision, string $reason): array
    {
        return DB::transaction(function () use ($actor, $source, $target, $revision, $reason) {
            // Match the users → groups → records lock order of all ordinary writers.
            User::query()->where(fn ($q) => $q->where('branch_id', $source->branch_id)->orWhere('id', $actor->id))
                ->orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            $groups = BranchGroup::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $groups->get($source->id); $target = $groups->get($target->id);
            abort_unless($source && $target, 404);
            $this->authorize($actor, $source, $target);
            $inventory = $this->inventory($source, $target, true);
            abort_unless(hash_equals($inventory['revision'], $revision), 409, 'Состав групп изменился. Обновите предварительный план.');
            abort_if($inventory['blockers'] !== [], 422, 'Устраните препятствия из предварительного плана.');
            abort_if($inventory['employees']->isEmpty() && $inventory['records'] === [], 422, 'Нет сотрудников или активных записей для переноса.');
            $audit = app(GroupAccessAudit::class);
            foreach ($inventory['employees'] as $employee) {
                DB::table('users')->where('id', $employee->id)->update(['branch_group_id' => $target->id,
                    'access_scope_version' => DB::raw('access_scope_version + 1'), 'updated_at' => now()]);
                $audit->record($actor, 'user_group_merged', 'user', $employee->id, ['branch_group_id' => $source->id], ['branch_group_id' => $target->id], $reason);
            }
            foreach ($inventory['records'] as $table => $rows) foreach ($rows as $id => $row) {
                $changes = ['branch_group_id' => $target->id, 'updated_at' => now()];
                if (array_key_exists('branch_id', $row)) $changes['branch_id'] = $target->branch_id;
                if ($table === 'properties') $changes['moderation_version'] = DB::raw('moderation_version + 1');
                DB::table($table)->where('id', $id)->update($changes);
                $audit->record($actor, 'record_group_merged', $table, $id, ['branch_group_id' => $row['branch_group_id']], ['branch_group_id' => $target->id], $reason);
            }
            $result = $this->summary($source, $target, $inventory);
            $audit->record($actor, 'branch_groups_merged', 'branch_group', $source->id, ['branch_group_id' => $source->id], $result, $reason);
            return $result;
        }, 3);
    }
}
