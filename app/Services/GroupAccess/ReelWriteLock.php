<?php

namespace App\Services\GroupAccess;

use App\Models\{Property, Reel, User};

/** Use inside the mutation transaction; never authorize against an earlier property relation. */
final class ReelWriteLock
{
    public function acquire(User $actor, ?Reel $reel = null, ?int $targetId = null, ?int $groupId = null): array
    {
        $sourceId = $reel?->getRawOriginal('property_id');
        $records = $reel ? ['reel' => $reel] : [];
        if ($sourceId) $records['source'] = Property::query()->findOrFail($sourceId);
        if ($targetId && (int) $targetId !== (int) $sourceId) $records['target'] = Property::query()->findOrFail($targetId);
        [$actor, $locked] = app(GroupRecordWriteLock::class)->acquireMany($actor, $records, [], [$groupId]);
        $fresh = $locked['reel'] ?? null;
        if ($fresh) {
            abort_unless((string) $fresh->property_id === (string) $sourceId, 409, 'REEL_SOURCE_CHANGED');
            $fresh->setRelation('property', $locked['source'] ?? null);
        }
        $target = $targetId ? ($locked['target'] ?? $locked['source'] ?? null) : null;

        return [$actor, $fresh, $target];
    }
}
