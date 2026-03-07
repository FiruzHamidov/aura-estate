<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\ClientType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ClientAccess
{
    public const VISIBILITY_SETTING_KEY = 'clients.agent_visibility_mode';
    public const VISIBILITY_ALL_BRANCH = 'all_branch';
    public const VISIBILITY_OWN_ONLY = 'own_only';

    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function isPrivilegedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['superadmin', 'admin'], true);
    }

    public function isBranchScopedManager(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop'], true);
    }

    public function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'agent', 'manager', 'operator'], true);
    }

    public function visibilityMode(): string
    {
        $value = Setting::query()->whereKey(self::VISIBILITY_SETTING_KEY)->value('value');

        return in_array($value, [self::VISIBILITY_ALL_BRANCH, self::VISIBILITY_OWN_ONLY], true)
            ? $value
            : self::VISIBILITY_ALL_BRANCH;
    }

    public function setVisibilityMode(string $value): string
    {
        $normalized = in_array($value, [self::VISIBILITY_ALL_BRANCH, self::VISIBILITY_OWN_ONLY], true)
            ? $value
            : self::VISIBILITY_ALL_BRANCH;

        Setting::updateOrCreate(
            ['key' => self::VISIBILITY_SETTING_KEY],
            ['value' => $normalized]
        );

        return $normalized;
    }

    public function visibleQuery(User $authUser): Builder
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = Client::query()->with(['branch', 'creator', 'responsibleAgent', 'type']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (!$this->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if (
            in_array($roleSlug, ['agent', 'manager', 'operator'], true)
            && $this->visibilityMode() === self::VISIBILITY_OWN_ONLY
        ) {
            $query->where(function (Builder $builder) use ($authUser) {
                $builder
                    ->where('responsible_agent_id', $authUser->id)
                    ->orWhere('created_by', $authUser->id);
            });
        }

        return $query;
    }

    public function ensureVisible(User $authUser, Client $client): void
    {
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($client->id)
            ->exists();

        abort_unless($allowed, 403, 'Forbidden');
    }

    public function ensureNeedVisible(User $authUser, ClientNeed $clientNeed): void
    {
        $clientNeed->loadMissing('client');

        abort_unless($clientNeed->client, 404, 'Client not found.');

        $this->ensureVisible($authUser, $clientNeed->client);
    }

    public function normalizeMutationData(array $data, User $authUser): array
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isBranchScopedRole($roleSlug)) {
            abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');
            $data['branch_id'] = $authUser->branch_id;
        }

        $data['created_by'] ??= $authUser->id;

        if (
            in_array($roleSlug, ['agent', 'manager', 'operator'], true)
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
        }

        if (empty($data['client_type_id'])) {
            $data['client_type_id'] = $this->defaultClientTypeId();
        }

        return $data;
    }

    public function validateMutationTargets(User $authUser, array $data): void
    {
        $roleSlug = $this->roleSlug($authUser);

        if (!$this->isBranchScopedRole($roleSlug)) {
            return;
        }

        $branchId = $data['branch_id'] ?? null;

        if ((int) $branchId !== (int) $authUser->branch_id) {
            abort(422, 'branch_id must match your branch.');
        }

        foreach (['created_by', 'responsible_agent_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }

            $targetUser = User::query()->find($data[$field]);

            if (!$targetUser || (int) $targetUser->branch_id !== (int) $authUser->branch_id) {
                abort(422, sprintf('%s must belong to your branch.', $field));
            }
        }
    }

    public function normalizeNeedMutationData(array $data, User $authUser, Client $client): array
    {
        $data['client_id'] = $client->id;
        $data['created_by'] ??= $authUser->id;
        $data['responsible_agent_id'] ??= $client->responsible_agent_id ?: $authUser->id;

        return $data;
    }

    public function validateNeedMutationTargets(User $authUser, Client $client, array $data): void
    {
        $this->ensureVisible($authUser, $client);

        $roleSlug = $this->roleSlug($authUser);

        if (!$this->isBranchScopedRole($roleSlug)) {
            return;
        }

        foreach (['created_by', 'responsible_agent_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }

            $targetUser = User::query()->find($data[$field]);

            if (!$targetUser || (int) $targetUser->branch_id !== (int) $client->branch_id) {
                abort(422, sprintf('%s must belong to the client branch.', $field));
            }
        }
    }

    public function defaultClientTypeId(): int
    {
        $id = ClientType::query()
            ->where('slug', ClientType::SLUG_INDIVIDUAL)
            ->value('id');

        abort_unless($id, 500, 'Default client type is not configured.');

        return (int) $id;
    }
}
