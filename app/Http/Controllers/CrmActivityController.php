<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\DealBoardService;
use App\Support\DealAccess;
use App\Support\LeadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CrmActivityController extends Controller
{
    public function __construct(
        private readonly LeadAccess $leadAccess,
        private readonly DealAccess $dealAccess,
        private readonly ActivityService $activityService,
        private readonly DealBoardService $boardService
    ) {}

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function leadRelations(): array
    {
        return [
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'client',
            'auditLogs.actor',
        ];
    }

    private function dealRelations(): array
    {
        return [
            'client.type',
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

    private function validateActivity(Request $request, bool $forDeal = false): array
    {
        return $request->validate([
            'type' => [
                'required',
                Rule::in([
                    'comment',
                    'tag_added',
                    'tag_removed',
                    'call',
                    'status_change',
                    'assignment',
                    'follow_up_changed',
                ]),
            ],
            'comment' => 'nullable|string',
            'tag' => 'nullable|string|max:64',
            'result' => 'nullable|string|max:100',
            'duration_seconds' => 'nullable|integer|min:0',
            'note' => 'nullable|string',
            'status' => $forDeal ? 'nullable' : ['nullable', Rule::in(array_diff(Lead::statuses(), [Lead::STATUS_CONVERTED]))],
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'next_follow_up_at' => 'nullable|date',
            'next_activity_at' => 'nullable|date',
            'stage_id' => $forDeal ? 'nullable|integer|exists:crm_deal_stages,id' : 'nullable',
            'position' => $forDeal ? 'nullable|integer|min:0' : 'nullable',
            'lost_reason' => $forDeal ? 'nullable|string' : 'nullable',
        ]);
    }

    public function leadIndex(Request $request, Lead $lead)
    {
        $authUser = $this->authUser();
        $this->leadAccess->ensureVisible($authUser, $lead);

        $validated = $request->validate([
            'type' => 'nullable|string|max:50',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $lead->auditLogs()->with('actor');

        if (! empty($validated['type'])) {
            $query->where('event', trim($validated['type']));
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['date_from']));
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['date_to']));
        }

        return response()->json(
            $query->paginate((int) ($validated['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    public function leadStore(Request $request, Lead $lead)
    {
        $authUser = $this->authUser();
        $this->leadAccess->ensureVisible($authUser, $lead);

        $data = $this->validateActivity($request);

        match ($data['type']) {
            'comment' => $this->leadComment($lead, $authUser, $data),
            'tag_added' => $this->leadTagAdded($lead, $authUser, $data),
            'tag_removed' => $this->leadTagRemoved($lead, $authUser, $data),
            'call' => $this->leadCall($lead, $authUser, $data),
            'status_change' => $this->leadStatusChange($lead, $authUser, $data),
            'assignment' => $this->leadAssignment($lead, $authUser, $data),
            'follow_up_changed' => $this->leadFollowUpChanged($lead, $authUser, $data),
        };

        return response()->json($lead->fresh($this->leadRelations()));
    }

    public function dealIndex(Request $request, Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $validated = $request->validate([
            'type' => 'nullable|string|max:50',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $deal->auditLogs()->with('actor');

        if (! empty($validated['type'])) {
            $query->where('event', trim($validated['type']));
        }

        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['date_from']));
        }

        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['date_to']));
        }

        return response()->json(
            $query->paginate((int) ($validated['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    public function dealStore(Request $request, Deal $deal)
    {
        $authUser = $this->authUser();
        $this->dealAccess->ensureVisible($authUser, $deal);

        $data = $this->validateActivity($request, true);

        match ($data['type']) {
            'comment' => $this->dealComment($deal, $authUser, $data),
            'tag_added' => $this->dealTagAdded($deal, $authUser, $data),
            'tag_removed' => $this->dealTagRemoved($deal, $authUser, $data),
            'call' => $this->dealCall($deal, $authUser, $data),
            'status_change' => $this->dealStatusChange($deal, $authUser, $data),
            'assignment' => $this->dealAssignment($deal, $authUser, $data),
            'follow_up_changed' => $this->dealFollowUpChanged($deal, $authUser, $data),
        };

        return response()->json($deal->fresh($this->dealRelations()));
    }

    private function touchLead(Lead $lead, User $actor, array $payload = []): void
    {
        $lead->update(array_merge([
            'updated_by' => $actor->id,
            'last_activity_at' => now(),
        ], $payload));
    }

    private function leadComment(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['comment']), 422, 'comment is required.');

        $this->touchLead($lead, $actor);
        $this->activityService->logComment($lead, $actor, trim((string) $data['comment']), ['lead_id' => $lead->id]);
    }

    private function leadTagAdded(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['tag']), 422, 'tag is required.');

        $oldTags = $lead->tags ?? [];
        $tags = $this->activityService->normalizeTags(array_merge($oldTags, [$data['tag']]));
        $this->touchLead($lead, $actor, ['tags' => $tags]);
        $this->activityService->logTagDiff($lead, $actor, $oldTags, $tags, ['lead_id' => $lead->id]);
    }

    private function leadTagRemoved(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['tag']), 422, 'tag is required.');

        $oldTags = $lead->tags ?? [];
        $tags = collect($oldTags)
            ->reject(fn ($tag) => (string) $tag === (string) $data['tag'])
            ->values()
            ->all();

        $this->touchLead($lead, $actor, ['tags' => $tags]);
        $this->activityService->logTagDiff($lead, $actor, $oldTags, $tags, ['lead_id' => $lead->id]);
    }

    private function leadCall(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['result']), 422, 'result is required.');

        $payload = [
            'last_contact_result' => trim((string) $data['result']),
            'first_contacted_at' => $lead->first_contacted_at ?: now(),
        ];

        if (! empty($data['next_follow_up_at'])) {
            $payload['next_follow_up_at'] = $data['next_follow_up_at'];
            $payload['next_activity_at'] = $data['next_activity_at'] ?? $data['next_follow_up_at'];
        } elseif (! empty($data['next_activity_at'])) {
            $payload['next_activity_at'] = $data['next_activity_at'];
        }

        $this->touchLead($lead, $actor, $payload);
        $this->activityService->logCall(
            $lead,
            $actor,
            $lead->last_contact_result,
            $data['duration_seconds'] ?? null,
            $data['note'] ?? null,
            ['lead_id' => $lead->id]
        );
    }

    private function leadStatusChange(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['status']), 422, 'status is required.');
        abort_if(
            $data['status'] === Lead::STATUS_LOST && empty($data['lost_reason']) && empty($lead->lost_reason),
            422,
            'lost_reason is required when status is lost.'
        );

        $oldStatus = $lead->status;
        $payload = ['status' => $data['status']];

        if (in_array($data['status'], [Lead::STATUS_IN_PROGRESS, Lead::STATUS_QUALIFIED], true) && ! $lead->first_contacted_at) {
            $payload['first_contacted_at'] = now();
        }

        if ($data['status'] === Lead::STATUS_LOST) {
            $payload['closed_at'] = $lead->closed_at ?: now();
        } elseif ($data['status'] !== Lead::STATUS_CONVERTED) {
            $payload['closed_at'] = null;
            $payload['lost_reason'] = null;
        }

        if (array_key_exists('lost_reason', $data)) {
            $payload['lost_reason'] = $data['lost_reason'];
        }

        $this->touchLead($lead, $actor, $payload);

        $this->activityService->logStatusChange(
            $lead,
            $actor,
            ['status' => $oldStatus],
            ['status' => $lead->status],
            ['lead_id' => $lead->id, 'lost_reason' => $lead->lost_reason],
            'Lead status changed.'
        );
    }

    private function leadAssignment(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['responsible_agent_id']), 422, 'responsible_agent_id is required.');

        $this->leadAccess->validateMutationTargets($actor, [
            'branch_id' => $lead->branch_id,
            'created_by' => $lead->created_by,
            'responsible_agent_id' => $data['responsible_agent_id'],
        ]);

        $oldResponsible = $lead->responsible_agent_id;
        $this->touchLead($lead, $actor, ['responsible_agent_id' => $data['responsible_agent_id']]);
        $this->activityService->logAssignment($lead, $actor, $oldResponsible, $lead->responsible_agent_id, ['lead_id' => $lead->id]);
    }

    private function leadFollowUpChanged(Lead $lead, User $actor, array $data): void
    {
        abort_if(empty($data['next_follow_up_at']) && empty($data['next_activity_at']), 422, 'follow-up value is required.');

        $oldValues = [
            'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
            'next_activity_at' => $lead->next_activity_at?->toIso8601String(),
        ];

        $payload = [];

        if (array_key_exists('next_follow_up_at', $data)) {
            $payload['next_follow_up_at'] = $data['next_follow_up_at'];
            $payload['next_activity_at'] = $data['next_activity_at'] ?? $data['next_follow_up_at'];
        } elseif (array_key_exists('next_activity_at', $data)) {
            $payload['next_activity_at'] = $data['next_activity_at'];
        }

        $this->touchLead($lead, $actor, $payload);
        $this->activityService->logFollowUpChange(
            $lead,
            $actor,
            $oldValues,
            [
                'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
                'next_activity_at' => $lead->next_activity_at?->toIso8601String(),
            ],
            ['lead_id' => $lead->id]
        );
    }

    private function touchDeal(Deal $deal, User $actor, array $payload = []): void
    {
        $deal->update(array_merge([
            'updated_by' => $actor->id,
        ], $payload));
    }

    private function dealComment(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['comment']), 422, 'comment is required.');

        $this->touchDeal($deal, $actor);
        $this->activityService->logComment($deal, $actor, trim((string) $data['comment']), ['deal_id' => $deal->id]);
    }

    private function dealTagAdded(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['tag']), 422, 'tag is required.');

        $oldTags = $deal->tags ?? [];
        $tags = $this->activityService->normalizeTags(array_merge($oldTags, [$data['tag']]));
        $this->touchDeal($deal, $actor, ['tags' => $tags]);
        $this->activityService->logTagDiff($deal, $actor, $oldTags, $tags, ['deal_id' => $deal->id]);
    }

    private function dealTagRemoved(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['tag']), 422, 'tag is required.');

        $oldTags = $deal->tags ?? [];
        $tags = collect($oldTags)
            ->reject(fn ($tag) => (string) $tag === (string) $data['tag'])
            ->values()
            ->all();

        $this->touchDeal($deal, $actor, ['tags' => $tags]);
        $this->activityService->logTagDiff($deal, $actor, $oldTags, $tags, ['deal_id' => $deal->id]);
    }

    private function dealCall(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['result']), 422, 'result is required.');

        $payload = [
            'last_contact_result' => trim((string) $data['result']),
        ];

        if (! empty($data['next_activity_at'])) {
            $payload['next_activity_at'] = $data['next_activity_at'];
        }

        $this->touchDeal($deal, $actor, $payload);
        $this->activityService->logCall(
            $deal,
            $actor,
            $deal->last_contact_result,
            $data['duration_seconds'] ?? null,
            $data['note'] ?? null,
            ['deal_id' => $deal->id]
        );
    }

    private function dealStatusChange(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['stage_id']), 422, 'stage_id is required.');

        $deal->loadMissing('stage');

        $targetStage = DealStage::query()->with('pipeline')->findOrFail($data['stage_id']);
        $this->dealAccess->ensurePipelineVisible($actor, $targetStage->pipeline);

        $oldValues = [
            'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id,
            'stage_slug' => $deal->stage?->slug,
            'closed_at' => $deal->closed_at?->toIso8601String(),
        ];

        $deal->update(['updated_by' => $actor->id]);

        $moved = $this->boardService->moveDeal(
            $deal,
            $targetStage,
            $data['position'] ?? null,
            $data['lost_reason'] ?? null
        );

        $this->activityService->logStatusChange(
            $moved,
            $actor,
            $oldValues,
            [
                'pipeline_id' => $moved->pipeline_id,
                'stage_id' => $moved->stage_id,
                'stage_slug' => $moved->stage?->slug,
                'closed_at' => $moved->closed_at?->toIso8601String(),
            ],
            ['deal_id' => $moved->id],
            'Deal stage changed.'
        );
    }

    private function dealAssignment(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['responsible_agent_id']), 422, 'responsible_agent_id is required.');

        $this->dealAccess->validateMutationTargets($actor, [
            'branch_id' => $deal->branch_id,
            'created_by' => $deal->created_by,
            'responsible_agent_id' => $data['responsible_agent_id'],
        ]);

        $oldResponsible = $deal->responsible_agent_id;
        $this->touchDeal($deal, $actor, ['responsible_agent_id' => $data['responsible_agent_id']]);
        $this->activityService->logAssignment($deal, $actor, $oldResponsible, $deal->responsible_agent_id, ['deal_id' => $deal->id]);
    }

    private function dealFollowUpChanged(Deal $deal, User $actor, array $data): void
    {
        abort_if(empty($data['next_activity_at']), 422, 'next_activity_at is required.');

        $oldValues = [
            'next_activity_at' => $deal->next_activity_at?->toIso8601String(),
        ];

        $this->touchDeal($deal, $actor, ['next_activity_at' => $data['next_activity_at']]);
        $this->activityService->logFollowUpChange(
            $deal,
            $actor,
            $oldValues,
            ['next_activity_at' => $deal->next_activity_at?->toIso8601String()],
            ['deal_id' => $deal->id]
        );
    }
}
