<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Lead;

class LeadDeduplicator
{
    public function summarize(Lead $lead): array
    {
        $clientMatches = $this->clientMatchesQuery($lead)->limit(3)->get([
            'id',
            'full_name',
            'phone',
            'email',
            'responsible_agent_id',
            'branch_id',
        ]);

        $leadMatches = $this->leadMatchesQuery($lead)->limit(3)->get([
            'id',
            'full_name',
            'phone',
            'email',
            'status',
            'responsible_agent_id',
            'branch_id',
        ]);

        return [
            'client_matches_count' => $this->clientMatchesQuery($lead)->count(),
            'lead_matches_count' => $this->leadMatchesQuery($lead)->count(),
            'top_client_match' => $clientMatches->first(),
            'top_lead_match' => $leadMatches->first(),
            'client_matches' => $clientMatches,
            'lead_matches' => $leadMatches,
        ];
    }

    public function findClientMatchForLead(Lead $lead): ?Client
    {
        return $this->clientMatchesQuery($lead)->orderBy('id')->first();
    }

    private function clientMatchesQuery(Lead $lead)
    {
        if (!$this->hasIdentifiers($lead)) {
            return Client::query()->whereRaw('1 = 0');
        }

        return Client::query()
            ->when(
                $lead->branch_id,
                fn ($query) => $query->where('branch_id', $lead->branch_id),
                fn ($query) => $query->whereNull('branch_id')
            )
            ->where(function ($query) use ($lead) {
                if (!empty($lead->phone_normalized)) {
                    $query->orWhere('phone_normalized', $lead->phone_normalized);
                }

                if (!empty($lead->email)) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)]);
                }
            });
    }

    private function leadMatchesQuery(Lead $lead)
    {
        if (!$this->hasIdentifiers($lead)) {
            return Lead::query()->whereRaw('1 = 0');
        }

        return Lead::query()
            ->whereKeyNot($lead->id)
            ->whereNotIn('status', Lead::closedStatuses())
            ->when(
                $lead->branch_id,
                fn ($query) => $query->where('branch_id', $lead->branch_id),
                fn ($query) => $query->whereNull('branch_id')
            )
            ->where(function ($query) use ($lead) {
                if (!empty($lead->phone_normalized)) {
                    $query->orWhere('phone_normalized', $lead->phone_normalized);
                }

                if (!empty($lead->email)) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)]);
                }
            });
    }

    private function hasIdentifiers(Lead $lead): bool
    {
        return !empty($lead->phone_normalized) || !empty($lead->email);
    }
}
