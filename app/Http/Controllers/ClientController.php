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
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->clientAccess->visibleQuery($authUser);

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
            'status',
            'bitrix_contact_id',
            'meta',
        ]);

        $data = $this->normalizeInput($data);
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $this->clientAccess->validateMutationTargets($authUser, $data);

        $client = Client::create($data);

        return response()->json($client->load(['branch', 'creator', 'responsibleAgent']), 201);
    }

    public function show(Client $client)
    {
        $this->clientAccess->ensureVisible($this->authUser(), $client);

        return response()->json($client->load(['branch', 'creator', 'responsibleAgent']));
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
            'status',
            'bitrix_contact_id',
            'meta',
        ]);

        $data = $this->normalizeInput($data);
        $data = $this->clientAccess->normalizeMutationData($data, $authUser);
        $this->clientAccess->validateMutationTargets($authUser, $data);

        $client->update($data);

        return response()->json($client->fresh(['branch', 'creator', 'responsibleAgent']));
    }

    public function destroy(Client $client)
    {
        $this->clientAccess->ensureVisible($this->authUser(), $client);

        $client->delete();

        return response()->json(['message' => 'Client deleted']);
    }

    public function settings()
    {
        return response()->json([
            'agent_visibility_mode' => $this->clientAccess->visibilityMode(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $authUser = $this->authUser();
        abort_unless($this->isPrivilegedRole($authUser), 403, 'Forbidden');

        $validated = $request->validate([
            'agent_visibility_mode' => ['required', Rule::in([
                ClientAccess::VISIBILITY_ALL_BRANCH,
                ClientAccess::VISIBILITY_OWN_ONLY,
            ])],
        ]);

        return response()->json([
            'agent_visibility_mode' => $this->clientAccess->setVisibilityMode($validated['agent_visibility_mode']),
        ]);
    }
}
