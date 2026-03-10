<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PropertyControlService
{
    private const TRIGGER_STATUSES = ['deleted', 'sold_by_owner'];

    public function __construct(
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger,
        private readonly ActivityService $activityService
    ) {}

    public function syncForProperty(Property $property, ?User $actor = null): ?Deal
    {
        $property->loadMissing(['agent.role', 'creator.role', 'ownerClient.type', 'logs.user']);

        $branchId = $this->resolveBranchId($property, $actor);

        if (! $branchId) {
            return null;
        }

        $pipeline = $this->ensurePipeline($branchId);

        if (in_array($property->moderation_status, self::TRIGGER_STATUSES, true)) {
            return $this->openOrReopen($property, $pipeline, $actor);
        }

        return $this->closeAsReactivated($property, $pipeline, $actor);
    }

    public function ensurePipeline(int $branchId): DealPipeline
    {
        $existing = DealPipeline::query()
            ->with(['stages', 'defaultStage'])
            ->where('branch_id', $branchId)
            ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($branchId) {
            $pipeline = DealPipeline::query()
                ->where('branch_id', $branchId)
                ->where('code', DealPipeline::CODE_PROPERTY_CONTROL)
                ->first();

            if ($pipeline) {
                return $pipeline->load(['stages', 'defaultStage']);
            }

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
                    'role_scope' => 'manager',
                ],
            ]);

            foreach ($this->stageDefinitions() as $stage) {
                $pipeline->stages()->create($stage);
            }

            return $pipeline->load(['stages', 'defaultStage']);
        });
    }

    private function openOrReopen(Property $property, DealPipeline $pipeline, ?User $actor): Deal
    {
        return DB::transaction(function () use ($property, $pipeline, $actor) {
            $cards = $this->cardsQuery($property)
                ->get();

            /** @var Deal|null $openCard */
            $openCard = $cards->first(fn (Deal $deal) => ! $deal->is_closed);
            $payload = $this->cardPayload($property, $pipeline, $actor);

            if ($openCard) {
                $dirty = $this->fillAndPersist($openCard, $payload, $actor);

                if (! empty($dirty)) {
                    $this->auditLogger->log(
                        $openCard,
                        $actor,
                        'property_control_synced',
                        Arr::only($openCard->getOriginal(), array_keys($dirty)),
                        Arr::only($openCard->getAttributes(), array_keys($dirty)),
                        'Property control card synced from property status.',
                        ['property_status' => $property->moderation_status]
                    );
                }

                return $openCard->fresh($this->relations());
            }

            /** @var DealStage $defaultStage */
            $defaultStage = $this->resolveStage($pipeline, 'new');
            /** @var Deal|null $latestCard */
            $latestCard = $cards->first();

            if ($latestCard) {
                $beforeMove = $this->stageSnapshot($latestCard);

                $this->fillAndPersist($latestCard, array_merge($payload, [
                    'closed_at' => null,
                ]), $actor);

                $moved = $this->boardService->moveDeal($latestCard, $defaultStage);

                $this->activityService->logStatusChange(
                    $moved,
                    $actor,
                    $beforeMove,
                    $this->stageSnapshot($moved),
                    ['property_status' => $property->moderation_status],
                    'Property control card reopened from property status.'
                );

                return $moved;
            }

            $deal = Deal::create(array_merge($payload, [
                'stage_id' => $defaultStage->id,
                'board_position' => $this->boardService->nextPosition($defaultStage),
                'currency' => 'TJS',
                'expected_company_income_currency' => 'TJS',
                'expected_agent_commission_currency' => 'TJS',
                'actual_company_income_currency' => 'TJS',
            ]));

            $this->auditLogger->log(
                $deal,
                $actor,
                'created',
                [],
                Arr::only($deal->getAttributes(), [
                    'title',
                    'client_id',
                    'branch_id',
                    'responsible_agent_id',
                    'pipeline_id',
                    'stage_id',
                    'primary_property_id',
                    'source_property_status',
                ]),
                'Property control card created.',
                ['property_status' => $property->moderation_status]
            );

            return $deal->fresh($this->relations());
        });
    }

    private function closeAsReactivated(Property $property, DealPipeline $pipeline, ?User $actor): ?Deal
    {
        /** @var Deal|null $openCard */
        $openCard = $this->cardsQuery($property)
            ->get()
            ->first(fn (Deal $deal) => ! $deal->is_closed);

        if (! $openCard) {
            return null;
        }

        /** @var DealStage $targetStage */
        $targetStage = $this->resolveStage($pipeline, 'reactivated');
        $beforeMove = $this->stageSnapshot($openCard);

        $this->fillAndPersist($openCard, [
            'source_property_status' => $property->moderation_status,
            'updated_by' => $actor?->id,
            'client_id' => $property->owner_client_id,
            'meta' => $this->mergeMeta($openCard->meta, $property),
        ], $actor);

        $closed = $this->boardService->moveDeal($openCard, $targetStage);

        $this->activityService->logStatusChange(
            $closed,
            $actor,
            $beforeMove,
            $this->stageSnapshot($closed),
            ['property_status' => $property->moderation_status],
            'Property returned to active status, card closed as reactivated.'
        );

        return $closed;
    }

    private function fillAndPersist(Deal $deal, array $payload, ?User $actor): array
    {
        $deal->fill($payload);

        if ($actor?->id) {
            $deal->updated_by = $actor->id;
        }

        $dirty = $deal->getDirty();

        if (! empty($dirty)) {
            $deal->save();
        }

        return $dirty;
    }

    private function cardPayload(Property $property, DealPipeline $pipeline, ?User $actor): array
    {
        return [
            'title' => $property->title ?: ('Контроль объекта #'.$property->id),
            'client_id' => $property->owner_client_id,
            'branch_id' => $pipeline->branch_id,
            'created_by' => $actor?->id ?: $property->created_by ?: $property->agent_id,
            'pipeline_id' => $pipeline->id,
            'primary_property_id' => $property->id,
            'source_property_status' => $property->moderation_status,
            'note' => $property->status_comment ?: null,
            'updated_by' => $actor?->id,
            'meta' => $this->mergeMeta([], $property),
        ];
    }

    private function mergeMeta(?array $currentMeta, Property $property): array
    {
        $snapshot = array_filter([
            'name' => $property->owner_name,
            'phone' => $property->owner_phone,
        ], fn ($value) => $value !== null && $value !== '');

        return array_merge($currentMeta ?? [], [
            'owner_contact_snapshot' => $snapshot ?: null,
            'property_status_history_available' => true,
            'source_property_status' => $property->moderation_status,
        ]);
    }

    private function resolveStage(DealPipeline $pipeline, string $slug): DealStage
    {
        return $pipeline->stages->firstWhere('slug', $slug)
            ?: $pipeline->stages()->where('slug', $slug)->firstOrFail();
    }

    private function stageDefinitions(): array
    {
        return [
            ['name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'На проверке', 'slug' => 'in_review', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Связались', 'slug' => 'contacted', 'color' => '#0891b2', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Ждём владельца', 'slug' => 'waiting_owner', 'color' => '#f59e0b', 'sort_order' => 40, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Возврат в работу', 'slug' => 'reactivation_in_progress', 'color' => '#8b5cf6', 'sort_order' => 50, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Реактивирован', 'slug' => 'reactivated', 'color' => '#16a34a', 'sort_order' => 60, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Продан владельцем', 'slug' => 'owner_sold_confirmed', 'color' => '#dc2626', 'sort_order' => 70, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
            ['name' => 'Нет ответа', 'slug' => 'no_answer', 'color' => '#f97316', 'sort_order' => 80, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Неактуально', 'slug' => 'not_relevant', 'color' => '#6b7280', 'sort_order' => 90, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
            ['name' => 'Закрыто', 'slug' => 'closed', 'color' => '#111827', 'sort_order' => 100, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
        ];
    }

    private function cardsQuery(Property $property)
    {
        return Deal::query()
            ->with($this->relations())
            ->where('primary_property_id', $property->id)
            ->whereHas('pipeline', fn ($query) => $query->where('code', DealPipeline::CODE_PROPERTY_CONTROL))
            ->orderByDesc('id');
    }

    private function relations(): array
    {
        return [
            'client.type',
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'pipeline',
            'stage',
            'primaryProperty.ownerClient.type',
            'primaryProperty.logs.user',
            'auditLogs.actor',
        ];
    }

    private function resolveBranchId(Property $property, ?User $actor = null): ?int
    {
        return $property->agent?->branch_id
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
            'closed_at' => optional($deal->closed_at)?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
