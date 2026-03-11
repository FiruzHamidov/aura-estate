<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\DealAccess;
use App\Support\LeadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClientAttachService
{
    public const CONTEXT_CLIENT = 'client';
    public const CONTEXT_LEAD = 'lead';
    public const CONTEXT_DEAL = 'deal';
    public const CONTEXT_PROPERTY = 'property';
    public const CONTEXT_CLIENT_NEED = 'client_need';

    public function __construct(
        private readonly ClientAccess $clientAccess,
        private readonly LeadAccess $leadAccess,
        private readonly DealAccess $dealAccess,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public static function contextTypes(): array
    {
        return [
            self::CONTEXT_CLIENT,
            self::CONTEXT_LEAD,
            self::CONTEXT_DEAL,
            self::CONTEXT_PROPERTY,
            self::CONTEXT_CLIENT_NEED,
        ];
    }

    public static function propertyRelations(): array
    {
        return ['owner', 'buyer'];
    }

    public function normalizedContext(array $data): array
    {
        $type = $data['context_type'] ?? null;

        if ($type === null || $type === '') {
            $type = self::CONTEXT_CLIENT;
        }

        return [
            'type' => $type,
            'id' => isset($data['context_id']) ? (int) $data['context_id'] : null,
            'property_relation' => $data['property_relation'] ?? null,
        ];
    }

    public function canUseExistingFlow(User $authUser, array $context): bool
    {
        try {
            $this->resolveContext($authUser, $context, false);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function attachableMatchesQuery(User $authUser, Builder $query, array $context): Builder
    {
        $resolved = $this->resolveContext($authUser, $context, false);

        if (!empty($resolved['branch_id'])) {
            $query->where('clients.branch_id', $resolved['branch_id']);
        } elseif ($context['type'] === self::CONTEXT_CLIENT && !$this->clientAccess->isPrivilegedRole($this->clientAccess->roleSlug($authUser))) {
            $query->where('clients.branch_id', $authUser->branch_id);
        }

        return $query->with(['branch', 'branchGroup', 'responsibleAgent']);
    }

    public function canAttachClient(User $authUser, Client $client, array $context): bool
    {
        try {
            $resolved = $this->resolveContext($authUser, $context, false);
        } catch (\Throwable) {
            return false;
        }

        if (!empty($resolved['branch_id']) && (int) $resolved['branch_id'] !== (int) $client->branch_id) {
            return false;
        }

        if (
            $context['type'] === self::CONTEXT_CLIENT
            && !$this->clientAccess->isPrivilegedRole($this->clientAccess->roleSlug($authUser))
            && (int) $client->branch_id !== (int) $authUser->branch_id
        ) {
            return false;
        }

        return true;
    }

    public function visibleMatchPayload(User $authUser, Client $client, array $context): array
    {
        return [
            'id' => $client->id,
            'full_name' => $client->full_name,
            'masked_name' => null,
            'phone' => $client->phone,
            'email' => $client->email,
            'masked_phone' => null,
            'masked_email' => null,
            'branch' => $this->safeBranchPayload($client),
            'branch_group' => $this->safeBranchGroupPayload($client),
            'responsible_agent' => $this->safeResponsiblePayload($client),
            'limited_visibility' => false,
            'can_attach' => $this->canAttachClient($authUser, $client, $context),
        ];
    }

    public function limitedMatchPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'full_name' => null,
            'masked_name' => $this->maskName($client->full_name),
            'phone' => null,
            'email' => null,
            'masked_phone' => $this->maskPhone($client->phone),
            'masked_email' => $this->maskEmail($client->email),
            'branch' => $this->safeBranchPayload($client),
            'branch_group' => $this->safeBranchGroupPayload($client),
            'responsible_agent' => $this->safeResponsiblePayload($client),
            'limited_visibility' => true,
            'can_attach' => true,
        ];
    }

    public function attach(User $authUser, Client $client, array $context): array
    {
        $resolved = $this->resolveContext($authUser, $context, true);

        abort_unless(
            $this->canAttachClient($authUser, $client, $context),
            403,
            'Forbidden'
        );

        return DB::transaction(function () use ($authUser, $client, $resolved) {
            $client->loadMissing(['branch', 'branchGroup', 'responsibleAgent', 'type']);

            $this->applyContextAttachment($client, $resolved);
            $this->syncSharedVisibility($client, $authUser, $resolved);
            $this->syncContactKind($client, $authUser, $resolved['contact_kind']);

            $event = 'attached_existing_client';
            $contextPayload = array_filter([
                'context_type' => $resolved['type'],
                'context_id' => $resolved['id'],
                'property_relation' => $resolved['property_relation'],
                'shared_with_user_ids' => $resolved['participant_user_ids'],
            ], fn ($value) => $value !== null && $value !== []);

            $this->auditLogger->log(
                $client,
                $authUser,
                $event,
                [],
                $contextPayload,
                'Existing client attached to CRM context.',
                $contextPayload
            );

            $freshClient = $client->fresh(['branch', 'branchGroup', 'creator', 'responsibleAgent', 'type']);

            return [
                'message' => 'Existing client attached successfully.',
                'client' => $freshClient,
                'context' => $contextPayload,
            ];
        });
    }

    private function resolveContext(User $authUser, array $context, bool $enforceVisibility): array
    {
        $type = $context['type'] ?? self::CONTEXT_CLIENT;
        $id = $context['id'] ?? null;

        return match ($type) {
            self::CONTEXT_CLIENT => [
                'type' => $type,
                'id' => $id,
                'model' => null,
                'branch_id' => $authUser->branch_id,
                'participant_user_ids' => [$authUser->id],
                'contact_kind' => null,
                'property_relation' => null,
            ],
            self::CONTEXT_LEAD => $this->resolveLeadContext($authUser, $id, $enforceVisibility),
            self::CONTEXT_DEAL => $this->resolveDealContext($authUser, $id, $enforceVisibility),
            self::CONTEXT_PROPERTY => $this->resolvePropertyContext($authUser, $id, $context['property_relation'] ?? null),
            self::CONTEXT_CLIENT_NEED => $this->resolveNeedContext($authUser, $id, $enforceVisibility),
            default => abort(422, 'Unsupported context_type.'),
        };
    }

    private function resolveLeadContext(User $authUser, ?int $id, bool $enforceVisibility): array
    {
        abort_if(empty($id), 422, 'context_id is required for lead context.');

        $lead = Lead::query()->findOrFail($id);

        if ($enforceVisibility) {
            $this->leadAccess->ensureVisible($authUser, $lead);
        } else {
            abort_unless(
                $this->leadAccess->visibleQuery($authUser)->whereKey($lead->id)->exists(),
                403,
                'Forbidden'
            );
        }

        return [
            'type' => self::CONTEXT_LEAD,
            'id' => $lead->id,
            'model' => $lead,
            'branch_id' => $lead->branch_id,
            'participant_user_ids' => $this->participantIds([$authUser->id, $lead->created_by, $lead->responsible_agent_id]),
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'property_relation' => null,
        ];
    }

    private function resolveDealContext(User $authUser, ?int $id, bool $enforceVisibility): array
    {
        abort_if(empty($id), 422, 'context_id is required for deal context.');

        $deal = Deal::query()->findOrFail($id);

        if ($enforceVisibility) {
            $this->dealAccess->ensureVisible($authUser, $deal);
        } else {
            abort_unless(
                $this->dealAccess->visibleQuery($authUser)->whereKey($deal->id)->exists(),
                403,
                'Forbidden'
            );
        }

        return [
            'type' => self::CONTEXT_DEAL,
            'id' => $deal->id,
            'model' => $deal,
            'branch_id' => $deal->branch_id,
            'participant_user_ids' => $this->participantIds([$authUser->id, $deal->created_by, $deal->responsible_agent_id]),
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'property_relation' => null,
        ];
    }

    private function resolveNeedContext(User $authUser, ?int $id, bool $enforceVisibility): array
    {
        abort_if(empty($id), 422, 'context_id is required for client_need context.');

        $need = ClientNeed::query()->with('client')->findOrFail($id);

        if ($enforceVisibility) {
            $this->clientAccess->ensureNeedVisible($authUser, $need);
        } else {
            abort_unless($need->client !== null, 404, 'Client not found.');
            abort_unless(
                $this->clientAccess->visibleQuery($authUser)->whereKey($need->client_id)->exists(),
                403,
                'Forbidden'
            );
        }

        return [
            'type' => self::CONTEXT_CLIENT_NEED,
            'id' => $need->id,
            'model' => $need,
            'branch_id' => $need->client?->branch_id,
            'participant_user_ids' => $this->participantIds([$authUser->id, $need->created_by, $need->responsible_agent_id]),
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'property_relation' => null,
        ];
    }

    private function resolvePropertyContext(User $authUser, ?int $id, ?string $propertyRelation): array
    {
        abort_if(empty($id), 422, 'context_id is required for property context.');
        abort_unless(in_array($propertyRelation, self::propertyRelations(), true), 422, 'property_relation is required for property context.');

        $property = Property::query()->with(['agent.role', 'creator.role'])->findOrFail($id);
        abort_unless($this->canAccessProperty($authUser, $property), 403, 'Forbidden');

        $branchId = $property->agent?->branch_id ?: $property->creator?->branch_id ?: $authUser->branch_id;

        return [
            'type' => self::CONTEXT_PROPERTY,
            'id' => $property->id,
            'model' => $property,
            'branch_id' => $branchId,
            'participant_user_ids' => $this->participantIds([$authUser->id, $property->created_by, $property->agent_id]),
            'contact_kind' => $propertyRelation === 'owner' ? Client::CONTACT_KIND_SELLER : Client::CONTACT_KIND_BUYER,
            'property_relation' => $propertyRelation,
        ];
    }

    private function canAccessProperty(User $authUser, Property $property): bool
    {
        $roleSlug = $this->clientAccess->roleSlug($authUser);

        if ($this->clientAccess->isPrivilegedRole($roleSlug)) {
            return true;
        }

        if (!$this->clientAccess->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return false;
        }

        $propertyBranchId = $property->agent?->branch_id ?: $property->creator?->branch_id;

        if ((int) $propertyBranchId !== (int) $authUser->branch_id) {
            return false;
        }

        if (in_array($roleSlug, ['branch_director', 'rop', 'manager', 'operator'], true)) {
            return true;
        }

        return (int) $property->created_by === (int) $authUser->id
            || (int) $property->agent_id === (int) $authUser->id;
    }

    private function applyContextAttachment(Client $client, array $resolved): void
    {
        match ($resolved['type']) {
            self::CONTEXT_CLIENT => null,
            self::CONTEXT_LEAD => $resolved['model']->update(['client_id' => $client->id]),
            self::CONTEXT_DEAL => $resolved['model']->update(['client_id' => $client->id]),
            self::CONTEXT_CLIENT_NEED => $resolved['model']->update(['client_id' => $client->id]),
            self::CONTEXT_PROPERTY => $this->attachToProperty($client, $resolved),
            default => null,
        };
    }

    private function attachToProperty(Client $client, array $resolved): void
    {
        /** @var Property $property */
        $property = $resolved['model'];

        if ($resolved['property_relation'] === 'owner') {
            $property->update([
                'owner_client_id' => $client->id,
                'owner_name' => $client->full_name,
                'owner_phone' => $client->phone,
            ]);

            return;
        }

        $property->update([
            'buyer_client_id' => $client->id,
            'buyer_full_name' => $client->full_name,
            'buyer_phone' => $client->phone,
        ]);
    }

    private function syncSharedVisibility(Client $client, User $authUser, array $resolved): void
    {
        foreach ($resolved['participant_user_ids'] as $userId) {
            if ((int) $userId === (int) $client->responsible_agent_id) {
                continue;
            }

            $existing = $client->collaborators()->whereKey($userId)->exists();

            if ($existing) {
                $client->collaborators()->updateExistingPivot($userId, [
                    'granted_by' => $authUser->id,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $client->collaborators()->attach($userId, [
                'role' => Client::COLLABORATOR_ROLE_VIEWER,
                'granted_by' => $authUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncContactKind(Client $client, User $authUser, ?string $contactKind): void
    {
        if (!$contactKind) {
            return;
        }

        $merged = $client->mergedContactKindFor($contactKind);

        if ($merged === $client->contact_kind) {
            return;
        }

        $old = $client->contact_kind;
        $client->update(['contact_kind' => $merged]);
        $client->contact_kind = $merged;

        $this->auditLogger->log(
            $client,
            $authUser,
            'contact_kind_changed',
            ['contact_kind' => $old],
            ['contact_kind' => $merged],
            'Client contact kind changed.',
            ['client_id' => $client->id]
        );
    }

    private function participantIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id) => !empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function safeBranchPayload(Client $client): ?array
    {
        if (!$client->branch) {
            return null;
        }

        return [
            'id' => $client->branch->id,
            'name' => $client->branch->name,
        ];
    }

    private function safeBranchGroupPayload(Client $client): ?array
    {
        if (!$client->branchGroup) {
            return null;
        }

        return [
            'id' => $client->branchGroup->id,
            'name' => $client->branchGroup->name,
        ];
    }

    private function safeResponsiblePayload(Client $client): ?array
    {
        if (!$client->responsibleAgent) {
            return null;
        }

        return [
            'id' => $client->responsibleAgent->id,
            'name' => $client->responsibleAgent->name,
        ];
    }

    private function maskName(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return collect(preg_split('/\s+/u', $value) ?: [])
            ->filter()
            ->map(function (string $part) {
                $first = mb_substr($part, 0, 1);

                return $first.'***';
            })
            ->implode(' ');
    }

    private function maskPhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', max(strlen($digits) - 5, 1)).substr($digits, -2);
    }

    private function maskEmail(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || !str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 1)).'@'.$domain;
    }
}
