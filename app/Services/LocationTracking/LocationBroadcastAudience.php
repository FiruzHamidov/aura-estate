<?php

namespace App\Services\LocationTracking;

use App\Models\User;
use App\Models\UserLocationPoint;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Builder;

/** Resolve recipients when the queued event executes, never when it is enqueued. */
final class LocationBroadcastAudience
{
    public static function channel(int $viewerId, int $version): string
    {
        return 'location.viewer.'.$viewerId.'.scope.'.$version;
    }

    public function channels(array $payload): array
    {
        $point = UserLocationPoint::query()->find($payload['point_id'] ?? 0);
        $target = User::query()->with('role')->find($payload['user_id'] ?? 0);
        if (! $point || ! $target || (int) $point->user_id !== (int) $target->id || $target->status !== User::STATUS_ACTIVE) return [];
        $tracked = in_array($target->role?->slug, ['agent', 'mop'], true);
        $hasCurrent = $tracked || $target->currentLocation()->exists();

        $roleIds = ['all' => [], 'branch' => [], 'group' => [], 'own' => []];
        foreach (\App\Models\Role::query()->get() as $role) {
            if (! $role->hasPermission('locations.view')) continue;
            foreach (array_keys($roleIds) as $scope) {
                if ($role->hasPermission('locations.scope.'.$scope)) {
                    $roleIds[$scope][] = $role->id;
                    break;
                }
            }
        }
        $viewers = User::query()->where('status', User::STATUS_ACTIVE)
            ->where(function (Builder $audience) use ($target, $point, $tracked, $hasCurrent, $roleIds) {
                $audience->whereRaw('1 = 0');
                if ($hasCurrent) $audience->orWhereIn('role_id', $roleIds['all']);
                $audience->orWhere(fn (Builder $self) => $self->whereKey($target->id)
                    ->whereIn('role_id', [...$roleIds['own'], ...$roleIds['group']]));
                if ($target->role?->slug === 'agent' && $target->branch_group_id && (int) $point->branch_group_id === (int) $target->branch_group_id) {
                    $audience->orWhere(fn (Builder $group) => $group->where('branch_group_id', $target->branch_group_id)
                        ->whereIn('role_id', $roleIds['group']));
                }
                if ($target->branch_id === null) {
                    $audience->orWhere(fn (Builder $self) => $self->whereKey($target->id)->whereIn('role_id', $roleIds['branch']));
                }
                if ($tracked && $target->branch_id && (int) $point->branch_id === (int) $target->branch_id) {
                    $audience->orWhere(fn (Builder $branch) => $branch->where('branch_id', $target->branch_id)
                        ->whereIn('role_id', $roleIds['branch']));
                }
            })
            // Assigned groups remain a hard boundary for actual ROP accounts, regardless of grants.
            ->where(function (Builder $boundary) use ($target, $point, $tracked) {
                $boundary->whereHas('role', fn (Builder $role) => $role->where('slug', '!=', 'rop'));
                if (! $tracked || ! $target->branch_id || ! $target->branch_group_id || ! $point->branch_group_id
                    || (int) $point->branch_id !== (int) $target->branch_id) return;
                $boundary->orWhere(function (Builder $rops) use ($target, $point) {
                    $rops->where('users.branch_id', $target->branch_id)
                        ->whereHas('role', fn (Builder $role) => $role->where('slug', 'rop'));
                    foreach (array_unique([$target->branch_group_id, $point->branch_group_id]) as $groupId) {
                        $rops->whereHas('supervisedGroups', fn (Builder $groups) => $groups->where('branch_groups.id', $groupId)
                            ->where('branch_groups.branch_id', $target->branch_id));
                    }
                });
            })->get(['id', 'access_scope_version']);

        return $viewers->map(fn (User $viewer) => new PrivateChannel(self::channel($viewer->id, (int) $viewer->access_scope_version)))->all();
    }
}
