<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\DealBoardService;
use App\Support\DealAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(
        private readonly DealAccess $dealAccess,
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger,
        private readonly ActivityService $activityService
    ) {}

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
            'updater',
            'pipeline',
            'stage',
            'primaryProperty.ownerClient.type',
            'primaryProperty.logs.user',
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
            'updater',
            'pipeline',
            'stage',
            'primaryProperty.ownerClient.type',
        ];
    }

    private function parseIncludes(Request $request): array
    {
        return collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $include) => trim($include))
            ->filter()
            ->values()
            ->all();
    }

    private function appendActivitySummary(Model $subject): void
    {
        $latestActivities = $subject->auditLogs()
            ->with('actor')
            ->latest('id')
            ->limit(10)
            ->get();

        $subject->setAttribute('activities_count', $subject->auditLogs()->count());
        $subject->setAttribute(
            'latest_activity_at',
            $latestActivities->first()?->created_at?->toIso8601String()
        );
        $subject->setAttribute('latest_activities', $latestActivities);
    }

    private function attachShowIncludes(Deal $deal, array $includes): Deal
    {
        if (in_array('activities', $includes, true)) {
            $deal->setRelation('activities', $deal->auditLogs);
        }

        if (in_array('property_history', $includes, true)) {
            $deal->setAttribute('property_history', $deal->primaryProperty?->logs ?? collect());
        }

        return $deal;
    }

    private function validatePayload(Request $request, ?Deal $deal = null): array
    {
        $rules = [
            'title' => ($deal ? 'sometimes|' : '').'nullable|string|max:255',
            'client_id' => ($deal ? 'sometimes|' : '').'nullable|integer|exists:clients,id',
            'lead_id' => ($deal ? 'sometimes|' : '').'nullable|integer|exists:leads,id',
            'responsible_agent_id' => ($deal ? 'sometimes|' : '').'nullable|integer|exists:users,id',
            'pipeline_id' => ($deal ? 'sometimes|' : '').'required|integer|exists:crm_deal_pipelines,id',
            'stage_id' => ($deal ? 'sometimes|' : '').'nullable|integer|exists:crm_deal_stages,id',
            'primary_property_id' => ($deal ? 'sometimes|' : '').'nullable|integer|exists:properties,id',
            'amount' => ($deal ? 'sometimes|' : '').'nullable|numeric|min:0',
            'currency' => ($deal ? 'sometimes|' : '').'nullable|in:TJS,USD',
            'probability' => ($deal ? 'sometimes|' : '').'nullable|integer|min:0|max:100',
            'expected_company_income' => ($deal ? 'sometimes|' : '').'nullable|numeric|min:0',
            'expected_company_income_currency' => ($deal ? 'sometimes|' : '').'nullable|in:TJS,USD',
            'expected_agent_commission' => ($deal ? 'sometimes|' : '').'nullable|numeric|min:0',
            'expected_agent_commission_currency' => ($deal ? 'sometimes|' : '').'nullable|in:TJS,USD',
            'actual_company_income' => ($deal ? 'sometimes|' : '').'nullable|numeric|min:0',
            'actual_company_income_currency' => ($deal ? 'sometimes|' : '').'nullable|in:TJS,USD',
            'deadline_at' => ($deal ? 'sometimes|' : '').'nullable|date',
            'lost_reason' => 'nullable|string',
            'source' => ($deal ? 'sometimes|' : '').'nullable|string|max:100',
            'meta' => ($deal ? 'sometimes|' : '').'nullable|array',
            'note' => ($deal ? 'sometimes|' : '').'nullable|string',
            'tags' => ($deal ? 'sometimes|' : '').'nullable|array',
            'tags.*' => 'string|max:64',
            'last_contact_result' => ($deal ? 'sometimes|' : '').'nullable|string|max:100',
            'next_activity_at' => ($deal ? 'sometimes|' : '').'nullable|date',
            'source_property_status' => ($deal ? 'sometimes|' : '').'nullable|string|max:40',
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
        if (! $clientId) {
            return null;
        }

        $client = Client::query()->findOrFail($clientId);
        $this->dealAccess->ensureClientVisible($authUser, $client);

        return $client;
    }

    private function resolveVisibleLead(User $authUser, ?int $leadId): ?Lead
    {
        if (! $leadId) {
            return null;
        }

        $lead = Lead::query()->findOrFail($leadId);
        $this->dealAccess->ensureLeadVisible($authUser, $lead);

        return $lead;
    }

    private function resolveProperty(?int $propertyId): ?Property
    {
        if (! $propertyId) {
            return null;
        }

        return Property::query()
            ->with(['ownerClient.type', 'logs.user'])
            ->findOrFail($propertyId);
    }

    private function normalizeInput(array $data, ?Client $client, ?Lead $lead, ?Property $property): array
    {
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }

        if ((empty($data['title']) || trim((string) $data['title']) === '') && $client) {
            $data['title'] = 'Сделка: '.$client->full_name;
        } elseif ((empty($data['title']) || trim((string) $data['title']) === '') && $lead) {
            $data['title'] = 'Сделка по лиду: '.($lead->full_name ?: ('Lead #'.$lead->id));
        } elseif ((empty($data['title']) || trim((string) $data['title']) === '') && $property) {
            $data['title'] = $property->title ?: ('Контроль объекта #'.$property->id);
        }

        if ($lead && empty($data['client_id']) && ! empty($lead->client_id)) {
            $data['client_id'] = $lead->client_id;
        }

        if ($property && empty($data['client_id']) && ! empty($property->owner_client_id)) {
            $data['client_id'] = $property->owner_client_id;
        }

        if (array_key_exists('source', $data) && $data['source']) {
            $data['source'] = trim((string) $data['source']);
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = $this->activityService->normalizeTags($data['tags']);
        }

        if (array_key_exists('note', $data) && $data['note']) {
            $data['note'] = trim((string) $data['note']);
        }

        if (array_key_exists('last_contact_result', $data) && $data['last_contact_result']) {
            $data['last_contact_result'] = trim((string) $data['last_contact_result']);
        }

        foreach (['deadline_at', 'next_activity_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->setTimezone(config('app.timezone'));
            }
        }

        if ($property && empty($data['source_property_status']) && ! empty($property->moderation_status)) {
            $data['source_property_status'] = $property->moderation_status;
        }

        return $data;
    }

    private function inheritLeadFields(array $data, Lead $lead): array
    {
        foreach ([
            'source' => $lead->source,
            'branch_id' => $lead->branch_id,
            'responsible_agent_id' => $lead->responsible_agent_id,
            'client_id' => $lead->client_id,
            'note' => $lead->note,
            'tags' => $lead->tags,
            'last_contact_result' => $lead->last_contact_result,
        ] as $field => $value) {
            if (! array_key_exists($field, $data) && $value !== null) {
                $data[$field] = $value;
            }
        }

        if (! array_key_exists('next_activity_at', $data)) {
            $data['next_activity_at'] = $lead->next_activity_at ?: $lead->next_follow_up_at;
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $data['meta'] = array_replace_recursive($meta, [
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
                'client_id' => $lead->client_id,
                'tags' => $lead->tags,
                'last_contact_result' => $lead->last_contact_result,
                'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
                'next_activity_at' => $lead->next_activity_at?->toIso8601String(),
            ], fn ($value) => $value !== null && $value !== ''),
        ]);

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

        if (! $stage->is_lost && ! array_key_exists('lost_reason', $data)) {
            $data['lost_reason'] = null;
        }

        return $data;
    }

    private function isoValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
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
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function logTypedUpdates(Deal $deal, User $authUser, array $oldValues, array $dirty, ?array $oldStageSnapshot = null): void
    {
        $handled = [];

        if (array_key_exists('responsible_agent_id', $dirty)) {
            $this->activityService->logAssignment(
                $deal,
                $authUser,
                isset($oldValues['responsible_agent_id']) ? (int) $oldValues['responsible_agent_id'] : null,
                $deal->responsible_agent_id ? (int) $deal->responsible_agent_id : null,
                ['deal_id' => $deal->id]
            );

            $handled[] = 'responsible_agent_id';
        }

        if (array_key_exists('tags', $dirty)) {
            $this->activityService->logTagDiff(
                $deal,
                $authUser,
                $this->activityService->normalizeTags($oldValues['tags'] ?? []),
                $this->activityService->normalizeTags($deal->tags ?? []),
                ['deal_id' => $deal->id]
            );

            $handled[] = 'tags';
        }

        if (array_key_exists('next_activity_at', $dirty)) {
            $this->activityService->logFollowUpChange(
                $deal,
                $authUser,
                ['next_activity_at' => $this->isoValue($oldValues['next_activity_at'] ?? null)],
                ['next_activity_at' => $deal->next_activity_at?->toIso8601String()],
                ['deal_id' => $deal->id]
            );

            $handled[] = 'next_activity_at';
        }

        if (array_key_exists('last_contact_result', $dirty) && ! empty($deal->last_contact_result)) {
            $this->activityService->logCall(
                $deal,
                $authUser,
                $deal->last_contact_result,
                null,
                null,
                ['deal_id' => $deal->id, 'source' => 'deal_update']
            );

            $handled[] = 'last_contact_result';
        }

        if (
            array_key_exists('stage_id', $dirty)
            || array_key_exists('pipeline_id', $dirty)
            || array_key_exists('closed_at', $dirty)
        ) {
            $this->activityService->logStatusChange(
                $deal,
                $authUser,
                $oldStageSnapshot ?: $oldValues,
                $this->stageSnapshot($deal),
                ['deal_id' => $deal->id],
                'Deal stage changed.'
            );

            $handled = array_merge($handled, ['stage_id', 'pipeline_id', 'closed_at']);
        }

        $remainingKeys = array_values(array_diff(array_keys($dirty), array_merge($handled, [
            'updated_by',
        ])));

        if (! empty($remainingKeys)) {
            $this->auditLogger->log(
                $deal,
                $authUser,
                'updated',
                Arr::only($oldValues, $remainingKeys),
                Arr::only($deal->getAttributes(), $remainingKeys),
                'Deal updated.'
            );
        }
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'search' => 'nullable|string',
            'pipeline_id' => 'nullable|integer|exists:crm_deal_pipelines,id',
            'pipeline_code' => 'nullable|string|max:255',
            'pipeline_type' => 'nullable|string|max:255',
            'stage_id' => 'nullable|integer|exists:crm_deal_stages,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'primary_property_id' => 'nullable|integer|exists:properties,id',
            'source_property_status' => 'nullable|string|max:40',
            'source' => 'nullable|string|max:100',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'overdue_activity' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->dealAccess->visibleQuery($authUser)->with($this->listRelations());

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('title', 'like', '%'.$term.'%')
                    ->orWhere('source', 'like', '%'.$term.'%')
                    ->orWhereHas('client', fn ($q) => $q->where('full_name', 'like', '%'.$term.'%'))
                    ->orWhereHas('lead', fn ($q) => $q->where('full_name', 'like', '%'.$term.'%'));
            });
        }

        foreach (['pipeline_id', 'stage_id', 'client_id', 'lead_id', 'responsible_agent_id', 'primary_property_id', 'source_property_status'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (! empty($validated['source'])) {
            $query->where('source', trim($validated['source']));
        }

        if (! empty($validated['pipeline_code'])) {
            $query->whereHas('pipeline', fn ($builder) => $builder->where('code', trim($validated['pipeline_code'])));
        }

        if (! empty($validated['pipeline_type'])) {
            $query->whereHas('pipeline', fn ($builder) => $builder->where('type', trim($validated['pipeline_type'])));
        }

        if ($this->dealAccess->isPrivilegedRole($this->dealAccess->roleSlug($authUser)) && ! empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (array_key_exists('overdue_activity', $validated) && $validated['overdue_activity'] !== null) {
            if ($request->boolean('overdue_activity')) {
                $query
                    ->whereNull('closed_at')
                    ->whereNotNull('next_activity_at')
                    ->where('next_activity_at', '<', now());
            } else {
                $query->where(function ($builder) {
                    $builder
                        ->whereNotNull('closed_at')
                        ->orWhereNull('next_activity_at')
                        ->orWhere('next_activity_at', '>=', now());
                });
            }
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
        $property = $this->resolveProperty($data['primary_property_id'] ?? null);
        $data = $this->normalizeInput($data, $client, $lead, $property);
        if ($lead) {
            $data = $this->inheritLeadFields($data, $lead);
        }
        $data = $this->dealAccess->normalizeMutationData($data, $authUser);
        $data['updated_by'] = $authUser->id;
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
                'source',
                'primary_property_id',
                'source_property_status',
                'note',
                'tags',
                'last_contact_result',
                'next_activity_at',
            ]),
            'Deal created.'
        );

        if ($lead) {
            $this->auditLogger->log(
                $deal,
                $authUser,
                'created_from_lead',
                [],
                array_filter([
                    'lead_id' => $lead->id,
                    'source' => $deal->source,
                    'origin' => Arr::get($deal->meta, 'origin'),
                ], fn ($value) => $value !== null && $value !== ''),
                'Deal created from lead.',
                array_filter([
                    'lead_id' => $lead->id,
                    'lead_snapshot' => Arr::get($deal->meta, 'lead_snapshot'),
                ], fn ($value) => $value !== null && $value !== '')
            );
        }

        return response()->json($deal->load($this->relations()), 201);
    }

    public function show(Request $request, Deal $deal)
    {
        $this->dealAccess->ensureVisible($this->authUser(), $deal);

        $deal->load($this->relations());
        $this->appendActivitySummary($deal);
        $this->attachShowIncludes($deal, $this->parseIncludes($request));

        return response()->json($deal);
    }

    public function update(Request $request, Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $data = $this->validatePayload($request, $deal);
        $client = $this->resolveVisibleClient($authUser, $data['client_id'] ?? $deal->client_id);
        $lead = $this->resolveVisibleLead($authUser, $data['lead_id'] ?? $deal->lead_id);
        $property = $this->resolveProperty($data['primary_property_id'] ?? $deal->primary_property_id);
        $data = $this->normalizeInput($data, $client, $lead, $property);
        $data = $this->dealAccess->normalizeMutationData($data, $authUser);
        $data['updated_by'] = $authUser->id;
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
        $oldStageSnapshot = $this->stageSnapshot($deal);
        $deal->fill($data);
        $dirty = $deal->getDirty();

        if (! empty($dirty)) {
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

            $this->logTypedUpdates($deal, $authUser, $oldValues, $dirty, $oldStageSnapshot);
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

        $oldValues = array_merge($this->stageSnapshot($deal), [
            'board_position' => $deal->board_position,
            'lost_reason' => $deal->lost_reason,
        ]);

        $movedDeal = $this->boardService->moveDeal(
            $deal,
            $targetStage,
            $validated['position'] ?? null,
            $validated['lost_reason'] ?? null
        );

        if ((int) $movedDeal->updated_by !== (int) $authUser->id) {
            $movedDeal->update(['updated_by' => $authUser->id]);
            $movedDeal = $movedDeal->fresh($this->relations());
        }

        $this->activityService->logStatusChange(
            $movedDeal,
            $authUser,
            $oldValues,
            array_merge($this->stageSnapshot($movedDeal), [
                'board_position' => $movedDeal->board_position,
                'lost_reason' => $movedDeal->lost_reason,
            ]),
            ['deal_id' => $movedDeal->id],
            'Deal moved on board.'
        );

        return response()->json($movedDeal);
    }
}
