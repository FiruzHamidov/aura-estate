<?php

namespace App\Services\Users;

use App\Models\BranchGroup;
use App\Models\User;
use App\Models\DealStage;
use App\Services\GroupAccess\TaskGroupOwnership;
use App\Observers\GroupOwnedRecordObserver;
use App\Support\RopGroupAccess;
use App\Services\GroupAccess\GroupAccessAudit;
use App\Services\GroupAccess\GroupRecordTransfer;
use App\Services\GroupAccess\UserOrganizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A reviewed transfer; closed history is preserved. */
final class DismissalTransfer
{
    public function __construct(
        private readonly UserOrganizationService $organization,
        private readonly GroupAccessAudit $audit,
    ) {}

    private function inventory(User $employee, bool $lock = false): array
    {
        $records = [];
        $queries = $this->organization->activeQueries($employee->id);
        foreach (GroupRecordTransfer::MODELS as $type => $class) {
            $table = (new $class)->getTable();
            $query = $queries[$table] ?? null;
            if (! $query) {
                continue;
            }
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                $records[] = ['type' => $type, 'id' => (int) $row->id, 'table' => $table,
                    'title' => $this->recordTitle($type, $row),
                    'branch_group_id' => $row->branch_group_id ?? null,
                    'branch_id' => $row->branch_id ?? (! empty($row->branch_group_id)
                        ? BranchGroup::whereKey($row->branch_group_id)->value('branch_id') : $employee->branch_id),
                    'attributes' => (array) $row];
            }
        }

        return $records;
    }

    private function recordTitle(string $type, object $row): string
    {
        $title = trim((string) ($row->title ?? $row->full_name ?? ''));
        if ($title !== '') return $title;
        if ($type !== 'properties') return '#'.$row->id;

        return implode(' · ', array_filter([
            ! empty($row->rooms) ? $row->rooms.' комн.' : null,
            $row->address ?? null,
            ! empty($row->total_area) ? $row->total_area.' м²' : null,
        ])) ?: 'Объявление #'.$row->id;
    }

    private function revision(User $employee, array $records): string
    {
        return hash('sha256', json_encode([$employee->only(['id', 'status', 'role_id', 'branch_id', 'branch_group_id', 'access_scope_version']), $records], JSON_THROW_ON_ERROR));
    }

    private function recipients(User $actor, User $employee)
    {
        return User::query()->with(['role', 'branch', 'branchGroup'])->whereKeyNot($employee->id)->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['agent', 'mop']))
            ->when($actor->hasRole('rop'), fn ($q) => app(RopGroupAccess::class)->scope($q, $actor, 'users.branch_group_id', 'users.branch_id'))
            ->when($actor->hasRole('branch_director'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->orderBy('id')->get()->reject(fn (User $user) => $user->isDeletedAccount());
    }

    private function parentReference(array $record): ?array
    {
        $attributes = $record['attributes'];
        if ($record['type'] === 'tasks') {
            $type = match ($attributes['related_entity_type'] ?? null) {
                'property', 'ad' => 'properties', 'client' => 'clients', 'deal' => 'deals',
                'lead' => 'leads', 'showing' => 'bookings', default => null,
            };
            return $type && ! empty($attributes['related_entity_id']) ? ['type' => $type, 'id' => $attributes['related_entity_id']] : null;
        }
        if ($record['type'] === 'deals' && ! empty($attributes['primary_property_id'])) {
            $control = ($attributes['control_kind'] ?? null) === 'security_property_closure';
            if (! $control && ! empty($attributes['pipeline_id'])) {
                $pipeline = \App\Models\DealPipeline::find($attributes['pipeline_id']);
                $control = $pipeline?->isPropertyControl() ?? false;
            }
            if ($control) return ['type' => 'properties', 'id' => $attributes['primary_property_id']];
        }
        return null;
    }

    private function eligible(User $actor, User $target, array $record): bool
    {
        if ($actor->hasRole('rop') && ! app(RopGroupAccess::class)->allows($actor, $target)) {
            return false;
        }

        if (empty($record['linked_task_fixed_group']) && $target->branch_group_id
            && (in_array($actor->role?->slug, ['admin', 'superadmin', 'branch_director'], true)
                || ($record['type'] !== 'properties' && $actor->hasRole('rop')))
            && (! $actor->hasRole('branch_director') || (int) $actor->branch_id === (int) $target->branch_id)) {
            return $record['type'] !== 'deals' || $this->dealDestination($record, $target) !== null;
        }

        return (int) $target->branch_group_id === (int) $record['branch_group_id']
            && (int) $target->branch_id === (int) $record['branch_id'];
    }

    private function dealDestination(array $record, User $target): ?array
    {
        if ((int) $record['branch_id'] === (int) $target->branch_id) return [];
        $stageId = $record['attributes']['stage_id'] ?? null;
        if (! $stageId) return null;
        $source = DealStage::with('pipeline')->find($stageId);
        if (! $source?->pipeline) return null;
        $matches = DealStage::query()->where('is_active', true)
            ->where('slug', $source->slug)->where('is_closed', $source->is_closed)->where('is_lost', $source->is_lost)
            ->whereHas('pipeline', fn ($q) => $q->where('branch_id', $target->branch_id)->where('is_active', true)
                ->where('slug', $source->pipeline->slug)->where('type', $source->pipeline->type))
            ->limit(2)->get();
        if ($matches->count() !== 1) return null;
        return ['pipeline_id' => $matches[0]->pipeline_id, 'stage_id' => $matches[0]->id];
    }

    private function ensureRecordScope(User $actor, array $records): void
    {
        if ($actor->hasRole('rop')) {
            $access = app(RopGroupAccess::class);
            foreach ($records as $record) {
                abort_unless($actor->branch_id && (int) $record['branch_id'] === (int) $actor->branch_id
                    && $access->allowsGroup($actor, $record['branch_group_id']), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            }
        }

        if ($actor->hasRole('branch_director')) {
            foreach ($records as $record) {
                abort_unless($actor->branch_id && (int) $record['branch_id'] === (int) $actor->branch_id, 403, 'FORBIDDEN_ACTION');
            }
        }
    }

    public function preview(User $actor, User $employee): array
    {
        $records = $this->inventory($employee);
        $this->ensureRecordScope($actor, $records);
        $recipients = $this->recipients($actor, $employee);
        $parents = collect($records)->keyBy(fn ($r) => $r['type'].':'.$r['id']);
        $groups = BranchGroup::whereIn('id', array_filter(array_column($records, 'branch_group_id')))->pluck('name', 'id');

        $loads = [];
        if (Schema::hasTable('properties')) {
            $loads = DB::table('properties')->where('moderation_status', 'approved')
                ->whereIn('agent_id', $recipients->pluck('id'))->groupBy('agent_id')
                ->selectRaw('agent_id, COUNT(*) as total')->pluck('total', 'agent_id')->all();
        }

        return ['revision' => $this->revision($employee, $records),
            'records' => array_map(function ($r) use ($actor, $recipients, $parents, $groups) {
                $reference = $this->parentReference($r);
                $parentType = $reference['type'] ?? null;
                $parent = $reference ? $parents->get($reference['type'].':'.$reference['id']) : null;
                $scope = $parent ?? $r;
                if ($reference && ! $parent) $scope['linked_task_fixed_group'] = true;
                $eligible = $recipients->filter(fn ($u) => $this->eligible($actor, $u, $scope))->pluck('id')->values()->all();
                $coOwner = (int) ($r['attributes']['co_owner_user_id'] ?? 0);
                return [...array_intersect_key($r, array_flip(['type', 'id', 'title', 'branch_group_id', 'branch_id'])),
                    'group_name' => $groups->get($r['branch_group_id']),
                    'eligible_user_ids' => $eligible,
                    'follows_record' => $parent ? ['type' => $parent['type'], 'id' => $parent['id']] : null,
                    'follows_property_id' => $parent && $parentType === 'properties' ? $parent['id'] : null,
                    'follows_client_id' => $parent && $parentType === 'clients' ? $parent['id'] : null,
                    'preferred_user_id' => $r['type'] === 'properties' && in_array($coOwner, $eligible, true) ? $coOwner : null];
            }, $records),
            'recipients' => $recipients->map(fn ($u) => [...$u->only(['id', 'name', 'branch_id', 'branch_group_id']),
                'branch_name' => $u->branch?->name,
                'group_name' => $u->branchGroup?->name,
                'approved_properties_count' => (int) ($loads[$u->id] ?? 0),
            ])->values()->all()];
    }

    /** Called inside the dismissal transaction after locking every participating user. */
    public function apply(User $actor, User $employee, array $plan, \Illuminate\Support\Collection $lockedUsers): array
    {
        $groupIds = [...array_column($this->inventory($employee), 'branch_group_id'), ...$lockedUsers->pluck('branch_group_id')->all()];
        BranchGroup::query()->whereIn('id', array_filter($groupIds))->orderBy('id')->lockForUpdate()->get();
        $inventory = $this->inventory($employee, true);
        $this->ensureRecordScope($actor, $inventory);
        abort_unless(hash_equals($this->revision($employee, $inventory), $plan['revision']), 409, 'DISMISSAL_VERSION_CONFLICT');
        $assignments = [];
        $destinations = [];
        foreach ($plan['records'] as $item) {
            $key = $item['type'].':'.$item['id'];
            abort_if(isset($assignments[$key]), 422, 'DUPLICATE_TRANSFER_RECORD');
            $assignments[$key] = $item['responsible_user_id'];
            $destinations[$key] = $item['destination_branch_group_id'] ?? null;
        }
        $expected = array_map(fn ($r) => $r['type'].':'.$r['id'], $inventory);
        $actual = array_keys($assignments);
        sort($expected);
        sort($actual);
        abort_unless($actual === $expected, 422, 'COMPLETE_TRANSFER_PLAN_REQUIRED');
        $targets = $lockedUsers->filter(fn (User $target) => $target->id !== $employee->id
            && $target->status === User::STATUS_ACTIVE && ! $target->isDeletedAccount()
            && in_array($target->role?->slug, ['agent', 'mop'], true));
        $counts = array_fill_keys(array_keys(GroupRecordTransfer::MODELS), 0);
        foreach ($inventory as $record) {
            $reference = $this->parentReference($record);
            if ($record['type'] === 'deals' && $reference) {
                $fresh = DB::table($record['table'])->where('id', $record['id'])->lockForUpdate()->first();
                $record['attributes'] = (array) $fresh;
                $record['branch_group_id'] = $fresh->branch_group_id;
                $record['branch_id'] = $fresh->branch_id;
            }
            if ($record['type'] === 'tasks') {
                $fresh = DB::table($record['table'])->where('id', $record['id'])->lockForUpdate()->first();
                $record['branch_group_id'] = $fresh->branch_group_id;
                $record['attributes'] = (array) $fresh;
                $record['branch_id'] = $fresh->branch_group_id
                    ? BranchGroup::whereKey($fresh->branch_group_id)->value('branch_id') : $record['branch_id'];
            }
            $target = $targets->get($assignments[$record['type'].':'.$record['id']]);
            if ($record['type'] === 'deals' && $reference) {
                $property = app(GroupRecordTransfer::class)->model('properties', $reference['id'], true);
                abort_unless($target && (int) $target->branch_group_id === (int) $property->branch_group_id,
                    422, 'TASK_MUST_FOLLOW_PARENT_GROUP');
            }
            if ($record['type'] === 'tasks' && ! empty($record['attributes']['related_entity_id'])) {
                $task = app(GroupRecordTransfer::class)->model('tasks', $record['id'], true);
                $parent = app(TaskGroupOwnership::class)->parent($task, true);
                abort_unless($parent && $target && (int) $target->branch_group_id === (int) $parent->branch_group_id,
                    422, 'TASK_MUST_FOLLOW_PARENT_GROUP');
            }
            abort_unless($target && $this->eligible($actor, $target, $record), 422, 'INVALID_TRANSFER_TARGET');
            if (in_array($record['type'], ['properties', 'clients'], true)
                && ((int) $target->branch_group_id !== (int) $record['branch_group_id']
                    || (int) $target->branch_id !== (int) $record['branch_id'])) {
                abort_unless((int) ($destinations[$record['type'].':'.$record['id']] ?? 0) === (int) $target->branch_group_id,
                    422, 'TRANSFER_DESTINATION_CONFIRMATION_REQUIRED');
                $transfer = app(GroupRecordTransfer::class);
                $model = $transfer->model($record['type'], $record['id'], true);
                $transfer->transfer($actor, $record['type'], $record['id'], [
                    'branch_group_id' => $target->branch_group_id, 'responsible_user_id' => $target->id,
                    'revision' => $transfer->revision($model), 'reason' => $plan['reason'],
                ]);
                $counts[$record['type']]++;
                continue;
            }
            $field = GroupOwnedRecordObserver::RESPONSIBLES[$record['table']];
            $changes = [$field => $target->id, 'updated_at' => now()];
            if (! in_array($record['type'], ['properties', 'clients'], true)
                && ((int) $target->branch_group_id !== (int) $record['branch_group_id'] || (int) $target->branch_id !== (int) $record['branch_id'])) {
                $destination = $destinations[$record['type'].':'.$record['id']] ?? null;
                abort_if($destination !== null && (int) $destination !== (int) $target->branch_group_id, 422, 'INVALID_TRANSFER_TARGET');
                $changes['branch_group_id'] = $target->branch_group_id;
                if (array_key_exists('branch_id', $record['attributes'])) $changes['branch_id'] = $target->branch_id;
                if ($record['type'] === 'deals') {
                    $stage = $this->dealDestination($record, $target);
                    abort_if($stage === null, 422, 'TRANSFER_PIPELINE_REQUIRED');
                    $changes = [...$changes, ...$stage];
                }
            }
            if ($record['type'] === 'properties') {
                if (in_array((int) ($record['attributes']['co_owner_user_id'] ?? 0), [$employee->id, $target->id], true)) {
                    $changes['co_owner_user_id'] = null;
                }
                if (array_key_exists('moderation_version', $record['attributes'])) {
                    $changes['moderation_version'] = (int) $record['attributes']['moderation_version'] + 1;
                }
            }
            DB::table($record['table'])->where('id', $record['id'])->update($changes);
            if (isset($changes['branch_group_id']) && $record['type'] !== 'tasks' && Schema::hasTable('crm_tasks')) {
                $relatedType = match ($record['type']) { 'bookings' => 'showing', default => rtrim($record['type'], 's') };
                $tasks = app(TaskGroupOwnership::class)->active(DB::table('crm_tasks'))
                    ->where('related_entity_type', $relatedType)->where('related_entity_id', $record['id'])->lockForUpdate()->get();
                foreach ($tasks as $task) {
                    $taskChanges = ['branch_group_id' => $target->branch_group_id, 'assignee_id' => $target->id, 'updated_at' => now()];
                    DB::table('crm_tasks')->where('id', $task->id)->update($taskChanges);
                    $this->audit->record($actor, 'employee_dismissal_record_transferred', 'crm_tasks', $task->id,
                        ['branch_group_id' => $task->branch_group_id, 'assignee_id' => $task->assignee_id], $taskChanges, $plan['reason']);
                }
            }
            if ($record['type'] === 'clients' && Schema::hasTable('client_needs')) {
                DB::table('client_needs')->where('client_id', $record['id'])->update(['responsible_agent_id' => $target->id, 'updated_at' => now()]);
            }
            $this->audit->record($actor, 'employee_dismissal_record_transferred', $record['table'], $record['id'],
                array_intersect_key($record['attributes'], $changes), [...$changes, 'dismissed_user_id' => $employee->id], $plan['reason']);
            $counts[$record['type']]++;
        }
        abort_if($this->organization->hasActiveRecords($employee->id, lock: true), 409, 'DISMISSAL_VERSION_CONFLICT');

        return $counts;
    }
}
