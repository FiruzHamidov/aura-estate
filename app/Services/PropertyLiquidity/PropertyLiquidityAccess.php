<?php

namespace App\Services\PropertyLiquidity;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PropertyLiquidityAccess
{
    public const INTERNAL_ROLES = [
        'agent', 'intern', 'mop', 'rop', 'branch_director',
        'marketing', 'reels_manager', 'admin', 'superadmin',
    ];

    public const MARKETING_WRITE_ROLES = ['marketing', 'reels_manager', 'admin', 'superadmin'];

    public function isInternal(?User $user): bool
    {
        return in_array($this->role($user), self::INTERNAL_ROLES, true);
    }

    public function canManagePromotion(?User $user): bool
    {
        return in_array($this->role($user), self::MARKETING_WRITE_ROLES, true);
    }

    public function canSetBusinessPriority(?User $user): bool
    {
        return in_array($this->role($user), ['mop', 'rop', 'branch_director', 'admin', 'superadmin'], true);
    }

    public function scope(Builder $query, User $user): Builder
    {
        $role = $this->role($user);

        if (in_array($role, ['admin', 'superadmin', 'marketing', 'reels_manager'], true)) {
            return $query;
        }

        if (in_array($role, ['agent', 'intern'], true)) {
            return $query->where(function (Builder $scope) use ($user) {
                $scope->where('properties.agent_id', $user->id)
                    ->orWhere('properties.created_by', $user->id)
                    ->orWhere('properties.co_owner_user_id', $user->id);
            });
        }

        if ($role === 'mop') {
            return $user->branch_group_id
                ? $query->where('properties.branch_group_id', $user->branch_group_id)
                : $query->whereRaw('1 = 0');
        }

        if (in_array($role, ['rop', 'branch_director'], true)) {
            if (! $user->branch_id) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $scope) use ($user) {
                $scope->where('properties.branch_id', $user->branch_id)
                    ->orWhereHas('agent', fn (Builder $agents) => $agents->where('branch_id', $user->branch_id))
                    ->orWhereHas('creator', fn (Builder $creators) => $creators->where('branch_id', $user->branch_id));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function canView(User $user, Property $property): bool
    {
        return $this->scope(Property::query(), $user)->whereKey($property->id)->exists();
    }

    private function role(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }
}
