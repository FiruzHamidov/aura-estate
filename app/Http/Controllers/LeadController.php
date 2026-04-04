<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadDeduplicator;
use App\Services\NotificationService;
use App\Support\ClientPhone;
use App\Support\LeadAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAccess $leadAccess,
        private readonly LeadDeduplicator $deduplicator,
        private readonly LeadConversionService $conversionService,
        private readonly AuditLogger $auditLogger,
        private readonly ActivityService $activityService,
        private readonly NotificationService $notifications
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
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'client',
            'auditLogs.actor',
        ];
    }

    private function listRelations(): array
    {
        return [
            'branch',
            'creator',
            'responsibleAgent',
            'updater',
            'client',
        ];
    }

    private function attachDuplicateSummary(Lead $lead): Lead
    {
        $lead->setAttribute('duplicate_summary', $this->deduplicator->summarize($lead));

        return $lead;
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

    private function attachShowIncludes(Lead $lead, array $includes): Lead
    {
        if (in_array('activities', $includes, true)) {
            $lead->setRelation('activities', $lead->auditLogs);
        }

        return $lead;
    }

    private function normalizeInput(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = ClientPhone::normalize($data['phone']);
        }

        if (array_key_exists('email', $data) && $data['email']) {
            $data['email'] = mb_strtolower(trim((string) $data['email']));
        }

        if (array_key_exists('source', $data) && $data['source']) {
            $data['source'] = trim((string) $data['source']);
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = $this->activityService->normalizeTags($data['tags']);
        }

        if (array_key_exists('last_contact_result', $data) && $data['last_contact_result']) {
            $data['last_contact_result'] = trim((string) $data['last_contact_result']);
        }

        foreach (['first_contact_due_at', 'first_contacted_at', 'next_follow_up_at', 'next_activity_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->setTimezone(config('app.timezone'));
            }
        }

        return $data;
    }

    private function validatePayload(Request $request, ?Lead $lead = null): array
    {
        $rules = [
            'full_name' => ($lead ? 'sometimes|' : '').'nullable|string|max:255',
            'phone' => ($lead ? 'sometimes|' : '').'nullable|string|max:50',
            'email' => ($lead ? 'sometimes|' : '').'nullable|email|max:255',
            'note' => 'nullable|string',
            'source' => ($lead ? 'sometimes|' : '').'nullable|string|max:100',
            'branch_id' => ($lead ? 'sometimes|' : '').'nullable|integer|exists:branches,id',
            'responsible_agent_id' => ($lead ? 'sometimes|' : '').'nullable|integer|exists:users,id',
            'status' => [
                $lead ? 'sometimes' : 'nullable',
                Rule::in(array_diff(Lead::statuses(), [Lead::STATUS_CONVERTED])),
            ],
            'first_contact_due_at' => ($lead ? 'sometimes|' : '').'nullable|date',
            'first_contacted_at' => ($lead ? 'sometimes|' : '').'nullable|date',
            'lost_reason' => 'nullable|string',
            'meta' => ($lead ? 'sometimes|' : '').'nullable|array',
            'tags' => ($lead ? 'sometimes|' : '').'nullable|array',
            'tags.*' => 'string|max:64',
            'last_contact_result' => ($lead ? 'sometimes|' : '').'nullable|string|max:100',
            'next_follow_up_at' => ($lead ? 'sometimes|' : '').'nullable|date',
            'next_activity_at' => ($lead ? 'sometimes|' : '').'nullable|date',
        ];

        $validated = $request->validate($rules);

        $fullName = trim((string) ($validated['full_name'] ?? $lead?->full_name ?? ''));
        $phone = trim((string) ($validated['phone'] ?? $lead?->phone ?? ''));
        $email = trim((string) ($validated['email'] ?? $lead?->email ?? ''));

        if ($fullName === '' && $phone === '' && $email === '') {
            throw ValidationException::withMessages([
                'full_name' => ['Either full_name, phone or email must be provided.'],
            ]);
        }

        $status = $validated['status'] ?? $lead?->status ?? Lead::STATUS_NEW;
        $lostReason = trim((string) ($validated['lost_reason'] ?? $lead?->lost_reason ?? ''));

        if ($status === Lead::STATUS_LOST && $lostReason === '') {
            throw ValidationException::withMessages([
                'lost_reason' => ['lost_reason is required when status is lost.'],
            ]);
        }

        return $validated;
    }

    private function applyState(array $data, ?Lead $lead = null): array
    {
        $status = $data['status'] ?? $lead?->status ?? Lead::STATUS_NEW;

        if (! $lead) {
            $data['status'] ??= Lead::STATUS_NEW;
            $data['first_contact_due_at'] ??= now()->addMinutes(Lead::DEFAULT_FIRST_CONTACT_SLA_MINUTES);
            $data['last_activity_at'] = now();
        } else {
            $data['last_activity_at'] = now();
        }

        if (! empty($data['next_follow_up_at']) && empty($data['next_activity_at'])) {
            $data['next_activity_at'] = $data['next_follow_up_at'];
        }

        if (
            in_array($status, [Lead::STATUS_IN_PROGRESS, Lead::STATUS_QUALIFIED], true)
            && empty($data['first_contacted_at'])
            && empty($lead?->first_contacted_at)
        ) {
            $data['first_contacted_at'] = now();
        }

        if ($status === Lead::STATUS_LOST) {
            $data['closed_at'] = $lead?->closed_at ?: now();
        } elseif ($status !== Lead::STATUS_CONVERTED && array_key_exists('status', $data)) {
            $data['closed_at'] = null;

            if (! array_key_exists('lost_reason', $data)) {
                $data['lost_reason'] = null;
            }
        }

        return $data;
    }

    private function changedValues(Lead $lead, array $dirty): array
    {
        return Arr::only($lead->getAttributes(), array_keys($dirty));
    }

    private function isoValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }

    private function leadStatusContext(Lead $lead): array
    {
        return array_filter([
            'lead_id' => $lead->id,
            'lost_reason' => $lead->lost_reason,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function logTypedUpdates(Lead $lead, User $authUser, array $oldValues, array $dirty): void
    {
        $handled = [];

        if (array_key_exists('status', $dirty)) {
            $this->activityService->logStatusChange(
                $lead,
                $authUser,
                ['status' => $oldValues['status'] ?? null],
                ['status' => $lead->status],
                $this->leadStatusContext($lead),
                'Lead status changed.'
            );

            $handled[] = 'status';
        }

        if (array_key_exists('responsible_agent_id', $dirty)) {
            $this->activityService->logAssignment(
                $lead,
                $authUser,
                isset($oldValues['responsible_agent_id']) ? (int) $oldValues['responsible_agent_id'] : null,
                $lead->responsible_agent_id ? (int) $lead->responsible_agent_id : null,
                ['lead_id' => $lead->id]
            );

            $handled[] = 'responsible_agent_id';
        }

        if (array_key_exists('tags', $dirty)) {
            $this->activityService->logTagDiff(
                $lead,
                $authUser,
                $this->activityService->normalizeTags($oldValues['tags'] ?? []),
                $this->activityService->normalizeTags($lead->tags ?? []),
                ['lead_id' => $lead->id]
            );

            $handled[] = 'tags';
        }

        $followUpKeys = array_values(array_intersect(['next_follow_up_at', 'next_activity_at'], array_keys($dirty)));

        if (! empty($followUpKeys)) {
            $this->activityService->logFollowUpChange(
                $lead,
                $authUser,
                [
                    'next_follow_up_at' => $this->isoValue($oldValues['next_follow_up_at'] ?? null),
                    'next_activity_at' => $this->isoValue($oldValues['next_activity_at'] ?? null),
                ],
                [
                    'next_follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
                    'next_activity_at' => $lead->next_activity_at?->toIso8601String(),
                ],
                ['lead_id' => $lead->id]
            );

            $handled = array_merge($handled, $followUpKeys);
        }

        if (array_key_exists('last_contact_result', $dirty) && ! empty($lead->last_contact_result)) {
            $this->activityService->logCall(
                $lead,
                $authUser,
                $lead->last_contact_result,
                null,
                null,
                ['lead_id' => $lead->id, 'source' => 'lead_update']
            );

            $handled[] = 'last_contact_result';
        }

        $remainingKeys = array_values(array_diff(array_keys($dirty), array_merge($handled, [
            'updated_by',
            'last_activity_at',
        ])));

        if (! empty($remainingKeys)) {
            $this->auditLogger->log(
                $lead,
                $authUser,
                'updated',
                Arr::only($oldValues, $remainingKeys),
                Arr::only($lead->getAttributes(), $remainingKeys),
                'Lead updated.'
            );
        }
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'search' => 'nullable|string',
            'status' => ['nullable', Rule::in(Lead::statuses())],
            'source' => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'overdue_first_contact' => 'nullable|boolean',
            'overdue_follow_up' => 'nullable|boolean',
            'overdue_activity' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->leadAccess->visibleQuery($authUser)->with($this->listRelations());

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('source', 'like', '%'.$term.'%');
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['source'])) {
            $query->where('source', trim($validated['source']));
        }

        if (! empty($validated['responsible_agent_id'])) {
            $query->where('responsible_agent_id', $validated['responsible_agent_id']);
        }

        if (! empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }

        if ($this->leadAccess->isPrivilegedRole($this->leadAccess->roleSlug($authUser)) && ! empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (array_key_exists('overdue_first_contact', $validated) && $validated['overdue_first_contact'] !== null) {
            if ($request->boolean('overdue_first_contact')) {
                $query
                    ->whereNotIn('status', Lead::closedStatuses())
                    ->whereNull('first_contacted_at')
                    ->whereNotNull('first_contact_due_at')
                    ->where('first_contact_due_at', '<', now());
            } else {
                $query->where(function ($builder) {
                    $builder
                        ->whereIn('status', Lead::closedStatuses())
                        ->orWhereNotNull('first_contacted_at')
                        ->orWhereNull('first_contact_due_at')
                        ->orWhere('first_contact_due_at', '>=', now());
                });
            }
        }

        if (array_key_exists('overdue_follow_up', $validated) && $validated['overdue_follow_up'] !== null) {
            if ($request->boolean('overdue_follow_up')) {
                $query
                    ->whereNotIn('status', Lead::closedStatuses())
                    ->whereNotNull('next_follow_up_at')
                    ->where('next_follow_up_at', '<', now());
            } else {
                $query->where(function ($builder) {
                    $builder
                        ->whereIn('status', Lead::closedStatuses())
                        ->orWhereNull('next_follow_up_at')
                        ->orWhere('next_follow_up_at', '>=', now());
                });
            }
        }

        if (array_key_exists('overdue_activity', $validated) && $validated['overdue_activity'] !== null) {
            if ($request->boolean('overdue_activity')) {
                $query
                    ->whereNotIn('status', Lead::closedStatuses())
                    ->whereNotNull('next_activity_at')
                    ->where('next_activity_at', '<', now());
            } else {
                $query->where(function ($builder) {
                    $builder
                        ->whereIn('status', Lead::closedStatuses())
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
        $data = $this->normalizeInput($data);
        $data = $this->applyState($data);
        $data = $this->leadAccess->normalizeCreationData($data, $authUser);
        $data['updated_by'] = $authUser->id;
        $this->leadAccess->validateMutationTargets($authUser, $data);

        $lead = Lead::create($data);

        $this->auditLogger->log(
            $lead,
            $authUser,
            'created',
            [],
            Arr::only($lead->getAttributes(), [
                'full_name',
                'phone',
                'email',
                'source',
                'branch_id',
                'responsible_agent_id',
                'status',
                'tags',
                'last_contact_result',
                'next_follow_up_at',
                'next_activity_at',
            ]),
            'Lead created.'
        );

        $this->notifications->handleLeadCreated($lead->fresh($this->relations()), $authUser);

        return response()->json(
            $this->attachDuplicateSummary($lead->load($this->relations())),
            201
        );
    }

    public function show(Request $request, Lead $lead)
    {
        $this->leadAccess->ensureVisible($this->authUser(), $lead);

        $lead->load($this->relations());
        $this->appendActivitySummary($lead);
        $this->attachShowIncludes($lead, $this->parseIncludes($request));

        return response()->json(
            $this->attachDuplicateSummary($lead)
        );
    }

    public function update(Request $request, Lead $lead)
    {
        $authUser = $this->authUser();
        $this->leadAccess->ensureVisible($authUser, $lead);

        $data = $this->validatePayload($request, $lead);
        $data = $this->normalizeInput($data);
        $data = $this->applyState($data, $lead);
        $data = $this->leadAccess->normalizeUpdateData($data, $authUser, $lead);
        $data['updated_by'] = $authUser->id;
        $this->leadAccess->validateMutationTargets($authUser, $data);

        $lead->fill($data);
        $dirty = $lead->getDirty();

        if (! empty($dirty)) {
            $oldValues = Arr::only($lead->getOriginal(), array_keys($dirty));
            $lead->save();
            $this->logTypedUpdates($lead, $authUser, $oldValues, $dirty);
            $this->notifications->handleLeadUpdated($lead->fresh($this->relations()), $authUser, $oldValues, $dirty);
        }

        return response()->json(
            $this->attachDuplicateSummary($lead->fresh($this->relations()))
        );
    }

    public function destroy(Lead $lead)
    {
        $authUser = $this->authUser();
        $this->leadAccess->ensureVisible($authUser, $lead);

        $this->auditLogger->log(
            $lead,
            $authUser,
            'deleted',
            Arr::only($lead->getAttributes(), [
                'full_name',
                'phone',
                'email',
                'source',
                'branch_id',
                'responsible_agent_id',
                'status',
                'client_id',
            ]),
            [],
            'Lead deleted.'
        );

        $lead->delete();

        return response()->json(['message' => 'Lead deleted']);
    }

    public function convert(Lead $lead)
    {
        $authUser = $this->authUser();
        $this->leadAccess->ensureVisible($authUser, $lead);

        $convertedLead = $this->conversionService->convert($lead, $authUser);
        $this->notifications->handleLeadConverted($convertedLead, $authUser);

        return response()->json(
            $this->attachDuplicateSummary($convertedLead)
        );
    }
}
