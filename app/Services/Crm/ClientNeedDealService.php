<?php

namespace App\Services\Crm;

use App\Models\ClientNeed;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClientNeedDealService
{
    public function __construct(
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** Called after need authorization, inside the client's write transaction. */
    public function ensureForNeed(ClientNeed $need, User $actor): Deal
    {
        return DB::transaction(function () use ($need, $actor) {
            $need = ClientNeed::query()->lockForUpdate()->findOrFail($need->id);

            // Retrying automation must neither duplicate nor restore a deleted deal.
            $existing = Deal::withTrashed()->where('client_need_id', $need->id)->orderBy('id')->first();
            if ($existing) {
                return $existing;
            }

            $pipeline = DealPipeline::query()
                ->whereNull('branch_id')
                ->where('is_default', true)
                ->where('is_active', true)
                ->where('type', DealPipeline::TYPE_SALES)
                ->where(fn ($query) => $query->whereNull('code')->orWhereNotIn('code', [
                    DealPipeline::CODE_PROPERTY_CONTROL,
                    DealPipeline::CODE_HR_RECRUITMENT,
                ]))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
            abort_unless($pipeline, 422, 'Для создания потребности настройте активную основную воронку сделок.');

            $stage = $pipeline->stages()
                ->where('is_active', true)
                ->where('is_closed', false)
                ->where('is_lost', false)
                ->reorder()
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            abort_unless($stage, 422, 'В основной воронке нет активной открытой стадии для новой сделки.');

            $need->loadMissing(['client', 'type']);
            $client = $need->client;
            $deal = Deal::create([
                'title' => mb_substr(($need->type?->name ?: 'Потребность').': '.$client->full_name, 0, 255),
                'client_id' => $client->id,
                'client_need_id' => $need->id,
                'branch_id' => $client->branch_id,
                'branch_group_id' => $client->branch_group_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'responsible_agent_id' => $need->responsible_agent_id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'amount' => $need->budget_total ?? $need->budget_to ?? $need->budget_from,
                'currency' => $need->currency ?: 'TJS',
                'note' => $need->comment,
                'board_position' => $this->boardService->nextPosition($stage),
                'meta' => ['origin' => ['type' => 'client_need', 'client_need_id' => $need->id]],
            ]);

            $this->auditLogger->log(
                $deal,
                $actor,
                'created_from_client_need',
                [],
                ['client_id' => $client->id, 'client_need_id' => $need->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id],
                'Сделка создана из потребности клиента',
            );

            return $deal;
        });
    }
}
