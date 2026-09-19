<?php

namespace App\Services\LocationTracking;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LocationAccessService
{
    public const FORBIDDEN_ROLE = 'LOCATION_FORBIDDEN_ROLE';

    public const FORBIDDEN_SCOPE = 'LOCATION_FORBIDDEN_SCOPE';

    public function role(User $user): ?string
    {
        $user->loadMissing('role');

        return $user->role?->slug;
    }

    public function assertCanTransmit(User $user): void
    {
        if (! in_array($this->role($user), config('location_tracking.tracked_roles', []), true)) {
            $this->deny(self::FORBIDDEN_ROLE, 'Передача местоположения доступна только агентам и МОПам.');
        }
    }

    public function assertCanViewModule(User $user): void
    {
        if (! in_array($this->role($user), config('location_tracking.viewer_roles', []), true)) {
            $this->deny(self::FORBIDDEN_ROLE, 'Нет доступа к модулю местоположения.');
        }
    }

    public function availableUsersQuery(User $viewer): Builder
    {
        $this->assertCanViewModule($viewer);
        $role = $this->role($viewer);
        $query = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->with([
                'role',
                'branch',
                'branchGroup',
                'currentLocation' => function ($current) use ($viewer): void {
                    if ($viewer->hasRole('rop')) {
                        $points = app(\App\Support\RopGroupAccess::class)->scope(\App\Models\UserLocationPoint::query(), $viewer, 'branch_group_id', 'branch_id');
                        $current->whereIn('location_point_id', $points->select('id'));
                    }
                },
                'locationTrackingSetting',
                'latestActiveLocationDevice',
            ]);

        return match ($role) {
            'agent' => $query->whereKey($viewer->id),
            'mop' => $query->where(function (Builder $q) use ($viewer) {
                $q->whereKey($viewer->id);
                if ($viewer->branch_group_id !== null) {
                    $q->orWhere(function (Builder $scope) use ($viewer) {
                        $scope->where('branch_group_id', $viewer->branch_group_id)
                            ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'agent'));
                    });
                }
            }),
            'rop' => app(\App\Support\RopGroupAccess::class)->scope($query, $viewer, 'users.branch_group_id', 'users.branch_id')
                ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop'])),
            'branch_director' => $viewer->branch_id === null
                ? $query->whereKey($viewer->id)
                : $query->where('branch_id', $viewer->branch_id)
                    ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop'])),
            'admin', 'superadmin' => $query->where(function (Builder $scope) {
                $scope->whereHas(
                    'role',
                    fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop'])
                )->orWhereHas('currentLocation');
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function assertCanView(User $viewer, User $target): void
    {
        if (! $this->canView($viewer, $target)) {
            $this->deny(self::FORBIDDEN_SCOPE, 'Нет доступа к местоположению пользователя.');
        }
    }

    public function canView(User $viewer, User $target): bool
    {
        try {
            return $this->availableUsersQuery($viewer)->whereKey($target->id)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertCanConfigure(User $actor, User $target): void
    {
        $role = $this->role($actor);

        if (in_array($role, ['admin', 'superadmin'], true)) {
            return;
        }

        if ($role === 'branch_director'
            && $actor->branch_id !== null
            && (int) $actor->branch_id === (int) $target->branch_id
            && in_array($this->role($target), ['agent', 'mop'], true)) {
            return;
        }

        $this->deny(self::FORBIDDEN_SCOPE, 'Нет права изменять настройки отслеживания пользователя.');
    }

    public function applyHistoryScope(Builder $query, User $viewer, User $target): Builder
    {
        if ($viewer->hasRole('rop')) {
            $this->assertCanViewModule($viewer);
            $groups = app(\App\Support\RopGroupAccess::class);
            $historical = $groups->scope(\App\Models\UserLocationPoint::query()->where('user_id', $target->id), $viewer, 'branch_group_id', 'branch_id');
            abort_unless($this->canView($viewer, $target) || $historical->exists(), 404, 'NOT_FOUND');
            return $groups->scope($query, $viewer, 'branch_group_id', 'branch_id');
        }

        $this->assertCanView($viewer, $target);
        $role = $this->role($viewer);

        if ($viewer->is($target) || in_array($role, ['admin', 'superadmin'], true)) {
            return $query;
        }

        if ($role === 'mop') {
            return $query->where('branch_group_id', $viewer->branch_group_id);
        }

        if (in_array($role, ['rop', 'branch_director'], true)) {
            return $query->where('branch_id', $viewer->branch_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function deny(string $code, string $message, int $status = 403): never
    {
        abort(response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) [],
            'trace_id' => request()->attributes->get('trace_id'),
        ], $status));
    }
}
