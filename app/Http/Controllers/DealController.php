<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\DealBoardService;
use App\Support\DealAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(
        private readonly DealAccess $dealAccess,
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger
    ) {
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function relations(): array
    {
        return [
            'client',
            'lead',
            'branch',
            'creator',
            'responsibleAgent',
            'pipeline',
            'stage',
            'primaryProperty',
            'auditLogs.actor',
        ];
    }

    private function listRelations(): array
    {
        return [
            'client',
            'lead',
            'branch',
            'creator',
            'responsibleAgent',
            'pipeline',
            'stage',
            'primaryProperty',
        ];
    }

    private function validatePayload(Request $request, ?Deal $deal = null): array
    {
        $rules = [
            'title' => ($deal ? 'sometimes|' : '') . 'nullable|string|max:255',
            'client_id' => ($deal ? 'sometimes|' : '') . 'nullable|integer|exists:clients,id',
            'lead_id' => ($deal ? 'sometimes|' : '') . 'nullable|integer|exists:leads,id',
            'responsible_agent_id' => ($deal ? 'sometimes|' : '') . 'nullable|integer|exists:users,id',
            'pipeline_id' => ($deal ? 'sometimes|' : '') . 'required|integer|exists:crm_deal_pipelines,id',
            'stage_id' => ($deal ? 'sometimes|' : '') . 'nullable|integer|exists:crm_deal_stages,id',
            'primary_property_id' => ($deal ? 'sometimes|' : '') . 'nullable|integer|exists:properties,id',
            'amount' => ($deal ? 'sometimes|' : '') . 'nullable|numeric|min:0',
            'currency' => ($deal ? 'sometimes|' : '') . 'nullable|in:TJS,USD',
            'probability' => ($deal ? 'sometimes|' : '') . 'nullable|integer|min:0|max:100',
            'expected_company_income' => ($deal ? 'sometimes|' : '') . 'nullable|numeric|min:0',
            'expected_company_income_currency' => ($deal ? 'sometimes|' : '') . 'nullable|in:TJS,USD',
            'expected_agent_commission' => ($deal ? 'sometimes|' : '') . 'nullable|numeric|min:0',
            'expected_agent_commission_currency' => ($deal ? 'sometimes|' : '') . 'nullable|in:TJS,USD',
            'actual_company_income' => ($deal ? 'sometimes|' : '') . 'nullable|numeric|min:0',
            'actual_company_income_currency' => ($deal ? 'sometimes|' : '') . 'nullable|in:TJS,USD',
            'deadline_at' => ($deal ? 'sometimes|' : '') . 'nullable|date',
            'lost_reason' => 'nullable|string',
            'source' => ($deal ? 'sometimes|' : '') . 'nullable|string|max:100',
            'meta' => ($deal ? 'sometimes|' : '') . 'nullable|array',
        ];

        $validated = $request->validate($rules);

        $title = trim((string) ($validated['title'] ?? $deal?->title ?? ''));
        $clientId = $validated['client_id'] ?? $deal?->client_id;
        $leadId = $validated['lead_id'] ?? $deal?->lead_id;

        if ($title === '' && empty($clientId) && empty($leadId)) {
            throw ValidationException::withMessages([
                'title' => ['Either title, client_id or lead_id must be provided.'],
            ]);
        }

        return $validated;
    }

    private function resolveVisibleClient(User $authUser, ?int $clientId): ?Client
    {
        if (!$clientId) {
            return null;
        }

        $client = Client::query()->findOrFail($clientId);
        $this->dealAccess->ensureClientVisible($authUser, $client);

        return $client;
    }

    private function resolveVisibleLead(User $authUser, ?int $leadId): ?Lead
    {
        if (!$leadId) {
            return null;
        }

        $lead = Lead::query()->findOrFail($leadId);
        $this->dealAccess->ensureLeadVisible($authUser, $lead);

        return $lead;
    }

    private function normalizeInput(array $data, ?Client $client, ?Lead $lead): array
    {
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }

        if ((empty($data['title']) || trim((string) $data['title']) === '') && $client) {
            $data['title'] = 'Сделка: ' . $client->full_name;
        } elseif ((empty($data['title']) || trim((string) $data['title']) === '') && $lead) {
            $data['title'] = 'Сделка по лиду: ' . ($lead->full_name ?: ('Lead #' . $lead->id));
        }

        if ($lead && empty($data['client_id']) && !empty($lead->client_id)) {
            $data['client_id'] = $lead->client_id;
        }

        if (array_key_exists('source', $data) && $data['source']) {
            $data['source'] = trim((string) $data['source']);
        }

        return $data;
    }

    private function resolveStage(DealPipeline $pipeline, ?int $stageId = null): DealStage
    {
        if ($stageId) {
            $stage = DealStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->findOrFail($stageId);

            return $stage;
        }

        return $pipeline->defaultStage()->first()
            ?: $pipeline->stages()->orderBy('sort_order')->firstOrFail();
    }

    private function applyStageState(array $data, DealStage $stage, ?Deal $deal = null): array
    {
        if ($stage->is_closed) {
            $data['closed_at'] = $deal?->closed_at ?: now();
        } else {
            $data['closed_at'] = null;
        }

        if (!$stage->is_lost && !array_key_exists('lost_reason', $data)) {
            $data['lost_reason'] = null;
        }

        return $data;
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'search' => 'nullable|string',
            'pipeline_id' => 'nullable|integer|exists:crm_deal_pipelines,id',
            'stage_id' => 'nullable|integer|exists:crm_deal_stages,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->dealAccess->visibleQuery($authUser)->with($this->listRelations());

        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('title', 'like', '%' . $term . '%')
                    ->orWhere('source', 'like', '%' . $term . '%')
                    ->orWhereHas('client', fn ($q) => $q->where('full_name', 'like', '%' . $term . '%'))
                    ->orWhereHas('lead', fn ($q) => $q->where('full_name', 'like', '%' . $term . '%'));
            });
        }

        foreach (['pipeline_id', 'stage_id', 'client_id', 'lead_id', 'responsible_agent_id'] as $field) {
            if (!empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if ($this->dealAccess->isPrivilegedRole($this->dealAccess->roleSlug($authUser)) && !empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $data = $this->validatePayload($request);
        $client = $this->resolveVisibleClient($authUser, $data['client_id'] ?? null);
        $lead = $this->resolveVisibleLead($authUser, $data['lead_id'] ?? null);
        $data = $this->normalizeInput($data, $client, $lead);
        $data = $this->dealAccess->normalizeMutationData($data, $authUser);
        $this->dealAccess->validateMutationTargets($authUser, $data);

        $pipeline = DealPipeline::query()->findOrFail($data['pipeline_id']);
        $this->dealAccess->ensurePipelineVisible($authUser, $pipeline);

        $stage = $this->resolveStage($pipeline, $data['stage_id'] ?? null);
        $data['pipeline_id'] = $pipeline->id;
        $data['stage_id'] = $stage->id;
        $data = $this->applyStageState($data, $stage);
        $data['board_position'] = $this->boardService->nextPosition($stage);
        $data['currency'] ??= 'TJS';
        $data['expected_company_income_currency'] ??= 'TJS';
        $data['expected_agent_commission_currency'] ??= 'TJS';
        $data['actual_company_income_currency'] ??= 'TJS';

        $deal = Deal::create($data);

        $this->auditLogger->log(
            $deal,
            $authUser,
            'created',
            [],
            Arr::only($deal->getAttributes(), [
                'title',
                'client_id',
                'lead_id',
                'branch_id',
                'responsible_agent_id',
                'pipeline_id',
                'stage_id',
                'board_position',
                'amount',
                'currency',
            ]),
            'Deal created.'
        );

        return response()->json($deal->load($this->relations()), 201);
    }

    public function show(Deal $deal)
    {
        $this->dealAccess->ensureVisible($this->authUser(), $deal);

        return response()->json($deal->load($this->relations()));
    }

    public function update(Request $request, Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $data = $this->validatePayload($request, $deal);
        $client = $this->resolveVisibleClient($authUser, $data['client_id'] ?? $deal->client_id);
        $lead = $this->resolveVisibleLead($authUser, $data['lead_id'] ?? $deal->lead_id);
        $data = $this->normalizeInput($data, $client, $lead);
        $data = $this->dealAccess->normalizeMutationData($data, $authUser);
        $this->dealAccess->validateMutationTargets($authUser, array_merge([
            'created_by' => $deal->created_by,
            'branch_id' => $deal->branch_id,
        ], $data));

        $pipelineId = $data['pipeline_id'] ?? $deal->pipeline_id;
        $pipeline = DealPipeline::query()->findOrFail($pipelineId);
        $this->dealAccess->ensurePipelineVisible($authUser, $pipeline);

        $stageId = $data['stage_id'] ?? $deal->stage_id;
        $stage = $this->resolveStage($pipeline, $stageId);
        $data['pipeline_id'] = $pipeline->id;
        $data['stage_id'] = $stage->id;
        $data = $this->applyStageState($data, $stage, $deal);

        $originalPipelineId = (int) $deal->pipeline_id;
        $originalStageId = (int) $deal->stage_id;
        $deal->fill($data);
        $dirty = $deal->getDirty();

        if (!empty($dirty)) {
            $oldValues = Arr::only($deal->getOriginal(), array_keys($dirty));
            $deal->save();

            if (
                $originalPipelineId !== (int) $deal->pipeline_id
                || $originalStageId !== (int) $deal->stage_id
            ) {
                $deal = $this->boardService->moveDeal(
                    $deal,
                    $stage,
                    null,
                    $data['lost_reason'] ?? null
                );
            }

            $this->auditLogger->log(
                $deal,
                $authUser,
                'updated',
                $oldValues,
                Arr::only($deal->getAttributes(), array_keys($dirty)),
                'Deal updated.'
            );
        }

        return response()->json($deal->fresh($this->relations()));
    }

    public function destroy(Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $this->auditLogger->log(
            $deal,
            $authUser,
            'deleted',
            Arr::only($deal->getAttributes(), [
                'title',
                'client_id',
                'lead_id',
                'pipeline_id',
                'stage_id',
                'responsible_agent_id',
            ]),
            [],
            'Deal deleted.'
        );

        $deal->delete();

        return response()->json(['message' => 'Deal deleted']);
    }

    public function move(Request $request, Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $validated = $request->validate([
            'stage_id' => 'required|integer|exists:crm_deal_stages,id',
            'position' => 'nullable|integer|min:0',
            'lost_reason' => 'nullable|string',
        ]);

        $targetStage = DealStage::query()->with('pipeline')->findOrFail($validated['stage_id']);
        $this->dealAccess->ensurePipelineVisible($authUser, $targetStage->pipeline);

        $oldValues = [
            'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id,
            'board_position' => $deal->board_position,
            'closed_at' => optional($deal->closed_at)?->toIso8601String(),
            'lost_reason' => $deal->lost_reason,
        ];

        $movedDeal = $this->boardService->moveDeal(
            $deal,
            $targetStage,
            $validated['position'] ?? null,
            $validated['lost_reason'] ?? null
        );

        $this->auditLogger->log(
            $movedDeal,
            $authUser,
            'moved',
            $oldValues,
            [
                'pipeline_id' => $movedDeal->pipeline_id,
                'stage_id' => $movedDeal->stage_id,
                'board_position' => $movedDeal->board_position,
                'closed_at' => optional($movedDeal->closed_at)?->toIso8601String(),
                'lost_reason' => $movedDeal->lost_reason,
            ],
            'Deal moved on board.'
        );

        return response()->json($movedDeal);
    }
}
