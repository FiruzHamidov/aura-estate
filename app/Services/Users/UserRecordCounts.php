<?php

namespace App\Services\Users;

use App\Models\{Booking, BranchGroup, Client, Deal, Property, User};
use App\Support\RopGroupAccess;
use App\Services\GroupAccess\UserOrganizationService;
use Illuminate\Support\Collection;

final class UserRecordCounts
{
    /** Count current responsibility, falling back to authorship only when no agent is assigned. */
    public function attach(Collection $users, User $actor): void
    {
        if ($users->isEmpty() || !$actor->hasPermission('users.record_counts.view')) {
            return;
        }

        $counts = [];
        foreach ([
            'properties' => [Property::class, 'agent_id', false],
            'clients' => [Client::class, 'responsible_agent_id', true],
            'bookings' => [Booking::class, 'agent_id', false],
            'deals' => [Deal::class, 'responsible_agent_id', true],
        ] as $type => [$class, $responsible, $hasBranch]) {
            $owner = $type === 'properties' ? 'COALESCE(agent_id, created_by)' : $responsible;
            $query = $class::query()->whereIn(\Illuminate\Support\Facades\DB::raw($owner), $users->pluck('id'));
            if ($type === 'properties') {
                $query->where(fn ($q) => $q->whereNull('moderation_status')->orWhere('moderation_status', '!=', 'deleted'));
            }
            if ($actor->usesAssignedGroupScope()) {
                app(RopGroupAccess::class)->scope($query, $actor, 'branch_group_id', $hasBranch ? 'branch_id' : null);
            } elseif (!$actor->hasPermission('users.record_counts.all_branches')) {
                if ($hasBranch) {
                    $query->where('branch_id', $actor->branch_id);
                } else {
                    $query->whereIn('branch_group_id', BranchGroup::withoutGlobalScope('active')->where('branch_id', $actor->branch_id)->select('id'));
                }
            }
            if ($type === 'properties') {
                $closed = UserOrganizationService::CLOSED_PROPERTY_STATUSES;
                $placeholders = implode(',', array_fill(0, count($closed), '?'));
                $rows = $query->selectRaw($owner.' as counted_user_id, COUNT(*) as total')
                    ->selectRaw("SUM(CASE WHEN moderation_status IN ($placeholders) THEN 1 ELSE 0 END) as completed", $closed)
                    ->selectRaw('SUM(CASE WHEN agent_id IS NULL THEN 1 ELSE 0 END) as unassigned')
                    ->groupByRaw($owner)->get();
                $counts[$type] = $rows->pluck('total', 'counted_user_id');
                $counts['properties_active'] = $rows->mapWithKeys(fn ($row) => [$row->counted_user_id => (int) $row->total - (int) $row->completed]);
                $counts['properties_completed'] = $rows->pluck('completed', 'counted_user_id');
                $counts['properties_unassigned'] = $rows->pluck('unassigned', 'counted_user_id');
                continue;
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
