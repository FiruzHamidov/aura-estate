<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\User;
use App\Services\Crm\ClientAttachService;
use App\Support\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

class ClientDeduplicator
{
    public function __construct(
        private readonly ClientAccess $clientAccess,
        private readonly ClientAttachService $attachService,
    ) {
    }

    public function summarize(User $authUser, array $data, ?int $excludeClientId = null, array $context = []): array
    {
        $matchesQuery = $this->matchesQuery($data, $excludeClientId);

        $allMatchesCount = (clone $matchesQuery)->count();

        if ($allMatchesCount === 0) {
            return [
                'has_duplicates' => false,
                'visible_matches_count' => 0,
                'hidden_matches_count' => 0,
                'visible_matches' => [],
                'attachable_hidden_matches_count' => 0,
                'attachable_matches' => [],
                'top_visible_match' => null,
                'top_attachable_match' => null,
                'message' => null,
            ];
        }

        $visibleMatchesQuery = $this->clientAccess->visibleQuery($authUser)
            ->whereIn('clients.id', (clone $matchesQuery)->select('clients.id'));

        $visibleMatches = (clone $visibleMatchesQuery)
            ->limit(3)
            ->get([
                'clients.id',
                'clients.full_name',
                'clients.phone',
                'clients.email',
                'clients.branch_id',
                'clients.branch_group_id',
                'clients.responsible_agent_id',
                'clients.contact_kind',
                'clients.status',
            ]);

        $visibleMatchIds = $visibleMatches->pluck('id');
        $visibleMatchesCount = (clone $visibleMatchesQuery)->count();
        $visiblePayload = $visibleMatches
            ->map(fn (Client $client) => $this->attachService->visibleMatchPayload($authUser, $client, $context))
            ->values();

        $attachableMatches = collect();
        $attachableMatchesCount = 0;

        if ($this->attachService->canUseExistingFlow($authUser, $context)) {
            $attachableQuery = $this->attachService->attachableMatchesQuery(
                $authUser,
                Client::query()
                    ->whereIn('clients.id', (clone $matchesQuery)->select('clients.id'))
                    ->when(
                        $visibleMatchIds->isNotEmpty(),
                        fn (Builder $query) => $query->whereNotIn('clients.id', $visibleMatchIds)
                    ),
                $context
            );

            $attachableMatches = (clone $attachableQuery)
                ->limit(3)
                ->get([
                    'clients.id',
                    'clients.full_name',
                    'clients.phone',
                    'clients.email',
                    'clients.branch_id',
                    'clients.branch_group_id',
                    'clients.responsible_agent_id',
                ])
                ->filter(fn (Client $client) => $this->attachService->canAttachClient($authUser, $client, $context))
                ->values();

            $attachableMatchesCount = (clone $attachableQuery)->get(['clients.id', 'clients.branch_id'])
                ->filter(fn (Client $client) => $this->attachService->canAttachClient($authUser, $client, $context))
                ->count();
        }

        $hiddenMatchesCount = max($allMatchesCount - $visibleMatchesCount - $attachableMatchesCount, 0);
        $attachablePayload = $attachableMatches
            ->map(fn (Client $client) => $this->attachService->limitedMatchPayload($client))
            ->values();

        return [
            'has_duplicates' => true,
            'visible_matches_count' => $visibleMatchesCount,
            'hidden_matches_count' => $hiddenMatchesCount,
            'visible_matches' => $visiblePayload,
            'attachable_hidden_matches_count' => $attachableMatchesCount,
            'attachable_matches' => $attachablePayload,
            'top_visible_match' => $visiblePayload->first(),
            'top_attachable_match' => $attachablePayload->first(),
            'message' => $this->message($visibleMatchesCount, $attachableMatchesCount, $hiddenMatchesCount),
        ];
    }

    public function hasIdentifiers(array $data): bool
    {
        return !empty($data['phone_normalized']) || !empty($data['email']);
    }

    private function matchesQuery(array $data, ?int $excludeClientId = null): Builder
    {
        if (!$this->hasIdentifiers($data)) {
            return Client::query()->whereRaw('1 = 0');
        }

        $branchId = $data['branch_id'] ?? null;
        $email = !empty($data['email']) ? mb_strtolower((string) $data['email']) : null;
        $phone = $data['phone_normalized'] ?? null;

        return Client::query()
            ->when(
                $branchId,
                fn (Builder $query) => $query->where('branch_id', $branchId),
                fn (Builder $query) => $query->whereNull('branch_id')
            )
            ->when($excludeClientId, fn (Builder $query) => $query->whereKeyNot($excludeClientId))
            ->where(function (Builder $query) use ($phone, $email) {
                if (!empty($phone)) {
                    $query->orWhere('phone_normalized', $phone);
                }

                if (!empty($email)) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            });
    }

    private function message(int $visibleMatchesCount, int $attachableMatchesCount, int $hiddenMatchesCount): ?string
    {
        if ($visibleMatchesCount > 0) {
            return 'Duplicate client already exists.';
        }

        if ($attachableMatchesCount > 0) {
            return 'Duplicate client exists and can be attached to the current context.';
        }

        if ($hiddenMatchesCount > 0) {
            return 'Duplicate client exists but is not attachable for current user.';
        }

        return null;
    }
}
