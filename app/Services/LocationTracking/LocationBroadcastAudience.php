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

        $viewers = User::query()->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', config('location_tracking.viewer_roles', [])))
            ->where(function (Builder $audience) use ($target, $point, $tracked, $hasCurrent) {
                $audience->whereRaw('1 = 0');
                if ($hasCurrent) {
                    $audience->orWhereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['admin', 'superadmin']));
                }
                $audience->orWhere(fn (Builder $self) => $self->whereKey($target->id)
                    ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop'])));
                if ($target->role?->slug === 'agent' && $target->branch_group_id && (int) $point->branch_group_id === (int) $target->branch_group_id) {
                    $audience->orWhere(fn (Builder $mops) => $mops->where('branch_group_id', $target->branch_group_id)
                        ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'mop')));
                }
                if ($target->branch_id === null && $target->role?->slug === 'branch_director') {
                    $audience->orWhereKey($target->id);
                }
                if (! $tracked || ! $target->branch_id) return;
                if ((int) $point->branch_id === (int) $target->branch_id) {
                    $audience->orWhere(fn (Builder $directors) => $directors->where('branch_id', $target->branch_id)
                        ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'branch_director')));
                }
                if (! $target->branch_group_id || ! $point->branch_group_id || (int) $point->branch_id !== (int) $target->branch_id) return;
                $audience->orWhere(function (Builder $rops) use ($target, $point) {
                    $rops->where('users.branch_id', $target->branch_id)
                        ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'rop'));
                    foreach (array_unique([$target->branch_group_id, $point->branch_group_id]) as $groupId) {
                        $rops->whereHas('supervisedGroups', fn (Builder $groups) => $groups->where('branch_groups.id', $groupId)
                            ->where('branch_groups.branch_id', $target->branch_id));
                    }
                });
            })->get(['id', 'access_scope_version']);

        return $viewers->map(fn (User $viewer) => new PrivateChannel(self::channel($viewer->id, (int) $viewer->access_scope_version)))->all();
    }
}
