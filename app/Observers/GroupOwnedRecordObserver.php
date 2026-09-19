<?php

namespace App\Observers;

use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Persist ownership once; employee moves never silently rewrite record history. */
final class GroupOwnedRecordObserver
{
    public const RESPONSIBLES = [
        'properties' => 'agent_id', 'clients' => 'responsible_agent_id',
        'leads' => 'responsible_agent_id', 'crm_deals' => 'responsible_agent_id',
        'bookings' => 'agent_id', 'crm_tasks' => 'assignee_id',
    ];

    public function saving(Model $record): void
    {
        $table = $record->getTable();
        if (! Schema::hasColumn($table, 'branch_group_id')) {
            return;
        }
        $access = app(RopGroupAccess::class);
        $actor = auth()->user();
        $responsibleField = self::RESPONSIBLES[$table];

        // Recruitment belongs to the shared HR pipeline, not the assignee's sales team.
        if ($record instanceof \App\Models\Deal
            && \App\Models\DealPipeline::query()->find($record->pipeline_id)?->isHrRecruitment()) {
            abort_if($access->applies($actor), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            abort_if($record->branch_group_id, 422, 'HR_GROUP_NOT_ALLOWED');
            $record->branch_id = null;

            return;
        }

        if ($access->applies($actor)) {
            if ($record->exists) {
                $current = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                $access->ensureVisible($actor, $current);
                foreach (['branch_id', 'branch_group_id', 'created_by'] as $field) {
                    if ($record->isDirty($field)) {
                        abort_unless((string) $record->getAttribute($field) === (string) $current->getAttribute($field), 403, 'GROUP_TRANSFER_REQUIRED');
                    }
                }
                // Keep untouched values from the locked row, not a stale route binding.
                foreach (['branch_id', 'branch_group_id'] as $field) {
                    if (array_key_exists($field, $current->getAttributes())) {
                        $record->setAttribute($field, $current->getAttribute($field));
                    }
                }
            } else {
                $record->branch_group_id = $access->creationGroup($actor, ($record->getAttributes()['branch_group_id'] ?? null));
                if (Schema::hasColumn($table, 'branch_id')) {
                    $record->branch_id = $actor->branch_id;
                }
                if (Schema::hasColumn($table, 'created_by')) {
                    $record->created_by = $actor->id;
                }
            }
            $access->ensureVisible($actor, $record);
            if ($record->getAttribute($responsibleField) && (! $record->exists || $record->isDirty($responsibleField))) {
                $access->ensureEmployee($actor, (int) $record->getAttribute($responsibleField), (int) $record->branch_group_id, true);
            }
        } elseif (! $record->exists && empty($record->getAttributes()['branch_group_id'])) {
            $userId = $record->getAttribute($responsibleField) ?: $record->getAttribute('created_by') ?: $record->getAttribute('creator_id');
            $responsible = $userId ? User::find($userId) : null;
            if ($responsible?->branch_group_id && (! $record->branch_id || (int) $record->branch_id === (int) $responsible->branch_id)) {
                $record->branch_group_id = $responsible->branch_group_id;
            }
        }

        if (($record instanceof \App\Models\Client || $record instanceof \App\Models\Lead || $record instanceof \App\Models\Deal)
            && $record->branch_group_id && $record->responsible_agent_id
            && (! $record->exists || $record->isDirty(['responsible_agent_id', 'branch_group_id', 'branch_id']))) {
            $responsible = User::query()->lockForUpdate()->findOrFail($record->responsible_agent_id);
            if (in_array($responsible->role?->slug, ['agent', 'mop'], true)) {
                abort_unless((int) $responsible->branch_group_id === (int) $record->branch_group_id
                    && (int) $responsible->branch_id === (int) $record->branch_id,
                    422, 'RESPONSIBLE_GROUP_MISMATCH');
                abort_unless($responsible->status === User::STATUS_ACTIVE && ! $responsible->isDeletedAccount(),
                    422, 'INVALID_TRANSFER_TARGET');
            }
        }

        if ($record instanceof \App\Models\CrmTask) {
            app(\App\Services\GroupAccess\TaskGroupOwnership::class)->validate($record, $actor);
        }

        if ($record instanceof \App\Models\Booking && $record->branch_group_id
            && (! $record->exists || $record->isDirty(['agent_id', 'branch_group_id']))) {
            $responsible = User::query()->lockForUpdate()->findOrFail($record->agent_id);
            $branchId = DB::table('branch_groups')->where('id', $record->branch_group_id)->value('branch_id');
            abort_unless((int) $responsible->branch_group_id === (int) $record->branch_group_id
                && (int) $responsible->branch_id === (int) $branchId, 422, 'RESPONSIBLE_GROUP_MISMATCH');
            abort_unless($responsible->status === User::STATUS_ACTIVE && ! $responsible->isDeletedAccount(),
                422, 'INVALID_TRANSFER_TARGET');
        }

        if ($record->branch_group_id && (! $record->exists || $record->isDirty(['branch_group_id', 'branch_id']))) {
            $branchId = DB::table('branch_groups')->where('id', $record->branch_group_id)->value('branch_id');
            abort_unless($branchId, 422, 'INVALID_GROUP');
            if (Schema::hasColumn($table, 'branch_id')) {
                abort_if($record->branch_id && (int) $record->branch_id !== (int) $branchId, 422, 'GROUP_BRANCH_MISMATCH');
                $record->branch_id = $branchId;
            }
        }
    }
}
