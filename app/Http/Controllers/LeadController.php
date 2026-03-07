<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadDeduplicator;
use App\Support\ClientPhone;
use App\Support\LeadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAccess $leadAccess,
        private readonly LeadDeduplicator $deduplicator,
        private readonly LeadConversionService $conversionService,
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
            'branch',
            'creator',
            'responsibleAgent',
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
            'client',
        ];
    }

    private function attachDuplicateSummary(Lead $lead): Lead
    {
        $lead->setAttribute('duplicate_summary', $this->deduplicator->summarize($lead));

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

        return $data;
    }

    private function validatePayload(Request $request, ?Lead $lead = null): array
    {
        $rules = [
            'full_name' => ($lead ? 'sometimes|' : '') . 'nullable|string|max:255',
            'phone' => ($lead ? 'sometimes|' : '') . 'nullable|string|max:50',
            'email' => ($lead ? 'sometimes|' : '') . 'nullable|email|max:255',
            'note' => 'nullable|string',
            'source' => ($lead ? 'sometimes|' : '') . 'nullable|string|max:100',
            'branch_id' => ($lead ? 'sometimes|' : '') . 'nullable|integer|exists:branches,id',
            'responsible_agent_id' => ($lead ? 'sometimes|' : '') . 'nullable|integer|exists:users,id',
            'status' => [
                $lead ? 'sometimes' : 'nullable',
                Rule::in(array_diff(Lead::statuses(), [Lead::STATUS_CONVERTED])),
            ],
            'first_contact_due_at' => ($lead ? 'sometimes|' : '') . 'nullable|date',
            'first_contacted_at' => ($lead ? 'sometimes|' : '') . 'nullable|date',
            'lost_reason' => 'nullable|string',
            'meta' => ($lead ? 'sometimes|' : '') . 'nullable|array',
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

        if (!$lead) {
            $data['status'] ??= Lead::STATUS_NEW;
            $data['first_contact_due_at'] ??= now()->addMinutes(Lead::DEFAULT_FIRST_CONTACT_SLA_MINUTES);
            $data['last_activity_at'] = now();
        } else {
            $data['last_activity_at'] = now();
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

            if (!array_key_exists('lost_reason', $data)) {
                $data['lost_reason'] = null;
            }
        }

        return $data;
    }

    private function changedValues(Lead $lead, array $dirty): array
    {
        return Arr::only($lead->getAttributes(), array_keys($dirty));
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
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->leadAccess->visibleQuery($authUser)->with($this->listRelations());

        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', '%' . $term . '%')
                    ->orWhere('phone', 'like', '%' . $term . '%')
                    ->orWhere('email', 'like', '%' . $term . '%')
                    ->orWhere('source', 'like', '%' . $term . '%');
            });
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['source'])) {
            $query->where('source', trim($validated['source']));
        }

        if (!empty($validated['responsible_agent_id'])) {
            $query->where('responsible_agent_id', $validated['responsible_agent_id']);
        }

        if (!empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }

        if ($this->leadAccess->isPrivilegedRole($this->leadAccess->roleSlug($authUser)) && !empty($validated['branch_id'])) {
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
            ]),
            'Lead created.'
        );

        return response()->json(
            $this->attachDuplicateSummary($lead->load($this->relations())),
            201
        );
    }

    public function show(Lead $lead)
    {
        $this->leadAccess->ensureVisible($this->authUser(), $lead);

        return response()->json(
            $this->attachDuplicateSummary($lead->load($this->relations()))
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
        $this->leadAccess->validateMutationTargets($authUser, $data);

        $lead->fill($data);
        $dirty = $lead->getDirty();

        if (!empty($dirty)) {
            $oldValues = Arr::only($lead->getOriginal(), array_keys($dirty));
            $lead->save();

            $this->auditLogger->log(
                $lead,
                $authUser,
                'updated',
                $oldValues,
                $this->changedValues($lead, $dirty),
                'Lead updated.'
            );
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

        return response()->json(
            $this->attachDuplicateSummary($convertedLead)
        );
    }
}
