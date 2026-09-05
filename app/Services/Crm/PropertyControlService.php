<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Property;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PropertyControlService
{
    public const CONTROL_KIND = 'security_property_closure';

    public function __construct(
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger,
        private readonly ActivityService $activityService,
        private readonly NotificationService $notifications
    ) {}

    public function syncForProperty(
        Property $property,
        ?User $actor = null,
        ?string $sourceEventUuid = null
    ): ?Deal {
        if (! $this->workflowSchemaReady()) {
            return null;
        }

        $propertyRelations = [
            'agent.role',
            'creator.role',
            'ownerClient.type',
            'buyerClient.type',
            'saleUser.role',
            'depositUser.role',
            'logs.user',
        ];
        if (Schema::hasTable('property_agent_sales')) {
            $propertyRelations[] = 'saleAgents.role';
        }
        $property->loadMissing($propertyRelations);

        $isTriggered = in_array(
            $property->moderation_status,
            config('security-property-control.trigger_statuses', []),
            true
        );
        if (! $isTriggered) {
            return $this->cancelOpenCards($property, $actor, 'Объект возвращён в активный статус.');
        }

        $sourceEventUuid ??= $this->latestEventUuid($property);
        $branchId = $this->resolveBranchId($property, $actor);

        if (! $branchId) {
            Log::warning('Property control event has no resolvable branch.', [
                'property_id' => $property->id,
                'moderation_status' => $property->moderation_status,
            ]);

            if (Schema::hasTable('crm_audit_logs')) {
                try {
                    $this->auditLogger->log(
                        $property,
                        $actor,
                        'property_control_branch_missing',
                        [],
                        array_filter([
                            'moderation_status' => $property->moderation_status,
                            'source_event_uuid' => $sourceEventUuid,
                        ]),
                        'CRM-карточка контроля не создана: филиал объекта не определён.'
                    );
                } catch (\Throwable $exception) {
                    Log::error('Failed to persist property-control branch diagnostic.', [
                        'property_id' => $property->id,
                        'error_class' => $exception::class,
                    ]);
                }
            }

            return null;
        }

        $pipeline = $this->ensurePipeline($branchId);

        return $this->createForEvent($property, $pipeline, $actor, $sourceEventUuid);
    }

    public function ensurePipeline(int $branchId): DealPipeline
    {
        return DB::transaction(function () use ($branchId) {
            $pipeline = DealPipeline::query()
                ->where('branch_id', $branchId)
                ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
                ->lockForUpdate()
                ->first();

            if (! $pipeline) {
                $sortOrder = (int) DealPipeline::query()
                    ->where('branch_id', $branchId)
                    ->max('sort_order') + 10;

                $pipeline = DealPipeline::create([
                    'name' => 'Контроль объектов',
                    'slug' => DealPipeline::CODE_PROPERTY_CONTROL.'_branch_'.$branchId,
                    'code' => DealPipeline::CODE_PROPERTY_CONTROL,
                    'type' => DealPipeline::TYPE_PROPERTY_CONTROL,
                    'branch_id' => $branchId,
                    'sort_order' => $sortOrder,
                    'is_default' => false,
                    'is_active' => true,
                    'meta' => [
                        'system_managed' => true,
                        'role_scope' => 'security',
                    ],
                ]);
            }

            $this->ensureStages($pipeline);

            return $pipeline->fresh(['stages', 'defaultStage']);
        });
    }

    private function workflowSchemaReady(): bool
    {
        return Schema::hasTable('crm_deal_pipelines')
            && Schema::hasTable('crm_deal_stages')
            && Schema::hasTable('crm_deals')
            && Schema::hasColumns('crm_deals', ['control_kind', 'source_event_uuid']);
    }

    public function backfillProperty(Property $property, string $sourceEventUuid): ?Deal
    {
        $propertyRelations = [
            'agent.role', 'creator.role', 'ownerClient.type', 'buyerClient.type',
            'saleUser.role', 'depositUser.role', 'logs.user',
        ];
        if (Schema::hasTable('property_agent_sales')) {
            $propertyRelations[] = 'saleAgents.role';
        }
        $property->loadMissing($propertyRelations);
        $branchId = $this->resolveBranchId($property);

        if (! $branchId) {
            return null;
        }

        $pipeline = $this->ensurePipeline($branchId);
        $legacy = Deal::query()
            ->where('primary_property_id', $property->id)
            ->where('pipeline_id', $pipeline->id)
            ->where('source_property_status', $property->moderation_status)
            ->whereNull('source_event_uuid')
            ->latest('id')
            ->first();

        if (! $legacy) {
            return $this->createForEvent(
                $property,
                $pipeline,
                null,
                $sourceEventUuid,
                $this->isoDate($property->sold_at ?: $property->updated_at)
            );
        }

        $controlMeta = $this->cardPayload(
            $property,
            $pipeline,
            null,
            $sourceEventUuid,
            $this->isoDate($property->sold_at ?: $property->updated_at)
        )['meta'];
        $legacy->update([
            'control_kind' => self::CONTROL_KIND,
            'source_event_uuid' => $sourceEventUuid,
            'meta' => array_replace_recursive($legacy->meta ?? [], $controlMeta),
        ]);

        return $legacy->fresh($this->relations());
    }

    public function eventUuidFor(Property $property, string $eventIdentity): string
    {
        $hash = hash('sha1', implode(':', [
            'aura-property-control',
            $property->id,
            $property->moderation_status,
            $eventIdentity,
        ]));

        return sprintf(
            '%s-%s-5%s-a%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

    public function branchIdFor(Property $property, ?User $actor = null): ?int
    {
        $property->loadMissing(['agent', 'creator', 'ownerClient']);

        return $this->resolveBranchId($property, $actor);
    }

    private function latestEventUuid(Property $property): string
    {
        $log = $property->logs()
            ->where('action', 'status_change')
            ->latest('id')
            ->first();

        return $this->eventUuidFor(
            $property,
            $log ? 'property-log-'.$log->id : 'state-'.$property->updated_at?->format('U.u')
        );
    }

    private function createForEvent(
        Property $property,
        DealPipeline $pipeline,
        ?User $actor,
        string $sourceEventUuid,
        ?string $triggeredAt = null
    ): Deal {
        return DB::transaction(function () use ($property, $pipeline, $actor, $sourceEventUuid, $triggeredAt) {
            Property::query()->whereKey($property->id)->lockForUpdate()->first();

            $existing = Deal::query()
                ->where('source_event_uuid', $sourceEventUuid)
                ->first();

            if ($existing) {
                return $existing->load($this->relations());
            }

            $this->cancelOpenCards(
                $property,
                $actor,
                'Создано новое событие контроля закрывающего статуса.'
            );

            $stage = $this->resolveStage($pipeline, PropertyControlWorkflow::STAGE_NEW);
            $deal = Deal::create(array_merge(
                $this->cardPayload($property, $pipeline, $actor, $sourceEventUuid, $triggeredAt),
                [
                    'stage_id' => $stage->id,
                    'board_position' => $this->boardService->nextPosition($stage),
                    'currency' => $property->actual_sale_currency ?: 'TJS',
                    'expected_company_income_currency' => $property->company_expected_income_currency ?: 'TJS',
                    'expected_agent_commission_currency' => 'TJS',
                    'actual_company_income_currency' => $property->company_commission_currency ?: 'TJS',
                ]
            ));

            $this->auditLogger->log(
                $deal,
                $actor,
                'created',
                [],
                Arr::only($deal->getAttributes(), [
                    'title',
                    'client_id',
                    'branch_id',
                    'pipeline_id',
                    'stage_id',
                    'primary_property_id',
                    'source_property_status',
                    'control_kind',
                    'source_event_uuid',
                ]),
                'Создана CRM-карточка контроля закрытого объявления.',
                ['property_status' => $property->moderation_status]
            );

            DB::afterCommit(fn () => $this->notifications->handlePropertyControlCreated(
                $deal->fresh($this->relations()),
                $actor
            ));

            return $deal->fresh($this->relations());
        });
    }

    private function cancelOpenCards(
        Property $property,
        ?User $actor,
        string $reason
    ): ?Deal {
        $openCards = $this->cardsQuery($property)
            ->whereNull('closed_at')
            ->get();

        if ($openCards->isEmpty()) {
            return null;
        }

        $last = null;

        foreach ($openCards as $card) {
            $target = $this->resolveStage($card->pipeline, PropertyControlWorkflow::STAGE_CANCELLED);
            $before = $this->stageSnapshot($card);
            $card->lost_reason = $reason;
            $card->updated_by = $actor?->id;
            $card->save();
            $last = $this->boardService->moveDeal($card, $target, null, $reason);

            $this->activityService->logStatusChange(
                $last,
                $actor,
                $before,
                $this->stageSnapshot($last),
                ['property_status' => $property->moderation_status, 'reason' => $reason],
                'CRM-карточка контроля закрыта автоматически.'
            );
        }

        return $last?->fresh($this->relations());
    }

    private function cardPayload(
        Property $property,
        DealPipeline $pipeline,
        ?User $actor,
        string $sourceEventUuid,
        ?string $triggeredAt = null
    ): array {
        return [
            'title' => $property->title ?: ('Контроль объекта #'.$property->id),
            'client_id' => $property->owner_client_id,
            'branch_id' => $pipeline->branch_id,
            'created_by' => $actor?->id ?: $property->created_by ?: $property->agent_id,
            'responsible_agent_id' => null,
            'pipeline_id' => $pipeline->id,
            'primary_property_id' => $property->id,
            'source_property_status' => $property->moderation_status,
            'control_kind' => self::CONTROL_KIND,
            'source_event_uuid' => $sourceEventUuid,
            'source' => 'property_status',
            'amount' => $property->actual_sale_price,
            'expected_company_income' => $property->company_expected_income,
            'actual_company_income' => $property->company_commission_amount,
            'note' => $property->status_comment ?: null,
            'next_activity_at' => now()->addHours((int) config('security-property-control.sla_hours.claim', 24)),
            'updated_by' => $actor?->id,
            'meta' => [
                'property_status_history_available' => true,
                'control' => [
                    'kind' => self::CONTROL_KIND,
                    'source_event_uuid' => $sourceEventUuid,
                    'triggered_at' => $triggeredAt ?: now()->toIso8601String(),
                    'closing_status' => $property->moderation_status,
                    'closing_snapshot' => $this->closingSnapshot($property, $pipeline->branch_id),
                ],
            ],
        ];
    }

    private function closingSnapshot(Property $property, int $branchId): array
    {
        return array_filter([
            'property_id' => $property->id,
            'object_key' => $property->object_key,
            'closing_status' => $property->moderation_status,
            'closed_at' => $this->isoDate($property->sold_at),
            'branch_id' => $branchId,
            'created_by' => $property->created_by,
            'agent_id' => $property->agent_id,
            'sale_user_id' => $property->sale_user_id,
            'deposit_user_id' => $property->deposit_user_id,
            'owner' => array_filter([
                'client_id' => $property->owner_client_id,
                'name' => $property->owner_name,
                'phone' => $property->owner_phone,
            ], fn ($value) => $value !== null && $value !== ''),
            'buyer' => array_filter([
                'client_id' => $property->buyer_client_id,
                'name' => $property->buyer_full_name,
                'phone' => $property->buyer_phone,
            ], fn ($value) => $value !== null && $value !== ''),
            'actual_sale_price' => $property->actual_sale_price,
            'actual_sale_currency' => $property->actual_sale_currency,
            'company_commission_amount' => $property->company_commission_amount,
            'company_commission_currency' => $property->company_commission_currency,
            'money_holder' => $property->money_holder,
            'money_received_at' => $this->isoDate($property->money_received_at),
            'contract_signed_at' => $this->isoDate($property->contract_signed_at),
            'deposit_amount' => $property->deposit_amount,
            'deposit_currency' => $property->deposit_currency,
            'deposit_received_at' => $this->isoDate($property->deposit_received_at),
            'sale_agents' => Schema::hasTable('property_agent_sales') ? $property->saleAgents->map(fn (User $user) => [
                'id' => $user->id,
                'role' => $user->pivot?->role,
                'commission_amount' => $user->pivot?->agent_commission_amount,
                'commission_currency' => $user->pivot?->agent_commission_currency,
            ])->values()->all() : [],
            'status_comment' => $property->status_comment,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function ensureStages(DealPipeline $pipeline): void
    {
        foreach ($this->stageDefinitions() as $definition) {
            $pipeline->stages()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition
            );
        }
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(DATE_ATOM)
            : Carbon::parse((string) $value)->toIso8601String();
    }

    private function stageDefinitions(): array
    {
        return config('security-property-control.stages', []);
    }

    private function resolveStage(DealPipeline $pipeline, string $slug): DealStage
    {
        return $pipeline->stages->firstWhere('slug', $slug)
            ?: $pipeline->stages()->where('slug', $slug)->firstOrFail();
    }

    private function cardsQuery(Property $property)
    {
        return Deal::query()
            ->with($this->relations())
            ->where('primary_property_id', $property->id)
            ->where('control_kind', self::CONTROL_KIND)
            ->orderByDesc('id');
    }

    private function relations(): array
    {
        return [
            'client.type', 'branch', 'creator', 'responsibleAgent', 'updater',
            'pipeline.stages', 'stage', 'primaryProperty.ownerClient.type',
            'primaryProperty.logs.user', 'auditLogs.actor',
        ];
    }

    private function resolveBranchId(Property $property, ?User $actor = null): ?int
    {
        return $property->branch_id
            ?: $property->agent?->branch_id
            ?: $property->creator?->branch_id
            ?: $property->ownerClient?->branch_id
            ?: $actor?->branch_id;
    }

    private function stageSnapshot(Deal $deal): array
    {
        $deal->loadMissing('pipeline', 'stage');

        return array_filter([
            'pipeline_id' => $deal->pipeline_id,
            'pipeline_code' => $deal->pipeline?->code,
            'stage_id' => $deal->stage_id,
            'stage_slug' => $deal->stage?->slug,
            'stage_name' => $deal->stage?->name,
            'closed_at' => $deal->closed_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
