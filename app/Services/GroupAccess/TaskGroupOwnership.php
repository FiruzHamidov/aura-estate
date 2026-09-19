<?php

namespace App\Services\GroupAccess;

use App\Models\{Booking, Client, CrmTask, Deal, Lead, Property, User};
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/** A linked task belongs to its primary card; completed history is not reclassified on save. */
final class TaskGroupOwnership
{
    private const PARENTS = ['property' => Property::class, 'ad' => Property::class,
        'client' => Client::class, 'lead' => Lead::class, 'deal' => Deal::class, 'showing' => Booking::class];

    public function active(Builder $query): Builder
    {
        $query->whereNull('completed_at');
        if (Schema::hasColumn('crm_tasks', 'status')) {
            $query->where(fn (Builder $status) => $status->whereNull('status')->orWhereNotIn('status', ['done', 'canceled']));
        }

        return $query;
    }

    public function parent(CrmTask $task, bool $lock = false): ?Model
    {
        if (! $task->related_entity_type && ! $task->related_entity_id) return null;
        $class = self::PARENTS[$task->related_entity_type] ?? null;
        abort_unless($class && $task->related_entity_id, 422, 'INVALID_TASK_PARENT');

        return $class::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($task->related_entity_id);
    }

    public function isReopening(CrmTask $task): bool
    {
        $closed = ['done', 'canceled'];

        return $task->exists
            && ($task->getRawOriginal('completed_at') !== null || in_array($task->getRawOriginal('status'), $closed, true))
            && ! $task->completed_at && ! in_array($task->status, $closed, true);
    }

    public function validate(CrmTask $task, ?User $actor): void
    {
        $reopening = $this->isReopening($task);
        if ($task->exists && ! $reopening && ! $task->isDirty(['related_entity_type', 'related_entity_id'])) return;
        if ($reopening || (! $task->exists && ! $task->completed_at && ! in_array($task->status, ['done', 'canceled'], true))) {
            $assignee = User::query()->lockForUpdate()->findOrFail($task->assignee_id);
            if ($reopening || in_array($assignee->role?->slug, ['agent', 'mop'], true)) {
                abort_unless($task->branch_group_id && (int) $assignee->branch_group_id === (int) $task->branch_group_id,
                    422, $reopening ? 'TASK_REOPEN_REQUIRES_GROUP_TRANSFER' : 'RESPONSIBLE_GROUP_MISMATCH');
                abort_unless($assignee->status === User::STATUS_ACTIVE && ! $assignee->isDeletedAccount(),
                    422, 'INVALID_TRANSFER_TARGET');
            }
        }
        $parent = $this->parent($task, true);
        if (! $parent) return;
        $access = app(RopGroupAccess::class);
        if ($actor) $access->ensureVisible($actor, $parent);
        $groupId = $parent->getAttributes()['branch_group_id'] ?? null;
        abort_unless($groupId, 422, 'TASK_PARENT_GROUP_REQUIRED');
        if (! $task->branch_group_id && ! $access->applies($actor)) $task->branch_group_id = $groupId;
        abort_unless((int) $task->branch_group_id === (int) $groupId, 422, 'TASK_MUST_FOLLOW_PARENT_GROUP');
    }
}
