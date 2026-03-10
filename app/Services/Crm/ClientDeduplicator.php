<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

class ClientDeduplicator
{
    public function __construct(
        private readonly ClientAccess $clientAccess
    ) {
    }

    public function summarize(User $authUser, array $data, ?int $excludeClientId = null): array
    {
        $matchesQuery = $this->matchesQuery($data, $excludeClientId);

        $allMatchesCount = (clone $matchesQuery)->count();

        if ($allMatchesCount === 0) {
            return [
                'has_duplicates' => false,
                'visible_matches_count' => 0,
                'hidden_matches_count' => 0,
                'visible_matches' => [],
                'top_visible_match' => null,
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

        $visibleMatchesCount = (clone $visibleMatchesQuery)->count();
        $hiddenMatchesCount = max($allMatchesCount - $visibleMatchesCount, 0);

        return [
            'has_duplicates' => true,
            'visible_matches_count' => $visibleMatchesCount,
            'hidden_matches_count' => $hiddenMatchesCount,
            'visible_matches' => $visibleMatches,
            'top_visible_match' => $visibleMatches->first(),
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
}
