<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\ClientAttachService;
use App\Services\Crm\ClientDeduplicator;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientAccess $clientAccess,
        private readonly ClientDeduplicator $deduplicator,
        private readonly ClientAttachService $attachService,
        private readonly AuditLogger $auditLogger,
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

    private function isPrivilegedRole(User $user): bool
    {
        return $this->clientAccess->isPrivilegedRole($this->clientAccess->roleSlug($user));
    }

    private function normalizeInput(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = ClientPhone::normalize($data['phone']);
        }

        if (array_key_exists('email', $data) && $data['email']) {
            $data['email'] = mb_strtolower(trim((string) $data['email']));
        }

        return $data;
    }

    private function showRelations(): array
    {
        return [
            'branch',
            'branchGroup',
            'creator',
            'responsibleAgent',
            'type',
            'needs.type',
            'needs.status',
            'needs.creator',
            'needs.responsibleAgent',
            'needs.location',
            'needs.propertyType',
            'needs.propertyTypes',
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

    private function summarizeDuplicates(
        User $authUser,
        array $data,
        ?int $excludeClientId = null,
        array $context = []
    ): array
    {
        return $this->deduplicator->summarize($authUser, $data, $excludeClientId, $context);
    }

    private function duplicateConflictResponse(array $summary)
    {
        return response()->json([
            'message' => $summary['message'] ?? 'Duplicate client already exists.',
            'duplicate_summary' => $summary,
        ], 409);
    }

    private function appendActivitySummary(Client $client): void
    {
        $latestActivities = $client->auditLogs()
            ->with('actor')
            ->latest('id')
            ->limit(10)
            ->get();

        $client->setAttribute('activities_count', $client->auditLogs()->count());
        $client->setAttribute(
            'latest_activity_at',
            $latestActivities->first()?->created_at?->toIso8601String()
        );
        $client->setAttribute('latest_activities', $latestActivities);
    }

    private function loadCollaboratorPayload(Client $client): array
    {
        $client->loadMissing(['responsibleAgent.role', 'collaborators.role']);

        $payload = [];

        if ($client->responsibleAgent) {
            $payload[] = [
                'user_id' => $client->responsibleAgent->id,
                'name' => $client->responsibleAgent->name,
                'phone' => $client->responsibleAgent->phone,
                'role_slug' => $client->responsibleAgent->role?->slug,
                'collaboration_role' => Client::COLLABORATOR_ROLE_OWNER,
                'granted_by' => null,
                'is_primary' => true,
            ];
        }

        foreach ($client->collaborators as $collaborator) {
            if ((int) $collaborator->id === (int) $client->responsible_agent_id) {
                continue;
            }

            $payload[] = [
                'user_id' => $collaborator->id,
                'name' => $collaborator->name,
                'phone' => $collaborator->phone,
                'role_slug' => $collaborator->role?->slug,
                'collaboration_role' => $collaborator->pivot->role,
                'granted_by' => $collaborator->pivot->granted_by,
                'is_primary' => false,
            ];
        }

        return $payload;
    }

    private function logClientCreated(Client $client, User $actor): void
    {
        $this->auditLogger->log(
            $client,
            $actor,
            'created',
            [],
            [
                'full_name' => $client->full_name,
                'phone' => $client->phone,
                'email' => $client->email,
                'responsible_agent_id' => $client->responsible_agent_id,
                'contact_kind' => $client->contact_kind,
                'status' => $client->status,
            ],
            'Client created.',
            ['client_id' => $client->id]
        );
    }

    private function logClientUpdated(Client $client, User $actor, array $before): void
    {
        $changedFields = collect(array_keys($client->getChanges()))
            ->reject(fn (string $field) => $field === 'updated_at')
            ->values();

        if ($changedFields->isEmpty()) {
            return;
        }

        $oldValues = $changedFields
            ->mapWithKeys(fn (string $field) => [$field => $before[$field] ?? null])
            ->all();

        $newValues = $changedFields
            ->mapWithKeys(fn (string $field) => [$field => $client->{$field}])
            ->all();

        $this->auditLogger->log(
            $client,
            $actor,
            'updated',
            $oldValues,
            $newValues,
            'Client updated.',
            ['client_id' => $client->id]
        );

        if (array_key_exists('contact_kind', $newValues)) {
            $this->auditLogger->log(
                $client,
                $actor,
                'contact_kind_changed',
                ['contact_kind' => $oldValues['contact_kind'] ?? null],
                ['contact_kind' => $newValues['contact_kind']],
                'Client contact kind changed.',
                ['client_id' => $client->id]
            );
        }

        if (array_key_exists('responsible_agent_id', $newValues)) {
            $this->auditLogger->log(
                $client,
                $actor,
                'responsible_agent_changed',
                ['responsible_agent_id' => $oldValues['responsible_agent_id'] ?? null],
                ['responsible_agent_id' => $newValues['responsible_agent_id']],
                'Client responsible agent changed.',
                ['client_id' => $client->id]
            );
        }
    }

    public function duplicateCheck(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'exclude_client_id' => 'nullable|integer|exists:clients,id',
            'context_type' => ['nullable', Rule::in(ClientAttachService::contextTypes())],
            'context_id' => 'nullable|integer',
            'property_relation' => ['nullable', Rule::in(ClientAttachService::propertyRelations())],
        ]);

        $data = $this->normalizeInput($request->only(['phone', 'email', 'branch_id']));
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $context = $this->attachService->normalizedContext($validated);

        return response()->json(
            $this->summarizeDuplicates($authUser, $data, $validated['exclude_client_id'] ?? null, $context)
        );
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'search' => 'nullable|string',
            'full_name' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'client_type_id' => 'nullable|integer|exists:client_types,id',
            'contact_kind' => ['nullable', Rule::in(Client::contactKinds())],
            'is_business' => 'nullable|boolean',
            'has_open_needs' => 'nullable|boolean',
            'need_status_id' => 'nullable|integer|exists:client_need_statuses,id',
            'need_type_id' => 'nullable|integer|exists:client_need_types,id',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->clientAccess->visibleQuery($authUser)
            ->with($this->showRelations())
            ->withCount(['needs', 'openNeeds']);

        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', '%' . $term . '%')
                    ->orWhere('phone', 'like', '%' . $term . '%')
                    ->orWhere('email', 'like', '%' . $term . '%');
            });
        }

        if (!empty($validated['full_name'])) {
            $query->where('full_name', 'like', '%' . trim($validated['full_name']) . '%');
        }

        if (!empty($validated['phone'])) {
            $query->where('phone', 'like', '%' . trim($validated['phone']) . '%');
        }

        if (!empty($validated['email'])) {
            $query->where('email', 'like', '%' . trim($validated['email']) . '%');
        }

        if (!empty($validated['responsible_agent_id'])) {
            $query->where('responsible_agent_id', $validated['responsible_agent_id']);
        }

        if ($this->isPrivilegedRole($authUser) && !empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['branch_group_id'])) {
            $query->where('branch_group_id', $validated['branch_group_id']);
        }

        if (!empty($validated['client_type_id'])) {
            $query->where('client_type_id', $validated['client_type_id']);
        }

        if (!empty($validated['contact_kind'])) {
            $query->whereIn('contact_kind', Client::kindsMatchingFilter($validated['contact_kind']));
        }

        if (array_key_exists('is_business', $validated) && $validated['is_business'] !== null) {
            $query->whereHas('type', fn ($builder) => $builder->where('is_business', $validated['is_business']));
        }

        if (array_key_exists('has_open_needs', $validated) && $validated['has_open_needs'] !== null) {
            if ($request->boolean('has_open_needs')) {
                $query->whereHas('openNeeds');
            } else {
                $query->whereDoesntHave('openNeeds');
            }
        }

        if (!empty($validated['need_status_id'])) {
            $query->whereHas('needs', fn ($builder) => $builder->where('status_id', $validated['need_status_id']));
        }

        if (!empty($validated['need_type_id'])) {
            $query->whereHas('needs', fn ($builder) => $builder->where('type_id', $validated['need_type_id']));
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
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

        $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'note' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'client_type_id' => 'nullable|integer|exists:client_types,id',
            'contact_kind' => ['nullable', Rule::in(Client::contactKinds())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'bitrix_contact_id' => 'nullable|integer',
            'meta' => 'nullable|array',
        ]);

        $data = $request->only([
            'full_name',
            'phone',
            'email',
            'note',
            'branch_id',
            'branch_group_id',
            'responsible_agent_id',
            'client_type_id',
            'contact_kind',
            'status',
            'bitrix_contact_id',
            'meta',
        ]);

        $data = $this->normalizeInput($data);
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $this->clientAccess->validateMutationTargets($authUser, $data);

        $duplicateSummary = $this->summarizeDuplicates(
            $authUser,
            $data,
            null,
            $this->attachService->normalizedContext([])
        );
        if ($duplicateSummary['has_duplicates']) {
            return $this->duplicateConflictResponse($duplicateSummary);
        }

        $client = Client::create($data);
        $this->logClientCreated($client, $authUser);

        return response()->json($client->load($this->showRelations()), 201);
    }

    public function show(Request $request, Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);
        $includes = $this->parseIncludes($request);

        $client->load($this->showRelations())
            ->loadCount(['needs', 'openNeeds']);

        $this->appendActivitySummary($client);

        if (in_array('activities', $includes, true)) {
            $client->loadMissing('auditLogs.actor');
            $client->setRelation('activities', $client->auditLogs);
        }

        if (in_array('collaborators', $includes, true)) {
            $client->setAttribute('collaborators_payload', $this->loadCollaboratorPayload($client));
        }

        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);

        $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'email' => 'sometimes|nullable|email|max:255',
            'note' => 'nullable|string',
            'branch_id' => 'sometimes|nullable|integer|exists:branches,id',
            'branch_group_id' => 'sometimes|nullable|integer|exists:branch_groups,id',
            'responsible_agent_id' => 'sometimes|nullable|integer|exists:users,id',
            'client_type_id' => 'sometimes|nullable|integer|exists:client_types,id',
            'contact_kind' => ['sometimes', 'nullable', Rule::in(Client::contactKinds())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'bitrix_contact_id' => 'sometimes|nullable|integer',
            'meta' => 'sometimes|nullable|array',
        ]);

        $data = $request->only([
            'full_name',
            'phone',
            'email',
            'note',
            'branch_id',
            'branch_group_id',
            'responsible_agent_id',
            'client_type_id',
            'contact_kind',
            'status',
            'bitrix_contact_id',
            'meta',
        ]);

        $data = $this->normalizeInput($data);
        $data = array_merge([
            'branch_id' => $client->branch_id,
            'branch_group_id' => $client->branch_group_id,
            'created_by' => $client->created_by,
            'responsible_agent_id' => $client->responsible_agent_id,
            'client_type_id' => $client->client_type_id,
            'contact_kind' => $client->contact_kind,
        ], $data);
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $this->clientAccess->validateMutationTargets($authUser, $data);

        $duplicateSummary = $this->summarizeDuplicates(
            $authUser,
            $data,
            $client->id,
            $this->attachService->normalizedContext([])
        );
        if ($duplicateSummary['has_duplicates']) {
            return $this->duplicateConflictResponse($duplicateSummary);
        }

        $before = $client->getAttributes();
        $client->update($data);
        $this->logClientUpdated($client, $authUser, $before);

        return response()->json($client->fresh($this->showRelations()));
    }

    public function activities(Request $request, Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);

        $validated = $request->validate([
            'type' => 'nullable|string|max:50',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $client->auditLogs()->with('actor');

        if (!empty($validated['type'])) {
            $query->where('event', trim((string) $validated['type']));
        }

        if (!empty($validated['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['date_from']));
        }

        if (!empty($validated['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['date_to']));
        }

        return response()->json(
            $query->paginate((int) ($validated['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    public function collaborators(Request $request, Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);

        return response()->json($this->loadCollaboratorPayload($client));
    }

    public function attachExisting(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'context_type' => ['nullable', Rule::in(ClientAttachService::contextTypes())],
            'context_id' => 'nullable|integer',
            'property_relation' => ['nullable', Rule::in(ClientAttachService::propertyRelations())],
        ]);

        $client = Client::query()->findOrFail($validated['client_id']);
        $result = $this->attachService->attach(
            $authUser,
            $client,
            $this->attachService->normalizedContext($validated)
        );

        return response()->json($result);
    }

    public function storeCollaborator(Request $request, Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);
        $this->clientAccess->ensureCanManageCollaborators($authUser, $client);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => ['nullable', Rule::in(Client::collaboratorRoles())],
        ]);

        $collaborator = User::query()->findOrFail($validated['user_id']);

        if ((int) $collaborator->id === (int) $client->responsible_agent_id) {
            abort(422, 'Primary responsible agent is already the owner of this client.');
        }

        if (!empty($client->branch_id) && (int) $collaborator->branch_id !== (int) $client->branch_id) {
            abort(422, 'Collaborator must belong to the client branch.');
        }

        $role = $validated['role'] ?? Client::COLLABORATOR_ROLE_COLLABORATOR;

        $existing = $client->collaborators()->whereKey($collaborator->id)->exists();

        if ($existing) {
            $client->collaborators()->updateExistingPivot($collaborator->id, [
                'role' => $role,
                'granted_by' => $authUser->id,
                'updated_at' => now(),
            ]);
        } else {
            $client->collaborators()->attach($collaborator->id, [
                'role' => $role,
                'granted_by' => $authUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->auditLogger->log(
            $client,
            $authUser,
            'collaborator_added',
            [],
            [
                'user_id' => $collaborator->id,
                'role' => $role,
            ],
            'Client collaborator added.',
            [
                'client_id' => $client->id,
                'user_id' => $collaborator->id,
                'role' => $role,
            ]
        );

        return response()->json($this->loadCollaboratorPayload($client->fresh()));
    }

    public function destroyCollaborator(Request $request, Client $client, User $user)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);
        $this->clientAccess->ensureCanManageCollaborators($authUser, $client);

        if ((int) $user->id === (int) $client->responsible_agent_id) {
            abort(422, 'Primary responsible agent cannot be removed from client owner role.');
        }

        $existing = $client->collaborators()
            ->whereKey($user->id)
            ->first();

        abort_unless($existing, 404, 'Collaborator not found.');

        $client->collaborators()->detach($user->id);

        $this->auditLogger->log(
            $client,
            $authUser,
            'collaborator_removed',
            [
                'user_id' => $user->id,
                'role' => $existing->pivot->role,
            ],
            [],
            'Client collaborator removed.',
            [
                'client_id' => $client->id,
                'user_id' => $user->id,
            ]
        );

        return response()->json($this->loadCollaboratorPayload($client->fresh()));
    }

    public function destroy(Client $client)
    {
        $this->clientAccess->ensureVisible($this->authUser(), $client);

        $client->delete();

        return response()->json(['message' => 'Client deleted']);
    }

    public function settings()
    {
        return response()->json($this->clientAccess->settings());
    }

    public function updateSettings(Request $request)
    {
        $authUser = $this->authUser();
        abort_unless($this->isPrivilegedRole($authUser), 403, 'Forbidden');

        $validated = $request->validate([
            'agent_visibility_mode' => ['sometimes', Rule::in([
                ClientAccess::VISIBILITY_ALL_BRANCH,
                ClientAccess::VISIBILITY_OWN_ONLY,
            ])],
            'agent_can_view_sellers' => ['sometimes', 'boolean'],
        ]);

        abort_if($validated === [], 422, 'At least one client setting must be provided.');

        if (array_key_exists('agent_can_view_sellers', $validated)) {
            $validated['agent_can_view_sellers'] = filter_var(
                $validated['agent_can_view_sellers'],
                FILTER_VALIDATE_BOOL
            );
        }

        return response()->json($this->clientAccess->updateSettings($validated));
    }
}
