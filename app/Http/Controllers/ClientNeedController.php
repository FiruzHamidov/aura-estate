<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\ClientNeedStatus;
use App\Models\PropertyType;
use App\Models\RepairType;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

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
        return [
            'client.type',
            'type',
            'status',
            'creator',
            'responsibleAgent',
            'location',
            'propertyType',
            'propertyTypes',
            'repairType',
            'repairTypes',
        ];
    }

    private function validatePayload(Request $request, ?ClientNeed $clientNeed = null): array
    {
        $validated = $request->validate([
            'type_id' => ($clientNeed ? 'sometimes|' : '') . 'required|exists:client_need_types,id',
            'status_id' => ($clientNeed ? 'sometimes|' : '') . 'nullable|exists:client_need_statuses,id',
            'budget_from' => 'nullable|numeric|min:0',
            'budget_to' => 'nullable|numeric|min:0',
            'budget_total' => 'nullable|numeric|min:0',
            'budget_cash' => 'nullable|numeric|min:0',
            'budget_mortgage' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:TJS,USD',
            'location_id' => 'nullable|exists:locations,id',
            'district' => 'nullable|string|max:255',
            'property_type_id' => 'nullable|exists:property_types,id',
            'property_type_ids' => 'sometimes|array',
            'property_type_ids.*' => ['integer', 'distinct', Rule::exists('property_types', 'id')],
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'repair_type_ids' => 'sometimes|array',
            'repair_type_ids.*' => ['integer', 'distinct', Rule::exists('repair_types', 'id')],
            'rooms_from' => 'nullable|integer|min:0',
            'rooms_to' => 'nullable|integer|min:0',
            'area_from' => 'nullable|numeric|min:0',
            'area_to' => 'nullable|numeric|min:0',
            'comment' => 'nullable|string',
            'created_by' => 'nullable|integer|exists:users,id',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'wants_mortgage' => 'nullable|boolean',
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

    private function normalizeFinance(array $data, ?ClientNeed $clientNeed = null): array
    {
        $budgetCash = array_key_exists('budget_cash', $data)
            ? $data['budget_cash']
            : $clientNeed?->budget_cash;

        $budgetMortgage = array_key_exists('budget_mortgage', $data)
            ? $data['budget_mortgage']
            : $clientNeed?->budget_mortgage;

        if (
            !array_key_exists('budget_total', $data)
            && $budgetCash !== null
            && $budgetMortgage !== null
        ) {
            $data['budget_total'] = (float) $budgetCash + (float) $budgetMortgage;
        }

        if (array_key_exists('wants_mortgage', $data) && $data['wants_mortgage'] !== null) {
            $data['wants_mortgage'] = filter_var($data['wants_mortgage'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return $data;
    }

    private function normalizePropertyTypes(array $data, ?ClientNeed $clientNeed = null): array
    {
        $hasLegacyPropertyType = array_key_exists('property_type_id', $data);
        $hasPropertyTypeIds = array_key_exists('property_type_ids', $data);

        if (!$hasLegacyPropertyType && !$hasPropertyTypeIds) {
            return $data;
        }

        if ($hasPropertyTypeIds) {
            $data['property_type_ids'] = collect($data['property_type_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } else {
            $data['property_type_ids'] = $data['property_type_id']
                ? [(int) $data['property_type_id']]
                : [];
        }

        $data['property_type_id'] = $data['property_type_ids'][0] ?? null;

        return $data;
    }

    private function normalizeRepairTypes(array $data): array
    {
        $hasLegacyRepairType = array_key_exists('repair_type_id', $data);
        $hasRepairTypeIds = array_key_exists('repair_type_ids', $data);

        if (!$hasLegacyRepairType && !$hasRepairTypeIds) {
            return $data;
        }

        if ($hasRepairTypeIds) {
            $data['repair_type_ids'] = collect($data['repair_type_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } else {
            $data['repair_type_ids'] = $data['repair_type_id']
                ? [(int) $data['repair_type_id']]
                : [];
        }

        $data['repair_type_id'] = $data['repair_type_ids'][0] ?? null;

        return $data;
    }

    private function syncPropertyTypes(ClientNeed $need, array $propertyTypeIds): void
    {
        $propertyTypeIds = collect($propertyTypeIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (!Schema::hasTable('client_need_property_type')) {
            return;
        }

        $need->propertyTypes()->sync($propertyTypeIds);

        if ($propertyTypeIds === []) {
            $need->unsetRelation('propertyType');

            return;
        }

        if ($need->relationLoaded('propertyTypes')) {
            $need->setRelation(
                'propertyType',
                $need->propertyTypes->firstWhere('id', $propertyTypeIds[0]) ?? $need->propertyTypes->first()
            );

            return;
        }

        $need->setRelation('propertyType', PropertyType::query()->find($propertyTypeIds[0]));
    }

    private function syncRepairTypes(ClientNeed $need, array $repairTypeIds): void
    {
        $repairTypeIds = collect($repairTypeIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (!Schema::hasTable('client_need_repair_type')) {
            return;
        }

        $need->repairTypes()->sync($repairTypeIds);

        if ($repairTypeIds === []) {
            $need->unsetRelation('repairType');

            return;
        }

        if ($need->relationLoaded('repairTypes')) {
            $need->setRelation(
                'repairType',
                $need->repairTypes->firstWhere('id', $repairTypeIds[0]) ?? $need->repairTypes->first()
            );

            return;
        }

        $need->setRelation('repairType', RepairType::query()->find($repairTypeIds[0]));
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

    private function filterNeedColumns(array $data): array
    {
        static $columns = null;

        if ($columns === null) {
            $columns = array_flip(Schema::getColumnListing((new ClientNeed)->getTable()));
        }

        return collect($data)
            ->filter(fn ($_value, $key) => isset($columns[$key]))
            ->all();
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
        $validated = $this->normalizePropertyTypes($validated);
        $validated = $this->normalizeRepairTypes($validated);
        $validated = $this->normalizeFinance($validated);
        $validated['status_id'] ??= ClientNeedStatus::defaultId();
        $validated = $this->clientAccess->normalizeNeedMutationData($validated, $authUser, $client);
        $this->clientAccess->validateNeedMutationTargets($authUser, $client, $validated);
        $validated = $this->applyClosedState($validated);
        $validated['currency'] ??= 'TJS';

        $propertyTypeIds = $validated['property_type_ids'] ?? [];
        $repairTypeIds = $validated['repair_type_ids'] ?? [];
        unset($validated['property_type_ids']);
        unset($validated['repair_type_ids']);
        $validated = $this->filterNeedColumns($validated);

        $need = ClientNeed::create($validated);
        $this->syncPropertyTypes($need, $propertyTypeIds);
        $this->syncRepairTypes($need, $repairTypeIds);

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
        $validated = $this->normalizePropertyTypes($validated, $clientNeed);
        $validated = $this->normalizeRepairTypes($validated);
        $validated = $this->normalizeFinance($validated, $clientNeed);
        $validated = $this->clientAccess->normalizeNeedMutationData($validated, $authUser, $clientNeed->client);
        $this->clientAccess->validateNeedMutationTargets($authUser, $clientNeed->client, $validated);
        $validated = $this->applyClosedState($validated, $clientNeed);

        $propertyTypeIds = null;
        $repairTypeIds = null;
        if (array_key_exists('property_type_ids', $validated)) {
            $propertyTypeIds = $validated['property_type_ids'];
            unset($validated['property_type_ids']);
        }
        if (array_key_exists('repair_type_ids', $validated)) {
            $repairTypeIds = $validated['repair_type_ids'];
            unset($validated['repair_type_ids']);
        }
        $validated = $this->filterNeedColumns($validated);

        $clientNeed->update($validated);

        if ($propertyTypeIds !== null) {
            $this->syncPropertyTypes($clientNeed, $propertyTypeIds);
        }
        if ($repairTypeIds !== null) {
            $this->syncRepairTypes($clientNeed, $repairTypeIds);
        }

        return response()->json($clientNeed->fresh($this->relations()));
    }

    public function destroy(ClientNeed $clientNeed)
    {
        $this->clientAccess->ensureNeedVisible($this->authUser(), $clientNeed);

        $clientNeed->delete();

        return response()->json(['message' => 'Client need deleted']);
    }
}
