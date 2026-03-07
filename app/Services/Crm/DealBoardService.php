<?php

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DealBoardService
{
    public function nextPosition(DealStage $stage): int
    {
        return (int) Deal::query()
            ->where('pipeline_id', $stage->pipeline_id)
            ->where('stage_id', $stage->id)
            ->max('board_position') + 1;
    }

    public function reorderStages(DealPipeline $pipeline, array $stageIds): void
    {
        DB::transaction(function () use ($pipeline, $stageIds) {
            $stages = $pipeline->stages()->get()->keyBy('id');

            foreach (array_values($stageIds) as $index => $stageId) {
                $stage = $stages->get((int) $stageId);

                if (!$stage) {
                    continue;
                }

                $sortOrder = $index + 1;

                if ((int) $stage->sort_order !== $sortOrder) {
                    $stage->update(['sort_order' => $sortOrder]);
                }
            }
        });
    }

    public function moveDeal(Deal $deal, DealStage $targetStage, ?int $targetPosition = null, ?string $lostReason = null): Deal
    {
        return DB::transaction(function () use ($deal, $targetStage, $targetPosition, $lostReason) {
            $sourceStageId = (int) $deal->stage_id;

            if ($sourceStageId !== (int) $targetStage->id) {
                $this->reindexStage($deal->pipeline_id, $sourceStageId, $deal->id);
            }

            $targetIds = Deal::query()
                ->where('pipeline_id', $targetStage->pipeline_id)
                ->where('stage_id', $targetStage->id)
                ->whereKeyNot($deal->id)
                ->orderBy('board_position')
                ->orderBy('id')
                ->pluck('id')
                ->values()
                ->all();

            $position = $targetPosition === null
                ? count($targetIds)
                : max(0, min($targetPosition, count($targetIds)));

            array_splice($targetIds, $position, 0, [$deal->id]);

            $payload = [
                'pipeline_id' => $targetStage->pipeline_id,
                'stage_id' => $targetStage->id,
                'closed_at' => $targetStage->is_closed ? ($deal->closed_at ?: now()) : null,
                'lost_reason' => $targetStage->is_lost ? ($lostReason ?: $deal->lost_reason) : null,
            ];

            $deal->update($payload);

            foreach ($targetIds as $index => $dealId) {
                Deal::query()->whereKey($dealId)->update([
                    'board_position' => $index + 1,
                ]);
            }

            if (!$targetStage->is_lost && $deal->lost_reason) {
                $deal->update(['lost_reason' => null]);
            }

            return $deal->fresh([
                'client',
                'lead',
                'branch',
                'creator',
                'responsibleAgent',
                'pipeline',
                'stage',
                'primaryProperty',
                'auditLogs.actor',
            ]);
        });
    }

    private function reindexStage(int $pipelineId, int $stageId, ?int $excludeDealId = null): void
    {
        $query = Deal::query()
            ->where('pipeline_id', $pipelineId)
            ->where('stage_id', $stageId)
            ->orderBy('board_position')
            ->orderBy('id');

        if ($excludeDealId) {
            $query->whereKeyNot($excludeDealId);
        }

        /** @var Collection<int, Deal> $deals */
        $deals = $query->get();

        foreach ($deals as $index => $deal) {
            $position = $index + 1;

            if ((int) $deal->board_position !== $position) {
                $deal->update(['board_position' => $position]);
            }
        }
    }
}
