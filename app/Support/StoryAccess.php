<?php

namespace App\Support;

use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class StoryAccess
{
    public function canEdit(User $actor, Story $story, string $action): bool
    {
        return ! $actor->usesAssignedGroupScope() && $actor->hasPermission('stories.'.$action)
            && ((int) $story->user_id === (int) $actor->id || $actor->hasPermission('stories.manage_all'));
    }

    public function canChangeStatus(User $actor, Story $story): bool
    {
        if ($actor->usesAssignedGroupScope()) return false;
        if ((int) $story->user_id === (int) $actor->id && $actor->hasPermission('stories.status')) return true;

        return $actor->hasPermission('stories.moderation.status') && $this->inModerationScope($actor, $story);
    }

    public function canViewModeration(User $actor): bool
    {
        return ! $actor->usesAssignedGroupScope() && $actor->hasPermission('stories.moderation.view')
            && ($actor->hasPermission('stories.moderation.scope.all')
                || ($actor->branch_id && $actor->hasPermission('stories.moderation.scope.branch'))
                || ($actor->branch_group_id && $actor->hasPermission('stories.moderation.scope.group')));
    }

    public function scopeModeration(Builder $query, User $actor): void
    {
        if ($actor->usesAssignedGroupScope()) {
            $query->whereRaw('1 = 0');
        } elseif ($actor->hasPermission('stories.moderation.scope.all')) {
            return;
        } elseif ($actor->branch_id && $actor->hasPermission('stories.moderation.scope.branch')) {
            $query->whereHas('user', fn (Builder $users) => $users->where('branch_id', $actor->branch_id));
        } elseif ($actor->branch_group_id && $actor->hasPermission('stories.moderation.scope.group')) {
            $query->whereHas('user', fn (Builder $users) => $users->where('branch_group_id', $actor->branch_group_id));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    public function inModerationScope(User $actor, Story $story): bool
    {
        $query = Story::query()->whereKey($story->id);
        $this->scopeModeration($query, $actor);

        return $query->exists();
    }
}
