<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use App\Support\DealPipelineAccess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeadConversionService
{
    public function __construct(
        private readonly LeadDeduplicator $deduplicator,
        private readonly ClientAccess $clientAccess,
        private readonly AuditLogger $auditLogger,
        private readonly DealPipelineAccess $pipelineAccess,
        private readonly DealBoardService $boardService
    ) {
    }

    public function convert(Lead $lead, User $actor): array
    {
        return DB::transaction(function () use ($lead, $actor) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $existingDeal = $this->findExistingDeal($lead);

            if ($lead->status === Lead::STATUS_CONVERTED && $lead->client_id && $existingDeal) {
                $existingDeal = $this->syncExistingDealLinks($existingDeal, $lead, (int) $lead->client_id);

                return $this->conversionPayload($lead, $lead->client()->firstOrFail(), $existingDeal);
            }

            $client = $lead->client_id ? $lead->client()->first() : null;

            if ($client) {
                $client = $lead->status === Lead::STATUS_CONVERTED
                    ? $client->fresh()
                    : $this->updateExistingClient($client, $lead);
                $clientEvent = 'lead_linked';
                $clientMessage = 'Existing client linked to converted lead.';
            } else {
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
            }

            $deal = $existingDeal ?: $this->createDealFromLead($lead, $client, $actor);

            if ($existingDeal) {
                $deal = $this->syncExistingDealLinks($existingDeal, $lead, (int) $client->id);
            }

            $oldLead = [
                'status' => $lead->status,
                'client_id' => $lead->client_id,
                'converted_client_id' => $lead->converted_client_id,
                'converted_deal_id' => $lead->converted_deal_id,
                'converted_at' => optional($lead->converted_at)?->toIso8601String(),
                'closed_at' => optional($lead->closed_at)?->toIso8601String(),
            ];

            $lead->update([
                'client_id' => $client->id,
                'converted_client_id' => $client->id,
                'converted_deal_id' => $deal->id,
                'status' => Lead::STATUS_CONVERTED,
                'converted_at' => $lead->converted_at ?: now(),
                'closed_at' => $lead->closed_at ?: now(),
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
                    'converted_client_id' => $lead->converted_client_id,
                    'converted_deal_id' => $lead->converted_deal_id,
                    'converted_at' => optional($lead->converted_at)?->toIso8601String(),
                    'closed_at' => optional($lead->closed_at)?->toIso8601String(),
                ],
                'Лид конвертирован в сделку',
                ['client_id' => $client->id, 'deal_id' => $deal->id]
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

            if (! $existingDeal) {
                $this->auditLogger->log(
                    $deal,
                    $actor,
                    'created_from_lead',
                    [],
                    array_filter([
                        'lead_id' => $lead->id,
                        'client_id' => $client->id,
                        'source' => $deal->source,
                    ], fn ($value) => $value !== null && $value !== ''),
                    'Сделка создана из лида',
                    array_filter([
                        'lead_id' => $lead->id,
                        'lead_snapshot' => Arr::get($deal->meta, 'lead_snapshot'),
                    ], fn ($value) => $value !== null && $value !== '')
                );
            }

            return $this->conversionPayload($lead, $client, $deal);
        });
    }

    private function findExistingDeal(Lead $lead): ?Deal
    {
        if ($lead->converted_deal_id) {
            $deal = Deal::query()->whereKey($lead->converted_deal_id)->first();

            if ($deal) {
                return $deal;
            }
        }

        return Deal::query()
            ->where('lead_id', $lead->id)
            ->orderBy('id')
            ->first();
    }

    private function syncExistingDealLinks(Deal $deal, Lead $lead, int $clientId): Deal
    {
        $data = [];

        if (! $deal->client_id) {
            $data['client_id'] = $clientId;
        }

        if (
            Schema::hasColumn('crm_deals', 'client_need_id')
            && ! $deal->client_need_id
            && $lead->client_need_id
        ) {
            $data['client_need_id'] = $lead->client_need_id;
        }

        if ($data === []) {
            return $deal;
        }

        $deal->update($data);

        return $deal->fresh();
    }

    private function createDealFromLead(Lead $lead, Client $client, User $actor): Deal
    {
        [$pipeline, $stage] = $this->resolveConversionStage($lead, $actor);

        $data = [
            'title' => $lead->full_name ?: ('Lead #'.$lead->id),
            'client_id' => $client->id,
            'lead_id' => $lead->id,
            'branch_id' => $lead->branch_id ?: $actor->branch_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'responsible_agent_id' => $lead->responsible_agent_id ?: $actor->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'amount' => $lead->budget,
            'currency' => $lead->currency ? mb_strtoupper((string) $lead->currency) : 'TJS',
            'source' => $lead->source,
            'board_position' => $this->boardService->nextPosition($stage),
            'note' => $lead->note,
            'tags' => $lead->tags,
            'last_contact_result' => $lead->last_contact_result,
            'next_activity_at' => $lead->next_activity_at ?: $lead->next_follow_up_at,
            'meta' => [
                'origin' => [
                    'type' => 'lead',
                    'lead_id' => $lead->id,
                ],
                'lead_snapshot' => array_filter([
                    'full_name' => $lead->full_name,
                    'phone' => $lead->phone,
                    'phone_normalized' => $lead->phone_normalized,
                    'email' => $lead->email,
                    'source' => $lead->source,
                    'note' => $lead->note,
                    'status' => $lead->status,
                    'branch_id' => $lead->branch_id,
                    'responsible_agent_id' => $lead->responsible_agent_id,
                    'client_id' => $client->id,
                    'client_need_id' => $lead->client_need_id,
                    'budget' => $lead->budget,
                    'currency' => $lead->currency,
                    'tags' => $lead->tags,
                    'last_contact_result' => $lead->last_contact_result,
                    'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
                    'next_activity_at' => $lead->next_activity_at?->toIso8601String(),
                ], fn ($value) => $value !== null && $value !== ''),
            ],
        ];

        if (Schema::hasColumn('crm_deals', 'client_need_id') && $lead->client_need_id) {
            $data['client_need_id'] = $lead->client_need_id;
            $data['meta']['lead_snapshot']['client_need_id'] = $lead->client_need_id;
        }

        return Deal::create($data);
    }

    private function resolveConversionStage(Lead $lead, User $actor): array
    {
        $query = $this->pipelineAccess->visibleQuery($actor)
            ->where('is_active', true)
            ->where('type', DealPipeline::TYPE_SALES)
            ->where(function ($builder) {
                $builder
                    ->whereNull('code')
                    ->orWhereNotIn('code', [
                        DealPipeline::CODE_PROPERTY_CONTROL,
                        DealPipeline::CODE_HR_RECRUITMENT,
                    ]);
            });

        if ($lead->branch_id) {
            $query->where(function ($builder) use ($lead) {
                $builder
                    ->where('branch_id', $lead->branch_id)
                    ->orWhereNull('branch_id');
            });
        } else {
            $query->whereNull('branch_id');
        }

        $pipeline = $query
            ->orderByRaw('case when branch_id = ? then 0 when branch_id is null then 1 else 2 end', [(int) $lead->branch_id])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $pipeline) {
            abort(422, 'Нет доступной активной воронки сделок для конвертации лида.');
        }

        $stage = $pipeline->stages()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first()
            ?: $pipeline->stages()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

        if (! $stage) {
            abort(422, 'Нет доступной активной стадии сделки для конвертации лида.');
        }

        return [$pipeline, $stage];
    }

    private function conversionPayload(Lead $lead, Client $client, Deal $deal): array
    {
        return [
            'message' => 'Лид успешно конвертирован в сделку',
            'lead' => $lead->fresh($this->leadPayloadRelations()),
            'client' => $client->fresh($this->clientPayloadRelations()),
            'deal' => $deal->fresh($this->dealPayloadRelations()),
        ];
    }

    private function clientNeedRelations(): array
    {
        if (! Schema::hasTable('client_needs')) {
            return [];
        }

        return [
            'client.needs',
            'clientNeed',
        ];
    }

    private function leadPayloadRelations(): array
    {
        return array_merge([
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'client',
            'deals.pipeline',
            'deals.stage',
            'auditLogs.actor',
        ], $this->clientNeedRelations());
    }

    private function dealPayloadRelations(): array
    {
        return array_merge([
            'client',
            'lead',
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'pipeline',
            'stage',
            'auditLogs.actor',
            'lead.client',
            'lead.clientNeed',
        ], $this->clientNeedRelations());
    }

    private function clientPayloadRelations(): array
    {
        if (! Schema::hasTable('client_needs')) {
            return [];
        }

        return [
            'needs',
        ];
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
