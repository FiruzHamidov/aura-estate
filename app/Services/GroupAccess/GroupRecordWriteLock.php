<?php

namespace App\Services\GroupAccess;

use App\Models\{BranchGroup, User};
use App\Observers\GroupOwnedRecordObserver;
use Illuminate\Database\Eloquent\Model;

/** Acquire inside the caller's transaction, before authorization and normalization. */
final class GroupRecordWriteLock
{
    public function acquire(User $actor, ?Model $record, ?int $responsibleId, ?int $groupId): array
    {
        [$actor, $records] = $this->acquireMany($actor, $record ? [$record] : [], [$responsibleId], [$groupId]);

        return [$actor, $records[0] ?? null];
    }

    private function ownerField(Model $record): string
    {
        // Creator locks serialize organizational edits; they do not grant access to a Reel.
        return match (true) {
            $record instanceof \App\Models\Reel, $record instanceof \App\Models\KpiRopPlan => 'created_by',
            $record instanceof \App\Models\DailyReport, $record instanceof \App\Models\KpiEarlyRiskAlert => 'user_id',
            default => GroupOwnedRecordObserver::RESPONSIBLES[$record->getTable()],
        };
    }

    /** Lock users, then groups, then roots in a stable order; retain the input keys. */
    public function acquireMany(User $actor, array $records, array $responsibleIds = [], array $groupIds = []): array
    {
        foreach ($records as $record) {
            $field = $this->ownerField($record);
            $responsibleIds[] = $record->getRawOriginal($field);
            $groupIds[] = $record->getRawOriginal('branch_group_id');
        }
        $users = User::query()->whereIn('id', array_filter([$actor->id, ...$responsibleIds]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actor->id);
        abort_unless($actor, 404, 'NOT_FOUND');
        if ((int) auth()->id() === (int) $actor->id) auth()->setUser($actor);
        $groupIds = array_filter([...$groupIds, ...$users->pluck('branch_group_id')->all()]);
        if ($groupIds !== []) {
            BranchGroup::query()->whereIn('id', $groupIds)->orderBy('id')->lockForUpdate()->get();
        }
        $ordered = $records;
        uasort($ordered, fn (Model $a, Model $b) => [$a->getTable(), $a->getKey()] <=> [$b->getTable(), $b->getKey()]);
        foreach ($ordered as $key => $original) {
            $field = $this->ownerField($original);
            $fresh = $original->newQuery()->lockForUpdate()->findOrFail($original->getKey());
            abort_unless((string) $fresh->getRawOriginal($field) === (string) $original->getRawOriginal($field)
                && (string) $fresh->getRawOriginal('branch_group_id') === (string) $original->getRawOriginal('branch_group_id'),
                409, 'RECORD_OWNERSHIP_CHANGED');
            $records[$key] = $fresh;
        }

        return [$actor, $records];
    }
}
