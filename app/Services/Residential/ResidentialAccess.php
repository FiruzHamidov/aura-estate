<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ResidentialAccess
{
    public const GLOBAL_ROLES = ['superadmin', 'admin', 'owner'];

    public const BRANCH_ROLES = ['branch_director'];

    public const AUTHOR_ROLES = ['agent', 'mop'];

    public function role(?User $user): ?string
    {
        return $user?->role?->slug;
    }

    public function global(?User $user): bool
    {
        return ($user?->hasPermission('new_buildings.manage_all') ?? false);
    }

    public function canCreate(?User $user): bool
    {
        return $user && $user->status === User::STATUS_ACTIVE && ! $user->isDeletedAccount()
            && $user->hasPermission('new_buildings.manage');
    }

    public function visible(User $user): Builder
    {
        $query = NewBuilding::query();
        if (! $this->canCreate($user)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->global($user)) {
            return $query;
        }
        if (in_array($this->role($user), self::BRANCH_ROLES, true)) {
            return $user->branch_id ? $query->where('branch_id', $user->branch_id) : $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('responsible_agent_id', $user->id));
    }

    public function canManage(User $user, NewBuilding $building): bool
    {
        return $this->visible($user)->whereKey($building->id)->exists();
    }

    public function canPublish(User $user, NewBuilding $building): bool
    {
        return $this->canManage($user, $building) && $user->hasPermission('new_buildings.publish');
    }

    public function ensureManage(?User $user, NewBuilding $building): void
    {
        abort_unless($user && $this->canManage($user, $building), 403, 'Нет доступа к этому ЖК.');
    }

    public function ensurePublish(?User $user, NewBuilding $building): void
    {
        abort_unless($user && $this->canPublish($user, $building), 403, 'Публикация доступна модератору ЖК.');
    }

    public function capabilities(User $user, NewBuilding $building): array
    {
        $manage = $this->canManage($user, $building);
        $publish = $this->canPublish($user, $building);

        return ['edit' => $manage, 'submit' => $manage, 'publish' => $publish, 'archive' => $publish, 'import' => $publish, 'change_availability' => $manage];
    }
}
