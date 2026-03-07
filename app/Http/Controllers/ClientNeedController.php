<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\ClientNeedStatus;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientNeedController extends Controller
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

    private function relations(): array
    {
        return ['client.type', 'type', 'status', 'creator', 'responsibleAgent', 'location', 'propertyType'];
    }

    private function validatePayload(Request $request, ?ClientNeed $clientNeed = null): array
    {
        $validated = $request->validate([
            'type_id' => ($clientNeed ? 'sometimes|' : '') . 'required|exists:client_need_types,id',
            'status_id' => ($clientNeed ? 'sometimes|' : '') . 'required|exists:client_need_statuses,id',
            'budget_from' => 'nullable|numeric|min:0',
            'budget_to' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:TJS,USD',
            'location_id' => 'nullable|exists:locations,id',
            'district' => 'nullable|string|max:255',
            'property_type_id' => 'nullable|exists:property_types,id',
            'rooms_from' => 'nullable|integer|min:0',
            'rooms_to' => 'nullable|integer|min:0',
            'area_from' => 'nullable|numeric|min:0',
            'area_to' => 'nullable|numeric|min:0',
            'comment' => 'nullable|string',
            'created_by' => 'nullable|integer|exists:users,id',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'meta' => 'nullable|array',
        ]);

        if (
            isset($validated['budget_from'], $validated['budget_to'])
            && (float) $validated['budget_to'] < (float) $validated['budget_from']
        ) {
            abort(422, 'budget_to must be greater than or equal to budget_from.');
        }

        if (
            isset($validated['rooms_from'], $validated['rooms_to'])
            && (int) $validated['rooms_to'] < (int) $validated['rooms_from']
        ) {
            abort(422, 'rooms_to must be greater than or equal to rooms_from.');
        }

        if (
            isset($validated['area_from'], $validated['area_to'])
            && (float) $validated['area_to'] < (float) $validated['area_from']
        ) {
            abort(422, 'area_to must be greater than or equal to area_from.');
        }

        return $validated;
    }

    private function applyClosedState(array $data, ?ClientNeed $clientNeed = null): array
    {
        if (!array_key_exists('status_id', $data) || empty($data['status_id'])) {
            return $data;
        }

        $status = ClientNeedStatus::query()->findOrFail($data['status_id']);
        $data['closed_at'] = $status->is_closed
            ? ($clientNeed?->closed_at ?: now())
            : null;

        return $data;
    }

    public function index(Client $client)
    {
        $authUser = $this->authUser();
        $this->clientAccess->ensureVisible($authUser, $client);

        return response()->json(
            $client->needs()
                ->with($this->relations())
                ->get()
        );
    }

    public function store(Request $request, Client $client)
    {
        $authUser = $this->authUser();

        $validated = $this->validatePayload($request);
        $validated = $this->clientAccess->normalizeNeedMutationData($validated, $authUser, $client);
        $this->clientAccess->validateNeedMutationTargets($authUser, $client, $validated);
        $validated = $this->applyClosedState($validated);
        $validated['currency'] ??= 'TJS';

        $need = ClientNeed::create($validated);

        return response()->json($need->load($this->relations()), 201);
    }

    public function show(ClientNeed $clientNeed)
    {
        $this->clientAccess->ensureNeedVisible($this->authUser(), $clientNeed);

        return response()->json($clientNeed->load($this->relations()));
    }

    public function update(Request $request, ClientNeed $clientNeed)
    {
        $authUser = $this->authUser();
        $clientNeed->loadMissing('client');
        $this->clientAccess->ensureNeedVisible($authUser, $clientNeed);

        $validated = $this->validatePayload($request, $clientNeed);
        $validated = $this->clientAccess->normalizeNeedMutationData($validated, $authUser, $clientNeed->client);
        $this->clientAccess->validateNeedMutationTargets($authUser, $clientNeed->client, $validated);
        $validated = $this->applyClosedState($validated, $clientNeed);

        $clientNeed->update($validated);

        return response()->json($clientNeed->fresh($this->relations()));
    }

    public function destroy(ClientNeed $clientNeed)
    {
        $this->clientAccess->ensureNeedVisible($this->authUser(), $clientNeed);

        $clientNeed->delete();

        return response()->json(['message' => 'Client need deleted']);
    }
}
