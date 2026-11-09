<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Eligibility is shared by listing, counts and the transactional claim check. */
final class UnattendedCatalogClients
{
    public function days(): int
    {
        return max(1, (int) config('client_catalog.inactive_days', 14));
    }

    public function apply(Builder $clients): void
    {
        $cutoff = now()->subDays($this->days());
        // Any existing need, even partial or closed, excludes this separate list.
        $clients->doesntHave('needs')
            ->where('clients.created_at', '<=', $cutoff)
            ->whereRaw('COALESCE(clients.updated_at, clients.created_at) <= ?', [$cutoff])
            ->whereDoesntHave('auditLogs', fn (Builder $q) => $q->where('created_at', '>', $cutoff))
            ->whereDoesntHave('needs', fn (Builder $q) => $q->withTrashed()->where(function (Builder $q) use ($cutoff) {
                $q->where('created_at', '>', $cutoff)->orWhere('updated_at', '>', $cutoff)->orWhere('deleted_at', '>', $cutoff)
                    ->orWhere('closed_at', '>', $cutoff);
            }))
            ->whereDoesntHave('bookings', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('created_at', '>', $cutoff)->orWhere('updated_at', '>', $cutoff)
                ->orWhere(fn (Builder $showing) => $showing->where('status', '!=', 'cancelled')->where('end_time', '>', $cutoff))));

        foreach (['deals', 'leads'] as $relation) {
            $clients->whereDoesntHave($relation, function (Builder $q) use ($relation, $cutoff) {
                $q->withTrashed()->where(function (Builder $q) use ($relation, $cutoff) {
                    $q->where('created_at', '>', $cutoff)->orWhere('updated_at', '>', $cutoff)
                        ->orWhere('closed_at', '>', $cutoff)->orWhere('deleted_at', '>', $cutoff)->orWhere('next_activity_at', '>=', now())
                        ->orWhereHas('auditLogs', fn (Builder $audit) => $audit->where('created_at', '>', $cutoff));
                    if ($relation === 'leads') {
                        $q->orWhere('last_activity_at', '>', $cutoff)->orWhere('first_contacted_at', '>', $cutoff)
                            ->orWhere('next_follow_up_at', '>=', now());
                    }
                });
            });
        }

        // Calls and meetings can be attached directly or through the client's deal/lead/showing.
        $clients->whereNotExists(function (QueryBuilder $tasks) use ($cutoff) {
            $tasks->selectRaw('1')->from('crm_tasks')->where(function (QueryBuilder $entities) {
                $entities->where(fn (QueryBuilder $q) => $q->where('related_entity_type', 'client')->whereColumn('related_entity_id', 'clients.id'));
                foreach (['deal' => ['crm_deals', 'client_id'], 'lead' => ['leads', 'client_id'], 'showing' => ['bookings', 'crm_client_id']] as $type => [$table, $clientKey]) {
                    $entities->orWhere(fn (QueryBuilder $q) => $q->where('related_entity_type', $type)
                        ->whereIn('related_entity_id', fn (QueryBuilder $related) => $related->select('id')->from($table)->whereColumn($clientKey, 'clients.id')));
                }
            })->where(fn (QueryBuilder $q) => $q->where('created_at', '>', $cutoff)->orWhere('updated_at', '>', $cutoff)
                ->orWhere('completed_at', '>', $cutoff)
                ->orWhere(fn (QueryBuilder $planned) => $planned->whereNotIn('status', ['done', 'canceled'])->where('due_at', '>=', now())));
        });
    }
}
