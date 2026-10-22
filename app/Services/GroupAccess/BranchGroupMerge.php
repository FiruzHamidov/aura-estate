<?php

namespace App\Services\GroupAccess;

use App\Models\{BranchGroup, CrmTask, User};
use App\Observers\GroupOwnedRecordObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Consolidate work and optionally all properties without rewriting historical snapshots. */
final class BranchGroupMerge
{
    private const LABELS = ['properties' => 'Объект', 'clients' => 'Клиент', 'leads' => 'Лид',
        'crm_deals' => 'Сделка', 'bookings' => 'Показ', 'crm_tasks' => 'Задача'];

    public function authorize(User $actor, BranchGroup $source, BranchGroup $target): void
    {
        abort_unless($actor->hasPermission('branch_groups.merge') && !$actor->usesAssignedGroupScope(), 403, 'FORBIDDEN_ACTION');
        foreach ([$source, $target] as $group) {
            $sameBranch = $actor->branch_id && (int) $actor->branch_id === (int) $group->branch_id;
            abort_unless(($actor->hasPermission('branch_groups.scope.all') || ($sameBranch && $actor->hasPermission('branch_groups.scope.branch')))
                && ($actor->hasPermission('branch_groups.manage.all') || ($sameBranch && $actor->hasPermission('branch_groups.manage.branch'))), 403, 'FORBIDDEN_ACTION');
        }
        abort_if($source->is_archived || $target->is_archived, 422, 'Архивную группу нельзя объединить.');
        abort_if($source->id === $target->id, 422, 'Выберите другую группу назначения.');
        abort_unless((int) $source->branch_id === (int) $target->branch_id, 422, 'Объединять можно только группы одного филиала.');
    }

    private function inventory(BranchGroup $source, BranchGroup $target, bool $lock = false, bool $includeAllProperties = false): array
    {
        $employees = User::query()->where('branch_group_id', $source->id)->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $ids = $employees->modelKeys();
        $blockers = [];
        foreach ($employees as $employee) {
            if ((int) $employee->branch_id !== (int) $source->branch_id) $blockers[] = "Сотрудник #{$employee->id}: филиал не совпадает с группой.";
        }
        $records = [];
        // Unfinished control of historical properties stays with the property, even
        // when its responsible employee moves. Keep linked tasks there as well.
        $retainedControls = [];
        if (!$includeAllProperties && Schema::hasColumn('crm_deals', 'control_kind') && Schema::hasTable('properties')) {
            $historical = DB::table('properties')->where('branch_group_id', $source->id)
                ->where(function ($query) {
                    $query->whereIn('moderation_status', UserOrganizationService::CLOSED_PROPERTY_STATUSES);
                    if (Schema::hasColumn('properties', 'deleted_at')) $query->orWhereNotNull('deleted_at');
                })->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id');
            $retainedControls = DB::table('crm_deals')->where('control_kind', 'security_property_closure')
                ->where('branch_group_id', $source->id)->whereIn('primary_property_id', $historical)
                ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('id')->all();
        }
        // The same active-state predicates are used by individual employee transfers.
        foreach (app(UserOrganizationService::class)->activeQueries(null) as $table => $query) {
            $label = self::LABELS[$table];
            $field = GroupOwnedRecordObserver::RESPONSIBLES[$table];
            // Retirement transfers the group's complete property inventory, including
            // sold/archived/deleted rows. Other closed business records remain history.
            if ($includeAllProperties && $table === 'properties') $query = DB::table('properties');
            if ($table === 'crm_deals' && Schema::hasColumn('crm_deal_pipelines', 'code')) {
                // Recruitment is shared HR work and must never acquire a sales group.
                $query->where(fn ($q) => $q->whereNull('pipeline_id')->orWhereNotIn('pipeline_id',
                    DB::table('crm_deal_pipelines')->select('id')->where('code', 'hr_recruitment')));
            }
            if ($includeAllProperties) {
                // Group consolidation must not pull an employee's unrelated work
                // from another group just because the employee changes team.
                $query->where('branch_group_id', $source->id);
            } else {
                $query->where(fn ($q) => $q->where('branch_group_id', $source->id)->orWhereIn($field, $ids));
            }
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                if ($table === 'crm_deals' && in_array($row->id, $retainedControls, true)) continue;
                if ($table === 'crm_tasks' && ($row->related_entity_type ?? null) === 'deal'
                    && in_array($row->related_entity_id ?? null, $retainedControls, true)
                    && (!$row->branch_group_id || (int) $row->branch_group_id === (int) $source->id)) continue;
                // An employee's existing work in the target already belongs there.
                if ((int) $row->branch_group_id === (int) $target->id) continue;
                if (isset($row->branch_id) && (int) $row->branch_id !== (int) $source->branch_id) {
                    $blockers[] = "{$label} #{$row->id}: запись относится к другому филиалу.";
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
                if ((int) $row->branch_group_id === (int) $target->id) continue;
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
                if ((int) $row->branch_group_id === (int) $target->id) continue;
                $records['crm_tasks'][$row->id] = (array) $row;
            }
        }
        $ownerIds = [];
        foreach ($records as $table => $rows) foreach ($rows as $row) $ownerIds[] = $row[GroupOwnedRecordObserver::RESPONSIBLES[$table]] ?? null;
        $owners = User::query()->with('role')->whereIn('id', array_filter($ownerIds))->get()->keyBy('id');
        $otherGroupCount = 0;
        $outsideTeamCount = 0;
        foreach ($records as $table => $rows) foreach ($rows as $row) {
            if (!empty($row['branch_group_id']) && !in_array((int) $row['branch_group_id'], [(int) $source->id, (int) $target->id], true)) $otherGroupCount++;
            $label = self::LABELS[$table];
            if ($includeAllProperties && !empty($row['branch_group_id']) && (int) $row['branch_group_id'] !== (int) $source->id) {
                $blockers[] = "{$label} #{$row['id']}: связанная запись относится к другой группе. Сначала исправьте её принадлежность.";
            }
            $responsible = $row[GroupOwnedRecordObserver::RESPONSIBLES[$table]] ?? null;
            $owner = $responsible ? $owners->get($responsible) : null;
            if (isset($row['branch_id']) && (int) $row['branch_id'] !== (int) $source->branch_id) {
                $blockers[] = "{$label} #{$row['id']}: запись относится к другому филиалу.";
            }
            if ($responsible && !$owner) $blockers[] = "{$label} #{$row['id']}: ответственный не найден.";
            if ($owner && $owner->hasPermission('users.subject.assignment_group_required')
                && (!in_array($owner->id, $ids, true) && (int) $owner->branch_group_id !== (int) $target->id)) {
                $outsideTeamCount++;
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
        // Group merge explicitly consolidates active work, preserving its assignees.
        // Existing group/assignee mismatches are disclosed, not silently reassigned.
        $warnings = [];
        if (!$includeAllProperties && $otherGroupCount) $warnings[] = "Записи из других групп: {$otherGroupCount}. Они также перейдут в выбранную группу.";
        if ($outsideTeamCount) $warnings[] = "Записи с ответственными вне объединяемой команды: {$outsideTeamCount}. Ответственные сохранятся; сотрудники других групп не переводятся.";
        foreach ($records as &$rows) ksort($rows);
        unset($rows);
        ksort($records);
        $snapshot = [$includeAllProperties, $source->getAttributes(), $target->getAttributes(), $employees->map->getAttributes()->all(), $records];
        return ['employees' => $employees, 'records' => $records, 'blockers' => array_values(array_unique($blockers)),
            'include_all_properties' => $includeAllProperties,
            'historical_properties' => count(array_filter($records['properties'] ?? [], fn ($row) =>
                !empty($row['deleted_at']) || in_array($row['moderation_status'] ?? null, UserOrganizationService::CLOSED_PROPERTY_STATUSES, true))),
            'warnings' => $warnings, 'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function summary(BranchGroup $source, BranchGroup $target, array $inventory): array
    {
        return ['source' => $source->only(['id', 'name', 'branch_id']), 'target' => $target->only(['id', 'name', 'branch_id']),
            'employees' => $inventory['employees']->map->only(['id', 'name'])->values()->all(),
            'include_all_properties' => $inventory['include_all_properties'], 'historical_properties' => $inventory['historical_properties'],
            'counts' => array_map('count', $inventory['records']), 'warnings' => $inventory['warnings'], 'blockers' => $inventory['blockers'], 'revision' => $inventory['revision']];
    }

    public function preview(User $actor, BranchGroup $source, BranchGroup $target, bool $includeAllProperties = false): array
    {
        $this->authorize($actor, $source, $target);
        return $this->summary($source, $target, $this->inventory($source, $target, includeAllProperties: $includeAllProperties));
    }

    public function merge(User $actor, BranchGroup $source, BranchGroup $target, string $revision, string $reason, bool $archiveSource = false, bool $includeAllProperties = false): array
    {
        return DB::transaction(function () use ($actor, $source, $target, $revision, $reason, $archiveSource, $includeAllProperties) {
            // Match the users → groups → records lock order of all ordinary writers.
            User::query()->where(fn ($q) => $q->where('branch_id', $source->branch_id)->orWhere('id', $actor->id))
                ->orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            $groups = BranchGroup::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $groups->get($source->id); $target = $groups->get($target->id);
            abort_unless($source && $target, 404);
            $this->authorize($actor, $source, $target);
            $inventory = $this->inventory($source, $target, true, $includeAllProperties);
            abort_unless(hash_equals($inventory['revision'], $revision), 409, 'Состав групп изменился. Обновите предварительный план.');
            abort_if($inventory['blockers'] !== [], 422, 'Устраните препятствия из предварительного плана.');
            abort_if(!$archiveSource && $inventory['employees']->isEmpty() && $inventory['records'] === [], 422, 'Нет сотрудников или активных записей для переноса.');
            $audit = app(GroupAccessAudit::class);
            foreach ($inventory['employees'] as $employee) {
                $before = $employee->only(['role_id', 'branch_id', 'branch_group_id']);
                DB::table('users')->where('id', $employee->id)->update(['branch_group_id' => $target->id,
                    'access_scope_version' => DB::raw('access_scope_version + 1'), 'updated_at' => now()]);
                $audit->record($actor, 'user_group_merged', 'user', $employee->id, $before,
                    [...$before, 'branch_group_id' => $target->id], $reason);
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
            if ($archiveSource) {
                $source->update(['is_archived' => true]);
                $audit->record($actor, 'branch_group_archived', 'branch_group', $source->id,
                    ['is_archived' => false], ['is_archived' => true], $reason);
            }
            $result['source_archived'] = $archiveSource;
            return $result;
        }, 3);
    }
}
