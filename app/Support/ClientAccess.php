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
    public const AGENT_CAN_VIEW_SELLERS_SETTING_KEY = 'clients.agent_can_view_sellers';
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

    public function isAgentScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['agent', 'manager', 'operator'], true);
    }

    public function visibilityMode(): string
    {
        $value = Setting::query()->whereKey(self::VISIBILITY_SETTING_KEY)->value('value');

        return in_array($value, [self::VISIBILITY_ALL_BRANCH, self::VISIBILITY_OWN_ONLY], true)
            ? $value
            : self::VISIBILITY_ALL_BRANCH;
    }

    public function agentCanViewSellers(): bool
    {
        $value = Setting::query()->whereKey(self::AGENT_CAN_VIEW_SELLERS_SETTING_KEY)->value('value');

        return $this->normalizeBooleanSetting($value, false);
    }

    public function settings(): array
    {
        return [
            'agent_visibility_mode' => $this->visibilityMode(),
            'agent_can_view_sellers' => $this->agentCanViewSellers(),
        ];
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

    public function setAgentCanViewSellers(bool $value): bool
    {
        Setting::updateOrCreate(
            ['key' => self::AGENT_CAN_VIEW_SELLERS_SETTING_KEY],
            ['value' => $value ? '1' : '0']
        );

        return $value;
    }

    public function updateSettings(array $settings): array
    {
        if (array_key_exists('agent_visibility_mode', $settings)) {
            $this->setVisibilityMode((string) $settings['agent_visibility_mode']);
        }

        if (array_key_exists('agent_can_view_sellers', $settings)) {
            $this->setAgentCanViewSellers(
                $this->normalizeBooleanSetting($settings['agent_can_view_sellers'], false)
            );
        }

        return $this->settings();
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

        if (!$this->isAgentScopedRole($roleSlug)) {
            return $query;
        }

        if ($this->visibilityMode() === self::VISIBILITY_OWN_ONLY) {
            return $this->filterSellerVisibilityForAgent(
                $this->restrictToOwnContacts($query, $authUser),
                $authUser,
                true
            );
        }

        return $this->filterSellerVisibilityForAgent($query, $authUser);
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
            $this->isAgentScopedRole($roleSlug)
            && empty($data['responsible_agent_id'])
        ) {
            $data['responsible_agent_id'] = $authUser->id;
        }

        if (empty($data['contact_kind']) || !in_array($data['contact_kind'], Client::contactKinds(), true)) {
            $data['contact_kind'] = Client::CONTACT_KIND_BUYER;
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

    private function restrictToOwnContacts(Builder $query, User $authUser): Builder
    {
        return $query->where(function (Builder $builder) use ($authUser) {
            $this->applyOwnContactConstraint($builder, $authUser);
        });
    }

    private function filterSellerVisibilityForAgent(
        Builder $query,
        User $authUser,
        bool $allVisibleContactsAlreadyOwn = false
    ): Builder {
        if (!$this->agentCanViewSellers()) {
            return $query->where(function (Builder $builder) {
                $this->applyNonSellerConstraint($builder);
            });
        }

        if ($allVisibleContactsAlreadyOwn) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($authUser) {
            $this->applyNonSellerConstraint($builder);

            $builder->orWhere(function (Builder $sellerQuery) use ($authUser) {
                $this->applySellerConstraint($sellerQuery);

                $sellerQuery->where(function (Builder $ownContactQuery) use ($authUser) {
                    $this->applyOwnContactConstraint($ownContactQuery, $authUser);
                });
            });
        });
    }

    private function applyNonSellerConstraint(Builder $builder): void
    {
        $builder
            ->whereNotIn('contact_kind', [
                Client::CONTACT_KIND_SELLER,
                Client::CONTACT_KIND_BOTH,
            ])
            ->orWhereNull('contact_kind');
    }

    private function applySellerConstraint(Builder $builder): void
    {
        $builder->whereIn('contact_kind', [
            Client::CONTACT_KIND_SELLER,
            Client::CONTACT_KIND_BOTH,
        ]);
    }

    private function applyOwnContactConstraint(Builder $builder, User $authUser): void
    {
        $builder
            ->where('responsible_agent_id', $authUser->id)
            ->orWhere('created_by', $authUser->id);
    }

    private function normalizeBooleanSetting(mixed $value, bool $default): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
    }
}
