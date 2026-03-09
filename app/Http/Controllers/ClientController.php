<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Support\ClientAccess;
use App\Support\ClientPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientAccess $clientAccess
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

        return $data;
    }

    private function showRelations(): array
    {
        return [
            'branch',
            'creator',
            'responsibleAgent',
            'type',
            'needs.type',
            'needs.status',
            'needs.creator',
            'needs.responsibleAgent',
            'needs.location',
            'needs.propertyType',
        ];
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

        $client = Client::create($data);

        return response()->json($client->load($this->showRelations()), 201);
    }

    public function show(Client $client)
    {
        $this->clientAccess->ensureVisible($this->authUser(), $client);

        return response()->json(
            $client->load($this->showRelations())
                ->loadCount(['needs', 'openNeeds'])
        );
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
            'created_by' => $client->created_by,
            'responsible_agent_id' => $client->responsible_agent_id,
            'client_type_id' => $client->client_type_id,
            'contact_kind' => $client->contact_kind,
        ], $data);
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $this->clientAccess->validateMutationTargets($authUser, $data);

        $client->update($data);

        return response()->json($client->fresh($this->showRelations()));
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
