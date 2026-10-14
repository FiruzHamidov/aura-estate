<?php

namespace App\Services\GroupAccess;

use App\Models\{Booking, BranchGroup, Client, CrmTask, Deal, DealStage, Lead, Property, User};
use App\Observers\GroupOwnedRecordObserver;
use App\Services\PropertyModeration\{PropertyModerationAccess, PropertyModerationService};
use App\Support\{ClientAccess, DealAccess, LeadAccess, RopGroupAccess};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GroupRecordTransfer
{
    public const MODELS = ['properties' => Property::class, 'clients' => Client::class, 'leads' => Lead::class,
        'deals' => Deal::class, 'bookings' => Booking::class, 'tasks' => CrmTask::class];

    public function __construct(private readonly RopGroupAccess $access, private readonly GroupAccessAudit $audit) {}

    public function model(string $type, int $id, bool $lock = false): Model
    {
        abort_unless(isset(self::MODELS[$type]), 404, 'NOT_FOUND');

        return self::MODELS[$type]::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function authorize(User $actor, Model $record, bool $employeeTransfer = false, ?int $ungroupedEmployeeId = null): void
    {
        if ($employeeTransfer && $ungroupedEmployeeId !== null) {
            abort_unless($actor->usesAssignedGroupScope() && $actor->hasPermission('users.ungrouped.view')
                && $actor->hasPermission('users.ungrouped.assign'), 403, 'FORBIDDEN_ACTION');
            $attributes = $record->getAttributes();
            $responsible = GroupOwnedRecordObserver::RESPONSIBLES[$record->getTable()];
            abort_unless((int) ($attributes[$responsible] ?? 0) === $ungroupedEmployeeId, 403, 'FORBIDDEN_ACTION');
            // Existing classified records must already belong to this manager's scope.
            if (($attributes['branch_group_id'] ?? null) !== null) $this->access->ensureVisible($actor, $record);
            abort_unless($actor->branch_id && (($attributes['branch_id'] ?? null) === null
                || (int) $attributes['branch_id'] === (int) $actor->branch_id), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            if ($record instanceof Deal) abort_if($record->isPropertyControl() || $record->pipeline?->isHrRecruitment(), 403, 'FORBIDDEN_ACTION');
            return;
        }
        abort_unless($actor->hasPermission('records.transfer'), 403, 'FORBIDDEN_ACTION');
        $this->access->ensureVisible($actor, $record);
        if (!$actor->hasPermission('records.transfer.all_branches')) {
            $branchId = $record->getAttributes()['branch_id'] ?? DB::table('branch_groups')->where('id', $record->getAttributes()['branch_group_id'] ?? 0)->value('branch_id');
            abort_unless($actor->branch_id && (int) $branchId === (int) $actor->branch_id, 404, 'NOT_FOUND');
        }
        if ($record instanceof Property) {
            abort_unless(app(PropertyModerationAccess::class)->canModerate($actor, $record), 403, 'FORBIDDEN_ACTION');
        } elseif ($record instanceof Deal) {
            // Control cards inherit their property, and recruitment has no sales group.
            abort_if($record->isPropertyControl() || $record->pipeline?->isHrRecruitment(), 403, 'FORBIDDEN_ACTION');
            app(DealAccess::class)->ensureVisible($actor, $record);
            app(DealAccess::class)->ensureCanUpdate($actor, $record);
        } elseif ($record instanceof Client) {
            app(ClientAccess::class)->ensureVisible($actor, $record);
            app(ClientAccess::class)->ensureCanMutateClients($actor);
        } elseif ($record instanceof Lead) {
            app(LeadAccess::class)->ensureVisible($actor, $record);
        } elseif ($record instanceof Booking && $actor->usesAssignedGroupScope() && ! $employeeTransfer) {
            abort(403, 'FORBIDDEN_ACTION');
        }
    }

    public function revision(Model $record): string
    {
        $attributes = $record->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor, string $type, int $id): array
    {
        $record = $this->model($type, $id);
        $this->authorize($actor, $record);

        return ['type' => $type, 'id' => $id, 'branch_group_id' => $record->getAttributes()['branch_group_id'] ?? null,
            'responsible_user_id' => $record->getAttribute(GroupOwnedRecordObserver::RESPONSIBLES[$record->getTable()]),
            'revision' => $this->revision($record),
            ...($record instanceof Deal ? ['pipeline_id' => $record->pipeline_id, 'stage_id' => $record->stage_id,
                'stage_is_closed' => (bool) $record->stage?->is_closed] : [])];
    }

    public function transfer(User $actor, string $type, int $id, array $data, bool $employeeTransfer = false, ?int $ungroupedEmployeeId = null): Model
    {
        return DB::transaction(function () use ($actor, $type, $id, $data, $employeeTransfer, $ungroupedEmployeeId) {
            $users = User::query()->whereIn('id', [$actor->id, $data['responsible_user_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $target = $users->get((int) $data['responsible_user_id']);
            abort_unless($actor && $target, 422, 'INVALID_TRANSFER_TARGET');
            $group = BranchGroup::query()->lockForUpdate()->findOrFail($data['branch_group_id']);
            $record = $this->model($type, $id, true);
            // Related tasks may already have followed their parent in this same employee transaction.
            $taskAlreadyMoved = $employeeTransfer && $record instanceof CrmTask
                && (int) $record->branch_group_id === (int) $group->id
                && (int) $record->assignee_id === (int) $target->id;
            if (! $taskAlreadyMoved) $this->authorize($actor, $record, $employeeTransfer, $ungroupedEmployeeId);
            abort_unless(hash_equals($this->revision($record), $data['revision']), 409, 'RECORD_VERSION_CONFLICT');
            if ($actor->usesAssignedGroupScope()) {
                if ($ungroupedEmployeeId !== null) {
                    $this->access->ensureGroup($actor, $group->id);
                } elseif ($employeeTransfer && (int) $record->getAttribute(GroupOwnedRecordObserver::RESPONSIBLES[$record->getTable()]) === (int) $target->id) {
                    abort_unless(app(EmployeeGroupTransfer::class)->destinationQuery($actor)->whereKey($group->id)->exists(), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
                } else $this->access->ensureGroup($actor, $group->id);
            }
            if (!$actor->hasPermission('records.transfer.all_branches')) abort_unless((int) $actor->branch_id === (int) $group->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            abort_unless($target->status === User::STATUS_ACTIVE && ! $target->isDeletedAccount()
                && $target->hasPermission($employeeTransfer ? 'users.subject.group_transfer' : 'users.subject.record_recipient')
                && (int) $target->branch_group_id === (int) $group->id
                && (int) $target->branch_id === (int) $group->branch_id, 422, 'INVALID_TRANSFER_TARGET');

            if ($record instanceof CrmTask && $record->related_entity_id) {
                $parent = app(TaskGroupOwnership::class)->parent($record, true);
                if ($parent) {
                    abort_unless((int) ($parent->getAttributes()['branch_group_id'] ?? 0) === (int) $group->id, 422, 'TASK_MUST_FOLLOW_PARENT_GROUP');
                }
            }

            $field = GroupOwnedRecordObserver::RESPONSIBLES[$record->getTable()];
            $changes = ['branch_group_id' => $group->id, $field => $target->id, 'updated_at' => now()];
            if (array_key_exists('branch_id', $record->getAttributes())) $changes['branch_id'] = $group->branch_id;
            if ($record instanceof Deal) {
                $stage = DealStage::query()->with('pipeline')->findOrFail($data['stage_id'] ?? $record->stage_id);
                abort_unless($stage->is_active && $stage->pipeline?->is_active
                    && ! $stage->pipeline->isHrRecruitment() && ! $stage->pipeline->isPropertyControl()
                    && ($stage->pipeline->branch_id === null || (int) $stage->pipeline->branch_id === (int) $group->branch_id)
                    && (int) $stage->pipeline_id === (int) ($data['pipeline_id'] ?? $record->pipeline_id), 422, 'TRANSFER_PIPELINE_REQUIRED');
                // A group transfer does not close or reopen a deal through a different stage.
                abort_unless((bool) $stage->is_closed === (bool) $record->stage?->is_closed, 422, 'TRANSFER_STAGE_STATE_MISMATCH');
                $changes['pipeline_id'] = $stage->pipeline_id;
                $changes['stage_id'] = $stage->id;
            }
            $before = array_intersect_key($record->getAttributes(), $changes);
            if ($record instanceof Property) {
                DB::table('properties')->where('id', $record->id)->update(['branch_group_id' => $group->id, 'branch_id' => $group->branch_id]);
                $ownership = ['agent_id' => $target->id];
                if ((int) $record->co_owner_user_id === (int) $target->id) $ownership['co_owner_user_id'] = null;
                if ($employeeTransfer && (int) $record->agent_id === (int) $target->id) {
                    // Ownership is unchanged; only the employee's organization moves.
                    DB::table('properties')->where('id', $record->id)->update(['updated_at' => now(), 'moderation_version' => (int) $record->moderation_version + 1]);
                    $record = $record->fresh();
                } else {
                    $record = app(PropertyModerationService::class)->transfer($record->fresh(), $actor, $ownership, $data['reason'], (int) $record->moderation_version);
                }
                // System control cards inherit their property's group, while keeping the SB assignee.
                $controls = Deal::query()->where('primary_property_id', $record->id)->where('control_kind', 'security_property_closure')
                    ->whereNull('closed_at')->with('stage')->lockForUpdate()->get();
                foreach ($controls as $control) {
                    $pipeline = app(\App\Services\Crm\PropertyControlService::class)->ensurePipeline($group->branch_id);
                    $stage = $pipeline->stages()->where('slug', $control->stage?->slug)->firstOrFail();
                    DB::table('crm_deals')->where('id', $control->id)->update([
                        'branch_group_id' => $group->id, 'branch_id' => $group->branch_id,
                        'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'updated_at' => now(),
                    ]);
                }
            } else {
                DB::table($record->getTable())->where('id', $record->id)->update($changes);
                if ($record instanceof Lead && (int) ($before['responsible_agent_id'] ?? 0) !== (int) $target->id) {
                    app(\App\Services\Marketing\LeadReceiptTracker::class)->reassigned($record->fresh(), true);
                }
            }
            if ($record instanceof Client && Schema::hasTable('client_needs')) {
                DB::table('client_needs')->where('client_id', $record->id)->update(['responsible_agent_id' => $target->id, 'updated_at' => now()]);
            }
            $relatedTypes = match ($type) { 'properties' => ['property', 'ad'], 'bookings' => ['showing'], 'tasks' => [], default => [rtrim($type, 's')] };
            if ($relatedTypes !== [] && Schema::hasTable('crm_tasks')) {
                $tasks = app(TaskGroupOwnership::class)->active(DB::table('crm_tasks')
                    ->whereIn('related_entity_type', $relatedTypes)->where('related_entity_id', $record->id))
                    ->orderBy('id')->lockForUpdate()->get();
                $taskChanges = ['branch_group_id' => $group->id, 'assignee_id' => $target->id];
                foreach ($tasks as $task) {
                    if ($ungroupedEmployeeId !== null) {
                        abort_unless($task->branch_group_id === null || $this->access->allowsGroup($actor, (int) $task->branch_group_id), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
                        abort_unless($task->assignee_id === null || (int) $task->assignee_id === $ungroupedEmployeeId, 403, 'FORBIDDEN_ACTION');
                    }
                    $taskBefore = array_intersect_key((array) $task, $taskChanges);
                    if ((int) $task->branch_group_id === (int) $group->id && (int) $task->assignee_id === (int) $target->id) {
                        continue;
                    }
                    DB::table('crm_tasks')->where('id', $task->id)->update([...$taskChanges, 'updated_at' => now()]);
                    $this->audit->record($actor, 'record_group_transferred', 'crm_tasks', $task->id,
                        $taskBefore, $taskChanges, $data['reason']);
                }
            }
            $this->audit->record($actor, 'record_group_transferred', $record->getTable(), $record->id, $before, $changes, $data['reason']);

            return $record->fresh();
        });
    }
}
