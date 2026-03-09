<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use Illuminate\Support\Facades\DB;

class LeadConversionService
{
    public function __construct(
        private readonly LeadDeduplicator $deduplicator,
        private readonly ClientAccess $clientAccess,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function convert(Lead $lead, User $actor): Lead
    {
        if ($lead->status === Lead::STATUS_CONVERTED && $lead->client_id) {
            return $lead->fresh(['branch', 'creator', 'responsibleAgent', 'client', 'auditLogs.actor']);
        }

        return DB::transaction(function () use ($lead, $actor) {
            $client = $this->deduplicator->findClientMatchForLead($lead);

            if ($client) {
                $client = $this->updateExistingClient($client, $lead);
                $clientEvent = 'lead_linked';
                $clientMessage = 'Existing client linked to converted lead.';
            } else {
                $client = $this->createClientFromLead($lead, $actor);
                $clientEvent = 'created_from_lead';
                $clientMessage = 'New client created from lead conversion.';
            }

            $oldLead = [
                'status' => $lead->status,
                'client_id' => $lead->client_id,
                'converted_at' => optional($lead->converted_at)?->toIso8601String(),
                'closed_at' => optional($lead->closed_at)?->toIso8601String(),
            ];

            $lead->update([
                'client_id' => $client->id,
                'status' => Lead::STATUS_CONVERTED,
                'converted_at' => now(),
                'closed_at' => now(),
                'first_contacted_at' => $lead->first_contacted_at ?: now(),
                'last_activity_at' => now(),
                'lost_reason' => null,
            ]);

            $this->auditLogger->log(
                $lead,
                $actor,
                'converted',
                $oldLead,
                [
                    'status' => $lead->status,
                    'client_id' => $lead->client_id,
                    'converted_at' => optional($lead->converted_at)?->toIso8601String(),
                    'closed_at' => optional($lead->closed_at)?->toIso8601String(),
                ],
                'Lead converted into client.',
                ['client_id' => $client->id]
            );

            $this->auditLogger->log(
                $client,
                $actor,
                $clientEvent,
                [],
                ['lead_id' => $lead->id],
                $clientMessage,
                ['lead_id' => $lead->id]
            );

            return $lead->fresh(['branch', 'creator', 'responsibleAgent', 'client', 'auditLogs.actor']);
        });
    }

    private function createClientFromLead(Lead $lead, User $actor): Client
    {
        $data = [
            'full_name' => $lead->full_name ?: ('Lead #' . $lead->id),
            'phone' => $lead->phone,
            'phone_normalized' => ClientPhone::normalize($lead->phone),
            'email' => $lead->email,
            'note' => $lead->note,
            'branch_id' => $lead->branch_id ?: $actor->branch_id,
            'responsible_agent_id' => $lead->responsible_agent_id ?: $actor->id,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
            'status' => 'active',
            'meta' => array_filter([
                'lead_source' => $lead->source,
                'converted_from_lead_id' => $lead->id,
            ], fn ($value) => $value !== null && $value !== ''),
        ];

        $data = $this->clientAccess->normalizeMutationData($data, $actor);
        $this->clientAccess->validateMutationTargets($actor, $data);

        return Client::create($data);
    }

    private function updateExistingClient(Client $client, Lead $lead): Client
    {
        $mergedContactKind = $client->mergedContactKindFor(Client::CONTACT_KIND_BUYER);

        $payload = array_filter([
            'full_name' => $client->full_name ?: $lead->full_name,
            'phone' => $client->phone ?: $lead->phone,
            'phone_normalized' => $client->phone_normalized ?: ClientPhone::normalize($lead->phone),
            'email' => $client->email ?: $lead->email,
            'note' => $client->note ?: $lead->note,
            'branch_id' => $client->branch_id ?: $lead->branch_id,
            'responsible_agent_id' => $client->responsible_agent_id ?: $lead->responsible_agent_id,
            'contact_kind' => $mergedContactKind !== $client->contact_kind ? $mergedContactKind : null,
        ], fn ($value) => $value !== null && $value !== '');

        if (!empty($payload)) {
            $client->update($payload);
        }

        return $client->fresh();
    }
}
