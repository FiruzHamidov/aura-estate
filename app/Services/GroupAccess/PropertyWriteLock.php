<?php

namespace App\Services\GroupAccess;

use App\Models\Property;
use App\Models\User;
use App\Support\RopGroupAccess;

final class PropertyWriteLock
{
    /** Lock all participants before roots, including both sides of a duplicate decision. */
    public function acquire(User $actor, Property|int $property, array $responsibleIds = [], array $relatedPropertyIds = []): array
    {
        $root = $property instanceof Property ? $property : Property::findOrFail($property);
        $records = [$root->id => $root];
        foreach (array_unique(array_filter($relatedPropertyIds)) as $id) {
            if (! isset($records[$id])) $records[$id] = Property::findOrFail($id);
        }
        [$actor, $records] = app(GroupRecordWriteLock::class)->acquireMany($actor, $records, $responsibleIds);
        foreach ($records as $record) app(RopGroupAccess::class)->ensureVisible($actor, $record);

        return [$actor, $records[$root->id]];
    }
}
