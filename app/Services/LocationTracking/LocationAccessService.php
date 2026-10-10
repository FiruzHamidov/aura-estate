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
        if (! $user->hasPermission('locations.transmit')) {
            $this->deny(self::FORBIDDEN_ROLE, 'Вашей роли не разрешена передача местоположения.');
        }
    }

    public function assertCanViewModule(User $user): void
    {
        if (! $user->hasPermission('locations.view')) {
            $this->deny(self::FORBIDDEN_ROLE, 'Нет доступа к модулю местоположения.');
        }
    }

    public function availableUsersQuery(User $viewer): Builder
    {
        $this->assertCanViewModule($viewer);
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

        if ($viewer->hasRole('rop')) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $viewer, 'users.branch_group_id', 'users.branch_id');
            $query->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop']));
        }

        return match ($this->scope($viewer)) {
            'own' => $query->whereKey($viewer->id),
            'group' => $query->where(function (Builder $q) use ($viewer) {
                $q->whereKey($viewer->id);
                if ($viewer->branch_group_id !== null) {
                    $q->orWhere(function (Builder $scope) use ($viewer) {
                        $scope->where('branch_group_id', $viewer->branch_group_id)
                            ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'agent'));
                    });
                }
            }),
            'branch' => $viewer->branch_id === null
                ? $query->whereKey($viewer->id)
                : $query->where('branch_id', $viewer->branch_id)
                    ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', ['agent', 'mop'])),
            'all' => $query->where(function (Builder $scope) {
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

    public function assertCanConfigure(User $actor, User $target, bool $write = true): void
    {
        if (! $actor->hasPermission($write ? 'locations.settings.update' : 'locations.settings.view')) {
            $this->deny(self::FORBIDDEN_SCOPE, 'Нет доступа к настройкам отслеживания пользователя.');
        }
        if ($actor->hasRole('rop')) {
            app(\App\Support\RopGroupAccess::class)->ensureEmployee($actor, $target->id);
        }
        if ($actor->hasPermission('locations.settings.scope.all')) return;
        if ($actor->hasPermission('locations.settings.scope.branch')
            && $actor->branch_id !== null
            && (int) $actor->branch_id === (int) $target->branch_id
            && $target->hasPermission('locations.transmit')) return;

        $this->deny(self::FORBIDDEN_SCOPE, 'Нет права изменять настройки отслеживания пользователя.');
    }

    public function scope(User $viewer): ?string
    {
        foreach (['all', 'branch', 'group', 'own'] as $scope) {
            if ($viewer->hasPermission('locations.scope.'.$scope)) return $scope;
        }
        return null;
    }

    public function applyHistoryScope(Builder $query, User $viewer, User $target): Builder
    {
        if ($viewer->hasRole('rop')) {
            $this->assertCanViewModule($viewer);
            $groups = app(\App\Support\RopGroupAccess::class);
            $historical = $groups->scope(\App\Models\UserLocationPoint::query()->where('user_id', $target->id), $viewer, 'branch_group_id', 'branch_id');
            $this->scopeHistoryRows($historical, $viewer, $target);
            abort_unless($this->canView($viewer, $target) || $historical->exists(), 404, 'NOT_FOUND');
            $groups->scope($query, $viewer, 'branch_group_id', 'branch_id');
        } else {
            $this->assertCanView($viewer, $target);
        }
        return $this->scopeHistoryRows($query, $viewer, $target);
    }

    private function scopeHistoryRows(Builder $query, User $viewer, User $target): Builder
    {
        $scope = $this->scope($viewer);
        if ($scope === null) return $query->whereRaw('1 = 0');
        if ($viewer->is($target) || $scope === 'all') return $query;
        return match ($scope) {
            'group' => $query->where('branch_group_id', $viewer->branch_group_id),
            'branch' => $query->where('branch_id', $viewer->branch_id),
            default => $query->whereRaw('1 = 0'),
        };
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
