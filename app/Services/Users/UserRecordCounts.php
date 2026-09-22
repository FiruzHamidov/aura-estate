<?php

namespace App\Services\Users;

use App\Models\{Booking, BranchGroup, Client, Deal, Property, User};
use App\Support\RopGroupAccess;
use Illuminate\Support\Collection;

final class UserRecordCounts
{
    /** Count current responsibility, never historical authorship, in four queries per page. */
    public function attach(Collection $users, User $actor): void
    {
        if ($users->isEmpty() || ! in_array($actor->role?->slug,
            ['admin', 'superadmin', 'hr', 'marketing', 'branch_director', 'rop'], true)) {
            return;
        }

        $counts = [];
        foreach ([
            'properties' => [Property::class, 'agent_id', false],
            'clients' => [Client::class, 'responsible_agent_id', true],
            'bookings' => [Booking::class, 'agent_id', false],
            'deals' => [Deal::class, 'responsible_agent_id', true],
        ] as $type => [$class, $responsible, $hasBranch]) {
            $query = $class::query()->whereIn($responsible, $users->pluck('id'));
            if ($type === 'properties') {
                $query->where(fn ($q) => $q->whereNull('moderation_status')->orWhere('moderation_status', '!=', 'deleted'));
            }
            if ($actor->hasRole('rop')) {
                app(RopGroupAccess::class)->scope($query, $actor, 'branch_group_id', $hasBranch ? 'branch_id' : null);
            } elseif ($actor->hasRole('branch_director')) {
                if ($hasBranch) {
                    $query->where('branch_id', $actor->branch_id);
                } else {
                    $query->whereIn('branch_group_id', BranchGroup::query()->where('branch_id', $actor->branch_id)->select('id'));
                }
            }
            $counts[$type] = $query->selectRaw($responsible.', COUNT(*) as total')
                ->groupBy($responsible)->pluck('total', $responsible);
        }

        foreach ($users as $user) {
            $user->setAttribute('record_counts', collect($counts)
                ->map(fn ($totals) => (int) ($totals[$user->id] ?? 0))->all());
        }
    }
}
