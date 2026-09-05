<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePropertyDealRequest;
use App\Models\Client;
use App\Models\Property;
use App\Models\PropertyLog;
use App\Models\PropertyModerationCase;
use App\Models\User;
use App\Services\Crm\ClientAttachService;
use App\Services\Crm\Matching\ClientPropertyMatcher;
use App\Services\PropertyDuplicateService;
use App\Services\PropertyModeration\PropertyModerationAccess;
use App\Services\PropertyModeration\PropertyModerationService;
use App\Services\PropertyQualityService;
use App\Support\ClientAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class PropertyController extends Controller
{
    protected ImageManager $imageManager;

    protected ClientAccess $clientAccess;

    protected ClientAttachService $clientAttachService;

    protected ClientPropertyMatcher $clientPropertyMatcher;

    protected PropertyDuplicateService $propertyDuplicateService;

    protected PropertyQualityService $propertyQualityService;

    protected PropertyModerationService $moderation;

    protected PropertyModerationAccess $moderationAccess;

    public function __construct(
        PropertyDuplicateService $propertyDuplicateService,
        PropertyQualityService $propertyQualityService,
        PropertyModerationService $moderation,
        PropertyModerationAccess $moderationAccess,
    ) {
        $this->imageManager = new ImageManager(new Driver);
        $this->clientAccess = app(ClientAccess::class);
        $this->clientAttachService = app(ClientAttachService::class);
        $this->clientPropertyMatcher = app(ClientPropertyMatcher::class);
        $this->propertyDuplicateService = $propertyDuplicateService;
        $this->propertyQualityService = $propertyQualityService;
        $this->moderation = $moderation;
        $this->moderationAccess = $moderationAccess;
    }

    private function propertyDetailRelations(): array
    {
        $relations = [
            'type',
            'status',
            'location',
            'repairType',
            'photos',
            'creator',
            'contractType',
            'documentType',
            'developer',
            'heating',
            'parking',
            'buildingType',
            'ownerClient.type',
            'buyerClient.type',
            'depositUser',
            'saleUser',
        ];

        if ($this->supportsPropertyCoOwner()) {
            $relations[] = 'coOwner.role';
        }

        if ($this->supportsPropertyFeatures()) {
            $relations[] = 'features';
        }

        if ($this->supportsPropertyTags()) {
            $relations[] = 'tags';
        }

        if (Schema::hasTable('property_promotions')) {
            $relations[] = 'activePromotion';
        }

        return $relations;
    }

    private function supportsPropertyCoOwner(): bool
    {
        return Schema::hasColumn('properties', 'co_owner_user_id');
    }

    private function supportsPropertyFeatures(): bool
    {
        return Schema::hasTable('features') && Schema::hasTable('feature_property');
    }

    private function supportsPropertyTags(): bool
    {
        return Schema::hasTable('tags') && Schema::hasTable('property_tag');
    }

    private function propertyMutationRelations(): array
    {
        $relations = ['photos', 'contractType', 'documentType', 'ownerClient.type', 'buyerClient.type', 'coOwner.role'];

        if (Schema::hasTable('property_promotions')) {
            $relations[] = 'activePromotion';
        }

        if ($this->supportsPropertyFeatures()) {
            $relations[] = 'features';
        }

        if ($this->supportsPropertyTags()) {
            $relations[] = 'tags';
        }

        return $relations;
    }

    private function propertySearchRelations(): array
    {
        $relations = ['type', 'status', 'location', 'photos', 'creator', 'developer'];

        if (Schema::hasTable('property_promotions')) {
            $relations[] = 'activePromotion';
        }

        if ($this->supportsPropertyTags()) {
            $relations[] = 'tags';
        }

        return $relations;
    }

    private function propertyShowAuthUser(Request $request): ?User
    {
        $user = $request->user() ?? $request->user('sanctum');
        $user?->loadMissing('role');

        return $user;
    }

    private function crmAuthUser(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function hasOwnPropertyScope(User $user): bool
    {
        return $user->hasRole('agent') || $user->hasRole('intern');
    }

    private function authorizePropertyMutation(Property $property): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        if (! $this->moderationAccess->canEdit($user, $property)) {
            abort(403, 'Доступ запрещён');
        }

        return $user;
    }

    private function listingDateRefreshState(Property $property): array
    {
        $nextAvailableAt = null;

        if (Schema::hasTable('property_logs')) {
            $lastRefresh = PropertyLog::query()
                ->where('property_id', $property->id)
                ->where('action', 'listing_date_refreshed')
                ->latest('created_at')
                ->first();

            if ($lastRefresh?->created_at) {
                $cooldownSeconds = max(
                    0,
                    (int) config('property-listing.date_refresh_cooldown_seconds', 86_400)
                );
                $nextAvailableAt = $lastRefresh->created_at->copy()->addSeconds($cooldownSeconds);
            }
        }

        $available = $this->moderation->isPublic($property)
            && ($nextAvailableAt === null || now()->greaterThanOrEqualTo($nextAvailableAt));

        return [
            'available' => $available,
            'next_available_at' => $available ? null : $nextAvailableAt?->toJSON(),
        ];
    }

    private function propertyCapabilities(?User $user, Property $property, ?array $refreshState = null): array
    {
        $workflow = $this->moderationAccess->capabilities($user, $property);
        $canMutate = $workflow['can_edit'];
        $refreshState ??= $this->listingDateRefreshState($property);

        return array_merge($workflow, [
            'can_refresh_listing_date' => $canMutate && $refreshState['available'],
            'can_view_history' => $canMutate,
            'can_moderate' => $workflow['can_approve'],
            'can_manage_co_owner' => $canMutate,
        ]);
    }

    /**
     * An agent may accept a deposit or close a colleague's property from any
     * branch. For a sale, an agent can only set themselves as seller.
     * This does not grant general edit access to colleagues' properties.
     */
    private function authorizePropertyDealMutation(
        Property $property,
        string $moderationStatus,
        ?int $saleUserId = null
    ): User {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');
        abort_unless($this->moderationAccess->canManageDeal($user, $property), 403, 'MODERATION_PERMISSION_DENIED');

        if ($this->moderationAccess->canEdit($user, $property)) {
            return $user;
        }

        $isColleagueDealAction = $moderationStatus === 'deposit'
            || in_array($moderationStatus, ['sold', 'sold_by_owner', 'rented'], true);

        $canManageColleagueDeal = $isColleagueDealAction
            && $user->hasRole('agent')
            && (
                $moderationStatus === 'deposit'
                || $saleUserId === null
                || $saleUserId === (int) $user->id
            );

        abort_unless($canManageColleagueDeal, 403, 'Доступ запрещён');

        return $user;
    }

    private function canAssignDealUser(User $actor): bool
    {
        return $actor->hasRole('admin')
            || $actor->hasRole('superadmin')
            || $actor->hasRole('agent')
            || $actor->hasRole('intern')
            || $actor->hasRole('mop')
            || $actor->hasRole('rop')
            || $actor->hasRole('branch_director')
            || $actor->hasRole('manager')
            || $actor->hasRole('operator');
    }

    /** Fields that belong exclusively to the deal DTO, not publication. */
    private function dealProtectedFieldExceptions(): array
    {
        return [
            'deal_status', 'status_comment', 'buyer_client_id', 'buyer_full_name',
            'buyer_phone', 'deposit_amount', 'deposit_currency', 'deposit_received_at',
            'deposit_taken_at', 'deposit_user_id', 'money_holder', 'money_received_at',
            'contract_signed_at', 'company_expected_income', 'company_expected_income_currency',
            'company_commission_amount', 'company_commission_currency', 'actual_sale_price',
            'actual_sale_currency', 'planned_contract_signed_at', 'sale_user_id', 'agents',
        ];
    }

    private function canAssignSpecificCoOwner(User $actor, User $coOwner): bool
    {
        if ($actor->hasRole('admin') || $actor->hasRole('superadmin')) {
            return true;
        }

        return ! empty($actor->branch_id)
            && ! empty($coOwner->branch_id)
            && (int) $actor->branch_id === (int) $coOwner->branch_id;
    }

    private function ensureDealAssigneeExists(int $assigneeId): void
    {
        $assignee = User::query()->find($assigneeId);

        abort_unless($assignee, 422, 'Указанный сотрудник не найден.');
    }

    private function ensureValidCoOwner(User $actor, ?int $coOwnerUserId, ?int $ownerUserId): void
    {
        if (! $coOwnerUserId) {
            return;
        }

        abort_if(
            $ownerUserId && (int) $coOwnerUserId === (int) $ownerUserId,
            422,
            'Соучастник не может быть владельцем объявления.'
        );

        $coOwner = User::query()->find($coOwnerUserId);

        abort_unless($coOwner, 422, 'Соучастник не найден.');
        abort_unless($coOwner->status === User::STATUS_ACTIVE, 422, 'Соучастник должен быть активным пользователем.');
        abort_unless(
            $this->canAssignSpecificCoOwner($actor, $coOwner),
            403,
            'Недостаточно прав, чтобы назначить этого соучастника.'
        );
    }

    private function ensureDealAssignmentUsersInScope(User $actor, array $data, ?Property $property = null): void
    {
        $fields = [
            'deposit_user_id' => 'Недостаточно прав, чтобы указать сотрудника как принявшего депозит.',
            'sale_user_id' => 'Недостаточно прав, чтобы указать продавца сделки.',
        ];

        foreach ($fields as $field => $message) {
            if (empty($data[$field])) {
                continue;
            }

            if ($property && (int) $property->{$field} === (int) $data[$field]) {
                continue;
            }

            abort_unless(
                $this->canAssignDealUser($actor),
                403,
                $message
            );

            $this->ensureDealAssigneeExists((int) $data[$field]);
        }

        foreach (($data['agents'] ?? []) as $index => $agent) {
            if (empty($agent['agent_id'])) {
                continue;
            }

            abort_unless(
                $this->canAssignDealUser($actor),
                403,
                'Недостаточно прав, чтобы добавить соисполнителя #'.($index + 1).'.'
            );

            $this->ensureDealAssigneeExists((int) $agent['agent_id']);
        }
    }

    private function serializePropertyShow(Property $property, ?User $authUser): array
    {
        $includeAuthContacts = $authUser !== null;

        if ($includeAuthContacts) {
            $property->loadMissing(['externalAgent', 'externalPropertyRequest']);
            $property->makeVisible([
                'owner_client_id',
                'owner_name',
                'owner_phone',
                'buyer_client_id',
                'buyer_full_name',
                'buyer_phone',
            ]);
        }

        $payload = $property->toArray();
        $payload['branch_id'] = $this->resolvePropertyBranchId($property);
        $payload['branch_group_id'] = $this->resolvePropertyBranchGroupId($property);

        if (isset($payload['creator']) && is_array($payload['creator'])) {
            $payload['creator']['branch_id'] = $this->resolveUserBranchId(
                $property->created_by ?: ($payload['creator']['id'] ?? null),
                $property->relationLoaded('creator') ? $property->creator : null
            );
            $payload['creator']['branch_group_id'] = $this->resolveUserBranchGroupId(
                $property->created_by ?: ($payload['creator']['id'] ?? null),
                $property->relationLoaded('creator') ? $property->creator : null
            );
        }

        if ($includeAuthContacts) {
            $payload['ownerClient'] = $payload['owner_client'] ?? null;
            $payload['buyerClient'] = $payload['buyer_client'] ?? null;
            $payload['external_source'] = $property->external_agent_id ? [
                'source_type' => $property->source_type,
                'external_agent_id' => $property->external_agent_id,
                'external_agent_name' => $property->externalAgent?->name,
                'external_property_request_id' => $property->external_property_request_id,
                'external_property_request_status' => $property->externalPropertyRequest?->status,
                'external_property_request_display_status' => $property->externalPropertyRequest?->display_status,
                'submitted_at' => $property->externalPropertyRequest?->submitted_at?->toJSON(),
            ] : null;
            $refreshState = $this->listingDateRefreshState($property);
            $payload['capabilities'] = $this->propertyCapabilities($authUser, $property, $refreshState);
            $payload['listing_date_refresh'] = $refreshState;
        }

        if ($authUser && (
            $this->moderationAccess->canEdit($authUser, $property)
            || $this->moderationAccess->canModerate($authUser, $property)
        )) {
            $payload = $this->withModerationWorkflow($property, $authUser, $payload);
        }

        return $payload;
    }

    private function withModerationWorkflow(Property $property, User $user, ?array $payload = null): array
    {
        $payload ??= $property->toArray();
        if (! Schema::hasTable('property_moderation_cases')) {
            $payload['moderation_reasons'] = [];
            $payload['open_moderation_cases'] = [];
            $payload['price_review'] = null;
            $payload['capabilities'] = $this->propertyCapabilities($user, $property);

            return $payload;
        }

        $cases = $property->moderationCases()
            ->open()
            ->with(['submitter.role', 'duplicateCandidates.candidateProperty.photos'])
            ->orderBy('submitted_at')
            ->get();
        $priceCase = $cases->firstWhere('type', PropertyModerationCase::TYPE_PRICE_INCREASE);
        $baseline = (array) ($priceCase?->baseline_snapshot ?? []);
        $proposed = (array) ($priceCase?->proposed_snapshot ?? []);
        $oldEffective = isset($baseline['effective_price']) ? (float) $baseline['effective_price'] : null;
        $newEffective = isset($proposed['effective_price']) ? (float) $proposed['effective_price'] : null;
        $difference = $oldEffective !== null && $newEffective !== null ? $newEffective - $oldEffective : null;

        $payload['moderation_reasons'] = $cases->pluck('reason_codes')->flatten()->filter()->unique()->values()->all();
        $payload['open_moderation_cases'] = $cases->toArray();
        $payload['price_review'] = $priceCase ? [
            'approved' => $baseline,
            'proposed' => $proposed,
            'absolute_difference' => $difference,
            'percent_difference' => $difference !== null && $oldEffective > 0
                ? round(($difference / $oldEffective) * 100, 2)
                : null,
            'submitted_by' => $priceCase->submitted_by,
            'submitted_at' => $priceCase->submitted_at?->toJSON(),
            'reason_codes' => $priceCase->reason_codes ?? [],
        ] : null;
        $payload['moderation_version'] = (int) $property->moderation_version;
        $payload['capabilities'] = $this->propertyCapabilities($user, $property);

        return $payload;
    }

    private function resolvePropertyBranchId(Property $property): ?int
    {
        $ownBranchId = $this->normalizeBranchId($property->getAttributes()['branch_id'] ?? null);

        if ($ownBranchId !== null) {
            return $ownBranchId;
        }

        return $this->resolveUserBranchId(
            $property->agent_id,
            $property->relationLoaded('agent') ? $property->agent : null
        )
            ?? $this->resolveUserBranchId(
                $property->created_by,
                $property->relationLoaded('creator') ? $property->creator : null
            )
            ?? $this->resolveUserBranchId(
                $property->relationLoaded('creator') ? $property->creator?->id : null,
                $property->relationLoaded('creator') ? $property->creator : null
            );
    }

    private function resolvePropertyBranchGroupId(Property $property): ?int
    {
        $ownBranchGroupId = $this->normalizeBranchId($property->getAttributes()['branch_group_id'] ?? null);

        if ($ownBranchGroupId !== null) {
            return $ownBranchGroupId;
        }

        return $this->resolveUserBranchGroupId(
            $property->agent_id,
            $property->relationLoaded('agent') ? $property->agent : null
        )
            ?? $this->resolveUserBranchGroupId(
                $property->created_by,
                $property->relationLoaded('creator') ? $property->creator : null
            )
            ?? $this->resolveUserBranchGroupId(
                $property->relationLoaded('creator') ? $property->creator?->id : null,
                $property->relationLoaded('creator') ? $property->creator : null
            );
    }

    private function resolveUserBranchId($userId, ?User $loadedUser = null): ?int
    {
        if ($loadedUser && (int) $loadedUser->id === (int) $userId) {
            $branchId = $this->normalizeBranchId($loadedUser->getAttributes()['branch_id'] ?? null);

            if ($branchId !== null) {
                return $branchId;
            }
        }

        if (empty($userId) || ! Schema::hasColumn('users', 'branch_id')) {
            return null;
        }

        return $this->normalizeBranchId(
            User::query()->whereKey($userId)->value('branch_id')
        );
    }

    private function resolveUserBranchGroupId($userId, ?User $loadedUser = null): ?int
    {
        if ($loadedUser && (int) $loadedUser->id === (int) $userId) {
            $branchGroupId = $this->normalizeBranchId($loadedUser->getAttributes()['branch_group_id'] ?? null);

            if ($branchGroupId !== null) {
                return $branchGroupId;
            }
        }

        if (empty($userId) || ! Schema::hasColumn('users', 'branch_group_id')) {
            return null;
        }

        return $this->normalizeBranchId(
            User::query()->whereKey($userId)->value('branch_group_id')
        );
    }

    private function normalizeBranchId($branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }

    private function normalizeMopBranchGroupPayload(User $user, array $data): array
    {
        if (! $user->hasRole('mop')) {
            return $data;
        }

        if (empty($user->branch_group_id)) {
            abort(403, 'Доступ запрещён');
        }

        if (
            array_key_exists('branch_group_id', $data)
            && $data['branch_group_id'] !== null
            && $data['branch_group_id'] !== ''
            && (int) $data['branch_group_id'] !== (int) $user->branch_group_id
        ) {
            abort(403, 'Доступ запрещён');
        }

        if (Schema::hasColumn('properties', 'branch_group_id')) {
            $data['branch_group_id'] = $user->branch_group_id;
        }

        return $data;
    }

    private function syncClientContactKind(?Client $client, string $contactKind): void
    {
        if (! $client) {
            return;
        }

        $mergedContactKind = $client->mergedContactKindFor($contactKind);

        if ($mergedContactKind !== $client->contact_kind) {
            $client->update(['contact_kind' => $mergedContactKind]);
            $client->contact_kind = $mergedContactKind;
        }
    }

    private function syncPropertyClientSnapshots(array $data): array
    {
        if (! empty($data['owner_client_id'])) {
            $ownerClient = Client::query()->with('type')->find($data['owner_client_id']);
            if ($ownerClient) {
                $this->syncClientContactKind($ownerClient, Client::CONTACT_KIND_SELLER);
                $data['owner_name'] = $ownerClient->full_name;
                $data['owner_phone'] = $ownerClient->phone;
                $data['is_business_owner'] = (bool) ($ownerClient->type?->is_business ?? false);
            }
        }

        if (! empty($data['buyer_client_id'])) {
            $buyerClient = Client::query()->with('type')->find($data['buyer_client_id']);
            if ($buyerClient) {
                $this->syncClientContactKind($buyerClient, Client::CONTACT_KIND_BUYER);
                $data['buyer_full_name'] = $buyerClient->full_name;
                $data['buyer_phone'] = $buyerClient->phone;
            }
        }

        return $data;
    }

    private function ensureVisibleClientsForProperty(array $data, ?Property $property = null): void
    {
        $authUser = auth()->user();
        $currentProperty = null;

        if (! $authUser) {
            return;
        }

        if ($property?->exists) {
            $currentProperty = Property::query()
                ->select(['id', 'owner_client_id', 'buyer_client_id'])
                ->find($property->getKey());
        }

        $attachments = [];
        foreach (['owner_client_id', 'buyer_client_id'] as $field) {
            if (empty($data[$field])) {
                continue;
            }

            if ($currentProperty && (int) $currentProperty->{$field} === (int) $data[$field]) {
                continue;
            }

            $client = Client::query()->findOrFail($data[$field]);
            $attachContext = $property?->exists
                ? $this->clientAttachService->normalizedContext([
                    'context_type' => ClientAttachService::CONTEXT_PROPERTY,
                    'context_id' => $property->getKey(),
                    'property_relation' => $field === 'owner_client_id' ? 'owner' : 'buyer',
                ])
                : $this->clientAttachService->normalizedContext([
                    'context_type' => ClientAttachService::CONTEXT_CLIENT,
                ]);

            if ($this->clientAccess->visibleQuery($authUser)->whereKey($client->id)->exists()) {
                continue;
            }

            // Initial attachment may grant shared access; replacing an assigned
            // contact still requires visibility of the replacement.
            if (
                ! empty($currentProperty?->{$field})
                || ! $this->clientAttachService->canAttachClient($authUser, $client, $attachContext)
            ) {
                $this->clientAccess->ensureVisible($authUser, $client);
            }

            $attachments[] = [$client, $attachContext];
        }

        // Validate both relations before granting access to either contact.
        DB::transaction(function () use ($authUser, $attachments): void {
            foreach ($attachments as [$client, $attachContext]) {
                $this->clientAttachService->attach($authUser, $client, $attachContext);
            }
        });
    }

    // ==== Список (как у тебя), но на общих методах ====
    /**
     * GET /api/properties
     *
     * @queryParam document_type_ids array Multiple property document type IDs. Example: [1,3]
     * @queryParam document_type_id integer Single property document type ID. Example: 1
     * @queryParam construction_status string Filter by construction stage.
     * Allowed: under_construction, built, commissioned.
     * Example: commissioned
     */
    public function index(Request $request)
    {
        $this->validateListFilters($request);

        $compact = $request->boolean('compact');
        $query = $this->baseQuery($request, $compact ? $this->propertyCompactRelations() : null);
        $this->applyFilters($query, $request);
        $this->applySorts($query, $request->input('sort'), $request->input('dir'));
        $perPage = (int) $request->input('per_page', 20);

        $page = $query->latest()->paginate($perPage);
        if ($compact) {
            $page->through(fn (Property $property): array => $this->compactFeedProperty($property));
        }

        return response()->json($page);
    }

    /**
     * Count properties using the same filters and access scope as the list.
     * This deliberately uses SQL COUNT(*) and never eager-loads relations.
     *
     * @queryParam document_type_ids array Multiple property document type IDs. Example: [1,3]
     * @queryParam document_type_id integer Single property document type ID. Example: 1
     */
    public function count(Request $request)
    {
        $this->validateListFilters($request);

        $query = $this->baseQuery($request, []);
        $this->applyFilters($query, $request);

        return response()->json([
            'count' => $query->count(),
        ]);
    }

    /**
     * GET /api/my-properties
     *
     * @queryParam document_type_ids array Multiple property document type IDs. Example: [1,3]
     * @queryParam document_type_id integer Single property document type ID. Example: 1
     */
    public function myProperties(Request $request)
    {
        $this->validateListFilters($request);

        $query = $this->baseQueryMyProperties($request);
        $this->applyFilters($query, $request);
        $this->applySorts($query, $request->input('sort'), $request->input('dir'));
        $perPage = (int) $request->input('per_page', 20);

        return response()->json($query->latest()->paginate($perPage));
    }

    private function propertyListRelations(): array
    {
        $relations = [
            'type',
            'status',
            'location',
            'repairType',
            'photos',
            'creator',
            'heating',
            'parking',
            'ownerClient.type',
            'buyerClient.type',
        ];

        if (Schema::hasTable('contract_types')) {
            $relations[] = 'contractType';
        }

        if (Schema::hasTable('document_types') && Schema::hasColumn('properties', 'document_type_id')) {
            $relations[] = 'documentType';
        }

        if ($this->supportsPropertyCoOwner()) {
            $relations[] = 'coOwner.role';
        }

        if ($this->supportsPropertyFeatures()) {
            $relations[] = 'features';
        }

        if ($this->supportsPropertyTags()) {
            $relations[] = 'tags';
        }

        return $relations;
    }

    private function propertyCompactRelations(): array
    {
        $relations = ['type', 'status', 'location', 'repairType', 'photos', 'creator', 'heating', 'parking', 'developer'];

        if (Schema::hasTable('document_types') && Schema::hasColumn('properties', 'document_type_id')) {
            $relations[] = 'documentType';
        }

        return $relations;
    }

    private function compactFeedProperty(Property $property): array
    {
        $item = $property->only([
            'id', 'title', 'description', 'type_id', 'status_id', 'location_id', 'repair_type_id',
            'heating_type_id', 'parking_type_id', 'document_type_id', 'developer_id', 'created_by',
            'price', 'discount_price', 'currency', 'offer_type', 'rooms', 'total_area', 'land_size',
            'living_area', 'floor', 'total_floors', 'year_built', 'condition', 'construction_status',
            'apartment_type', 'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
            'landmark', 'latitude', 'longitude', 'district', 'address', 'listing_type', 'views_count',
            'moderation_status', 'publication_expires_at', 'listed_at', 'created_at', 'updated_at',
        ]);
        foreach ([
            'type' => ['id', 'name', 'slug'],
            'status' => ['id', 'name', 'slug'],
            'location' => ['id', 'name', 'slug'],
            'repair_type' => ['id', 'name', 'slug'],
            'heating' => ['id', 'name', 'slug'],
            'parking' => ['id', 'name', 'slug'],
            'document_type' => ['id', 'name', 'slug'],
            'developer' => ['id', 'name', 'slug', 'logo'],
            'creator' => ['id', 'name', 'photo'],
        ] as $key => $fields) {
            $relationName = match ($key) {
                'repair_type' => 'repairType',
                'document_type' => 'documentType',
                default => $key,
            };
            $relation = $property->relationLoaded($relationName) ? $property->getRelation($relationName) : null;
            $item[$key] = $relation?->only($fields);
        }
        $item['photos'] = $property->photos
            ->map(fn ($photo): array => $photo->only(['id', 'file_path', 'path', 'url', 'type', 'is_main', 'position']))
            ->values()
            ->all();

        return $item;
    }

    private function baseQueryMyProperties(Request $request): Builder
    {
        $user = auth()->user();
        $query = Property::query()->with(array_merge($this->propertyListRelations(), ['saleAgents']));

        $hasStatusFilter = $request->filled('moderation_status');

        if ($user && ($user->hasRole('admin') || $user->hasRole('superadmin'))) {
            // без ограничений
        } elseif (! $user || $user->hasRole('client')) {
            $query->publicSearchable();
        } elseif ($this->hasOwnPropertyScope($user)) {
            $query->where(function (Builder $ownerQuery) use ($user) {
                $ownerQuery->where('created_by', $user->id);

                if ($this->supportsPropertyCoOwner()) {
                    $ownerQuery->orWhere('co_owner_user_id', $user->id);
                }
            });
            if (! $hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        } elseif ($user->hasRole('mop')) {
            if (empty($user->branch_group_id)) {
                $query->whereRaw('1 = 0');
            } else {
                $this->applyBranchGroupFilter($query, [$user->branch_group_id]);
            }

            if (! $hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        }

        return $query;
    }

    // ==== Общая база для index/map: роли, связи, базовые статусы ====
    private function baseQuery(Request $request, ?array $relations = null): Builder
    {
        $user = $this->propertyShowAuthUser($request);
        $query = Property::query()->with($relations ?? $this->propertyListRelations());

        $hasStatusFilter = $request->filled('moderation_status');

        if ($user && ($user->hasRole('admin') || $user->hasRole('superadmin'))) {
            // без ограничений
        } elseif (! $user || $user->hasRole('client')) {
            $query->publicSearchable();
        } elseif ($this->hasOwnPropertyScope($user)) {
            $query->where(function (Builder $ownerQuery) use ($user) {
                $ownerQuery->where('created_by', $user->id);

                if ($this->supportsPropertyCoOwner()) {
                    $ownerQuery->orWhere('co_owner_user_id', $user->id);
                }
            });
            if (! $hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        } elseif ($user->hasRole('mop')) {
            if (empty($user->branch_group_id)) {
                $query->whereRaw('1 = 0');
            } else {
                $this->applyBranchGroupFilter($query, [$user->branch_group_id]);
            }

            if (! $hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        }

        return $query;
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'deal_type' => ['sometimes', 'nullable', Rule::in(['sale', 'rent'])],
            'property_type_id' => ['sometimes', 'nullable', 'integer'],
            'status_id' => ['sometimes', 'nullable', 'integer'],
            'location_id' => ['sometimes', 'nullable', 'integer'],
            'district' => ['sometimes', 'nullable', 'string'],
            'price_from' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', Rule::in(['TJS', 'USD'])],
            'rooms_from' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rooms_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'area_from' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'area_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'document_type_ids' => ['sometimes', 'nullable', 'array'],
            'document_type_ids.*' => ['integer', 'distinct', 'exists:document_types,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            'sort' => ['sometimes', 'nullable', Rule::in(['relevance', 'newest', 'price_asc', 'price_desc'])],
        ]);

        $this->validateRangeOrder($validated, [
            ['price_from', 'price_to'],
            ['rooms_from', 'rooms_to'],
            ['area_from', 'area_to'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        if ($q !== '' && mb_strlen($q, 'UTF-8') < 2 && ! ctype_digit($q)) {
            return response()->json([
                'message' => 'Минимум 2 символа для поиска',
            ], 422);
        }

        $authUser = $this->propertyShowAuthUser($request);
        $canSearchCrmFields = $this->canSearchPropertyCrmFields($authUser);
        $sort = $validated['sort'] ?? 'relevance';
        $perPage = min((int) ($validated['per_page'] ?? 20), 50);

        $query = $this->baseQuery($request, $this->propertySearchRelations())
            ->publicSearchable();

        $this->applyPropertySearchFilters($query, $validated);

        if ($q !== '') {
            $this->applyPropertySearchText($query, $q, $canSearchCrmFields);
        }

        $query->select('properties.*');
        $this->applyPropertySearchSort($query, $sort, $q, $canSearchCrmFields);

        try {
            $paginator = $query->paginate($perPage);
        } catch (QueryException $e) {
            Log::warning('Property search relevance query failed, retrying with safe fallback.', [
                'query' => $q,
                'sort' => $sort,
                'error' => $e->getMessage(),
            ]);

            $query = $this->baseQuery($request, $this->propertySearchRelations())
                ->publicSearchable();

            $this->applyPropertySearchFilters($query, $validated);
            $this->applyPropertySearchSafeFallback($query, $q);
            $query->select('properties.*')
                ->orderByDesc('properties.created_at')
                ->orderByDesc('properties.id');

            $paginator = $query->paginate($perPage);
        }

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Property $property) => $this->serializePropertySearchResult($property))
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    private function canSearchPropertyCrmFields(?User $user): bool
    {
        return $user !== null && ! $user->hasRole('client');
    }

    private function applyPropertySearchFilters(Builder $query, array $filters): void
    {
        $map = [
            'deal_type' => 'offer_type',
            'property_type_id' => 'type_id',
            'status_id' => 'status_id',
            'location_id' => 'location_id',
            'district' => 'district',
            'currency' => 'currency',
        ];

        foreach ($map as $param => $column) {
            if (array_key_exists($param, $filters) && $filters[$param] !== null && $filters[$param] !== '') {
                $query->where($column, $filters[$param]);
            }
        }

        if (! empty($filters['document_type_ids'])) {
            $query->whereIn(
                'document_type_id',
                array_values(array_unique(array_map('intval', $filters['document_type_ids'])))
            );
        } elseif (! empty($filters['document_type_id'])) {
            $query->where('document_type_id', (int) $filters['document_type_id']);
        }

        foreach ([
            'rooms_from' => ['rooms', '>='],
            'rooms_to' => ['rooms', '<='],
            'area_from' => ['total_area', '>='],
            'area_to' => ['total_area', '<='],
        ] as $param => [$column, $operator]) {
            if (array_key_exists($param, $filters) && $filters[$param] !== null && $filters[$param] !== '') {
                $query->where($column, $operator, $filters[$param]);
            }
        }

        $hasPriceRange = isset($filters['price_from']) || isset($filters['price_to']);
        if ($hasPriceRange) {
            if (empty($filters['currency'])) {
                $query->where('currency', 'TJS');
            }

            $this->applyEffectivePriceRange(
                $query,
                $filters['price_from'] ?? null,
                $filters['price_to'] ?? null
            );
        }

        if (
            $this->supportsPropertyTags()
            && ! empty($filters['tag_ids'])
        ) {
            $tagIds = array_values(array_unique(array_map('intval', $filters['tag_ids'])));

            $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->whereIn('tags.id', $tagIds));
        }
    }

    private function applyPropertySearchText(Builder $query, string $term, bool $includeCrmFields): void
    {
        $like = '%'.$this->escapeLikeTerm(mb_strtolower($term, 'UTF-8')).'%';
        $isNumericId = ctype_digit($term);
        $roomCount = $this->extractRoomSearchHint($term);

        $query->where(function (Builder $searchQuery) use ($term, $like, $isNumericId, $includeCrmFields, $roomCount) {
            if ($isNumericId) {
                $searchQuery->orWhere('properties.id', (int) $term);
            }

            if ($roomCount !== null && Schema::hasColumn('properties', 'rooms')) {
                $searchQuery->orWhere('properties.rooms', $roomCount);
            }

            foreach ($this->propertySearchTextColumns() as $column) {
                $searchQuery->orWhereRaw("LOWER(properties.{$column}) LIKE ? ESCAPE '\\'", [$like]);
            }

            if ($includeCrmFields) {
                foreach ($this->propertySearchCrmColumns() as $column) {
                    $searchQuery->orWhereRaw("LOWER(properties.{$column}) LIKE ? ESCAPE '\\'", [$like]);
                }
            }

            $searchQuery
                ->orWhereHas('developer', fn (Builder $developerQuery) => $developerQuery
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]))
                ->orWhereHas('location', fn (Builder $locationQuery) => $locationQuery
                    ->whereRaw("LOWER(city) LIKE ? ESCAPE '\\'", [$like]))
                ->orWhereHas('type', fn (Builder $typeQuery) => $typeQuery
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$like]))
                ->orWhereHas('status', fn (Builder $statusQuery) => $statusQuery
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$like]));

            if ($this->supportsPropertyTags()) {
                $searchQuery->orWhereHas('tags', fn (Builder $tagQuery) => $tagQuery
                    ->whereRaw("LOWER(tags.name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(tags.slug) LIKE ? ESCAPE '\\'", [$like]));
            }
        });
    }

    private function applyPropertySearchSort(Builder $query, string $sort, string $term, bool $includeCrmFields): void
    {
        if ($sort === 'newest' || ($sort === 'relevance' && $term === '')) {
            $query->orderByDesc('properties.created_at')->orderByDesc('properties.id');

            return;
        }

        if ($sort === 'price_asc') {
            $query->orderByRaw($this->effectivePriceSql('properties').' ASC')
                ->orderByDesc('properties.created_at');

            return;
        }

        if ($sort === 'price_desc') {
            $query->orderByRaw($this->effectivePriceSql('properties').' DESC')
                ->orderByDesc('properties.created_at');

            return;
        }

        $lowerTerm = mb_strtolower($term, 'UTF-8');
        $like = '%'.$this->escapeLikeTerm($lowerTerm).'%';
        $cases = ['WHEN properties.id = ? THEN 1000'];
        $bindings = [ctype_digit($term) ? (int) $term : 0];

        if (Schema::hasColumn('properties', 'title')) {
            $cases[] = 'WHEN LOWER(properties.title) = ? THEN 800';
            $bindings[] = $lowerTerm;
            $cases[] = "WHEN LOWER(properties.title) LIKE ? ESCAPE '\\' THEN 700";
            $bindings[] = $like;
        }

        foreach ([
            'address' => 600,
            'district' => 550,
            'description' => 350,
        ] as $column => $score) {
            if (Schema::hasColumn('properties', $column)) {
                $cases[] = "WHEN LOWER(properties.{$column}) LIKE ? ESCAPE '\\' THEN {$score}";
                $bindings[] = $like;
            }
        }

        if ($includeCrmFields) {
            foreach ($this->propertySearchCrmColumns() as $column) {
                $cases[] = "WHEN LOWER(properties.{$column}) LIKE ? ESCAPE '\\' THEN 240";
                $bindings[] = $like;
            }
        }

        if ($this->supportsPropertyTags()) {
            $cases[] = "WHEN EXISTS (
                SELECT 1
                FROM property_tag
                INNER JOIN tags ON tags.id = property_tag.tag_id
                WHERE property_tag.property_id = properties.id
                  AND (LOWER(tags.name) LIKE ? ESCAPE '\\' OR LOWER(tags.slug) LIKE ? ESCAPE '\\')
            ) THEN 500";
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $caseSql = implode("\n", $cases);

        $query
            ->orderByRaw("
                CASE
                    {$caseSql}
                    ELSE 100
                END DESC
            ", $bindings)
            ->orderByDesc('properties.created_at')
            ->orderByDesc('properties.id');
    }

    private function applyPropertySearchSafeFallback(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $roomCount = $this->extractRoomSearchHint($term);
        $isNumericId = ctype_digit($term);

        if (! $isNumericId && $roomCount === null) {
            return;
        }

        $query->where(function (Builder $fallbackQuery) use ($term, $roomCount, $isNumericId) {
            if ($isNumericId) {
                $fallbackQuery->orWhere('properties.id', (int) $term);
            }

            if ($roomCount !== null && Schema::hasColumn('properties', 'rooms')) {
                $fallbackQuery->orWhere('properties.rooms', $roomCount);
            }
        });
    }

    private function propertySearchTextColumns(): array
    {
        return array_values(array_filter(
            ['title', 'description', 'address', 'district', 'landmark'],
            static fn (string $column): bool => Schema::hasColumn('properties', $column)
        ));
    }

    private function propertySearchCrmColumns(): array
    {
        return array_values(array_filter(
            ['owner_name', 'owner_phone'],
            static fn (string $column): bool => Schema::hasColumn('properties', $column)
        ));
    }

    private function extractRoomSearchHint(string $term): ?int
    {
        $normalized = mb_strtolower(trim($term), 'UTF-8');

        if (preg_match('/\b([1-9])\s*[- ]?\s*(комн|комнат|room|rooms|кк|к)\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function escapeLikeTerm(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    private function serializePropertySearchResult(Property $property): array
    {
        return [
            'id' => (int) $property->id,
            'title' => $property->title,
            'price' => $this->normalizeRawNumber($property->price),
            'discount_price' => $this->normalizeRawNumber($property->discount_price),
            'currency' => $property->currency,
            'address' => $property->address,
            'district' => $property->district,
            'instagram_link' => $property->instagram_link,
            'location' => $property->location ? [
                'id' => (int) $property->location->id,
                'name' => $property->location->name,
            ] : null,
            'type' => $property->type?->slug ?? $property->type?->name,
            'status' => $property->status?->slug ?? $property->status?->name,
            'tags' => $property->relationLoaded('tags')
                ? $property->tags->map(fn ($tag) => [
                    'id' => (int) $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'color' => $tag->color,
                ])->values()
                : [],
            'photos' => $property->photos
                ->map(fn ($photo) => [
                    'id' => (int) $photo->id,
                    'url' => $photo->file_path ? asset('storage/'.ltrim($photo->file_path, '/')) : null,
                ])
                ->values(),
            'creator' => $property->creator ? [
                'id' => (int) $property->creator->id,
                'name' => $property->creator->name,
                'phone' => $property->creator->phone,
            ] : null,
            'created_at' => $property->created_at?->toJSON(),
            'listing_updated_at' => $property->listing_updated_at?->toJSON(),
            'public_price_badge' => $property->publicPriceBadge(),
        ];
    }

    // ==== Карта: bbox + zoom + кластеризация/точки ====
    /**
     * GET /api/properties/map
     *
     * @queryParam document_type_ids array Multiple property document type IDs. Example: [1,3]
     * @queryParam document_type_id integer Single property document type ID. Example: 1
     * @queryParam construction_status string Filter by construction stage.
     * Allowed: under_construction, built, commissioned.
     * Example: built
     */
    public function map(Request $request)
    {
        $this->validateListFilters($request);

        // bbox: south,west,north,east
        $bboxRaw = $request->query('bbox', '');
        $parts = array_map('trim', explode(',', $bboxRaw));
        if (count($parts) !== 4) {
            return response()->json(['error' => 'Invalid bbox. Expected south,west,north,east'], 400);
        }
        [$south, $west, $north, $east] = array_map('floatval', $parts);

        // Нормализация (на случай перепутанных значений)
        if ($south > $north) {
            [$south, $north] = [$north, $south];
        }
        if ($west > $east) {
            [$west, $east] = [$east, $west];
        }

        $zoom = (int) $request->query('zoom', 12);
        $zoom = max(1, min(22, $zoom));

        // Always query current visibility, including moderation blockers and promotion expiry.
        return (function () use ($request, $south, $west, $north, $east, $zoom) {
            $query = $this->baseQuery($request);

            // Ограничение по bbox (полям latitude/longitude)
            $query->whereBetween('latitude', [$south, $north])
                ->whereBetween('longitude', [$west, $east]);

            // Применяем те же фильтры, что и в списке
            $this->applyFilters($query, $request);

            // Safety cap (не отдавать десятки тысяч)
            $limit = 5000;

            // Низкие зумы: грубая кластеризация "по сетке"
            if ($zoom <= 11) {
                $cell = 0.02; // шаг сетки ~2 км (подберите под город)
                $effectivePriceSql = $this->effectivePriceSql();
                $rows = $query
                    ->selectRaw("
                        FLOOR(latitude  / {$cell}) as gx,
                        FLOOR(longitude / {$cell}) as gy,
                        COUNT(*) as cnt,
                        MIN({$effectivePriceSql}) as min_price,
                        COUNT(DISTINCT currency) as currency_count,
                        MIN(currency) as currency_single,
                        AVG(latitude)  as lat_avg,
                        AVG(longitude) as lng_avg
                    ")
                    ->groupBy('gx', 'gy')
                    ->limit($limit)
                    ->get();

                $features = $rows->map(function ($r) {
                    $minPrice = $this->normalizeRawNumber($r->min_price);
                    $currency = ((int) $r->currency_count === 1) ? ($r->currency_single ?: null) : null;
                    $priceFromLabel = $minPrice !== null ? 'от '.$this->formatCompactPrice($minPrice) : null;

                    return [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            // ВНИМАНИЕ: проверь порядок в вашей карте. Для Yandex чаще [lat, lng]
                            'coordinates' => [(float) $r->lat_avg, (float) $r->lng_avg],
                        ],
                        'properties' => [
                            'cluster' => true,
                            'point_count' => (int) $r->cnt,
                            'min_price' => $minPrice,
                            'currency' => $currency,
                            'price_from_label' => $priceFromLabel,
                        ],
                    ];
                })->values();

                return response()->json([
                    'type' => 'FeatureCollection',
                    'features' => $features,
                ]);
            }

            // Высокие зумы: отдаём точки
            $points = $query
                ->select(['id', 'title', 'price', 'discount_price', 'currency', 'latitude', 'longitude'])
                ->limit($limit)
                ->get();

            $features = $points->map(function ($p) {
                $price = $this->normalizeRawNumber($p->price);
                $discountPrice = $this->normalizeRawNumber($p->discount_price);

                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $p->latitude, (float) $p->longitude],
                    ],
                    'properties' => [
                        'id' => (int) $p->id,
                        'title' => (string) $p->title,
                        'price' => $price,
                        'discount_price' => $discountPrice,
                        'currency' => $p->currency ?: null,
                        'price_label' => $price !== null ? $this->formatCompactPrice($price) : null,
                        'discount_price_label' => $discountPrice !== null ? $this->formatCompactPrice($discountPrice) : null,
                    ],
                ];
            })->values();

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);
        })();
    }

    private function normalizeRawNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        $numeric = (float) $value;
        if (fmod($numeric, 1.0) === 0.0) {
            return (int) $numeric;
        }

        return $numeric;
    }

    private function formatCompactPrice($price): ?string
    {
        if ($price === null || $price === '' || ! is_numeric($price)) {
            return null;
        }

        $value = (float) $price;
        $abs = abs($value);

        if ($abs >= 1000000000) {
            return $this->formatWithUnit($value / 1000000000, 'млрд');
        }

        if ($abs >= 1000000) {
            return $this->formatWithUnit($value / 1000000, 'млн');
        }

        if ($abs >= 1000) {
            return $this->formatWithUnit($value / 1000, 'к');
        }

        if (fmod($value, 1.0) === 0.0) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatWithUnit(float $value, string $unit): string
    {
        $rounded = round($value, 1);

        if (fmod($rounded, 1.0) === 0.0) {
            $formatted = (string) (int) $rounded;
        } else {
            $formatted = rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.');
        }

        if ($unit === 'к') {
            return "{$formatted}{$unit}";
        }

        return "{$formatted} {$unit}";
    }

    // ==== Единая фильтрация для списка и карты ====
    private function applyFilters(Builder $query, Request $request): void
    {
        $toArray = function ($value) {
            if ($value === null || $value === '') {
                return [];
            }
            if (is_array($value)) {
                return array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null));
            }

            return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
        };

        if ($this->supportsPropertyTags()) {
            $rawTags = $request->input('tag_ids', $request->input('tags'));
            $tagIds = array_values(array_unique(array_filter(array_map('intval', $toArray($rawTags)))));

            if (! empty($tagIds)) {
                $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->whereIn('tags.id', $tagIds));
            }
        }

        if ($request->filled('branch_id')) {
            $branchIds = array_values(array_filter(
                array_map('intval', $toArray($request->input('branch_id')))
            ));

            if (! empty($branchIds)) {
                $this->applyBranchIdFilter($query, $branchIds);
            }
        }

        if ($request->filled('branch_group_id')) {
            $branchGroupIds = array_values(array_filter(
                array_map('intval', $toArray($request->input('branch_group_id')))
            ));

            if (! empty($branchGroupIds)) {
                $this->applyBranchGroupFilter($query, $branchGroupIds);
            }
        }

        if ($request->filled('source_type') && Schema::hasColumn('properties', 'source_type')) {
            $sourceTypes = array_values(array_intersect(
                $toArray($request->input('source_type')),
                ['external_agent']
            ));

            if (! empty($sourceTypes)) {
                $query->whereIn('source_type', $sourceTypes);
            }
        }

        if ($request->filled('external_agent_id') && Schema::hasColumn('properties', 'external_agent_id')) {
            $externalAgentIds = array_values(array_filter(
                array_map('intval', $toArray($request->input('external_agent_id')))
            ));

            if (! empty($externalAgentIds)) {
                $query->whereIn('external_agent_id', $externalAgentIds);
            }
        }

        // Статусы (мульти)
        if ($request->filled('moderation_status')) {
            $available = ['pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented', 'sold_by_owner', 'denied', 'deposit'];
            $statuses = array_values(array_intersect($toArray($request->input('moderation_status')), $available));
            if (! empty($statuses)) {
                $query->whereIn('moderation_status', $statuses);
            }
        }

        // ---- districts (мультиселект) с похожестью ≥ 0.7 ----
        if ($request->has('districts')) {
            $selected = $toArray($request->input('districts'));
            $selected = array_values(array_filter($selected, fn ($v) => $v !== ''));

            if (! empty($selected)) {
                // 1) Грубая выборка кандидатов по LIKE (по первым 3 символам каждого значения)
                $coarse = Property::query()->select(['id', 'district']);

                $coarse->where(function ($q) use ($selected) {
                    foreach ($selected as $d) {
                        $needle = mb_strtolower(trim($d), 'UTF-8');
                        if ($needle === '') {
                            continue;
                        }
                        $prefix = mb_substr($needle, 0, 3, 'UTF-8'); // берём первые 3 символа
                        if ($prefix !== '') {
                            $q->orWhereRaw('LOWER(district) LIKE ?', ['%'.$prefix.'%']);
                        }
                    }
                });

                // можно сузить по другим фильтрам, если уже заданы (город, тип и т.п.)
                // но просто применим базовые ограничения ролей:
                // (важно: НЕ копируем все applyFilters, чтобы не задвоить; достаточно чернового ограничения)
                // Либо оставьте как есть.

                $candidates = $coarse->limit(5000)->get(); // safety cap

                // 2) Тонкая фильтрация (Jaccard по 3-граммам), порог 0.7
                $THRESHOLD = 0.70;
                $ids = [];

                foreach ($candidates as $row) {
                    $cand = (string) ($row->district ?? '');
                    foreach ($selected as $needle) {
                        if ($this->jaccard($cand, (string) $needle, 3) >= $THRESHOLD) {
                            $ids[] = (int) $row->id;
                            break;
                        }
                    }
                }

                // если нет совпадений — заведомо пустой результат
                if (empty($ids)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                // Применяем к основному запросу, чтобы пагинация и сортировки работали как обычно
                $query->whereIn('id', array_values(array_unique($ids)));
            }
        }

        // Текстовые поля (like, поддержка массива термов: OR)
        foreach (['title', 'description', 'district', 'address', 'landmark', 'condition', 'apartment_type', 'owner_phone'] as $field) {
            if ($request->has($field)) {
                $terms = $toArray($request->input($field));
                if (empty($terms)) {
                    $val = $request->input($field);
                    if ($val !== null && $val !== '') {
                        $query->where($field, 'like', '%'.$val.'%');
                    }
                } else {
                    $query->where(function ($q) use ($field, $terms) {
                        foreach ($terms as $t) {
                            $q->orWhere($field, 'like', '%'.$t.'%');
                        }
                    });
                }
            }
        }

        // Точные поля (включая мультиселект через whereIn)
        // Aliases are kept here so the public list, map, and count endpoints
        // always apply an identical filter set.
        $fieldAliases = [
            'type_id' => ['propertyTypes', 'propertyType'],
            'location_id' => ['city'],
            'repair_type_id' => ['repairs'],
            'document_type_id' => ['document_type_ids'],
        ];
        $exactFields = [
            'type_id', 'status_id', 'location_id', 'repair_type_id',
            'currency', 'offer_type',
            'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
            'agent_id', 'listing_type', 'created_by', 'contract_type_id', 'document_type_id',
            'is_business_owner', 'is_full_apartment', 'is_for_aura', 'developer_id',
            'heating_type_id', 'parking_type_id', 'construction_status',
            // при желании можно и lat/lng, но для карты они задаются bbox'ом
        ];
        foreach ($exactFields as $field) {
            $filterInput = $request->input($field);
            $hasFilter = $request->has($field);

            if ($field === 'document_type_id' && $request->has('document_type_ids')) {
                $hasFilter = true;
                $filterInput = $request->input('document_type_ids');
            } elseif (! $hasFilter) {
                foreach ($fieldAliases[$field] ?? [] as $alias) {
                    if ($request->has($alias)) {
                        $hasFilter = true;
                        $filterInput = $request->input($alias);
                        break;
                    }
                }
            }

            if ($hasFilter) {
                if ($field === 'listing_type' && Schema::hasTable('property_promotions')) {
                    $types = array_values(array_intersect($toArray($filterInput), ['regular', 'vip', 'urgent']));
                    if ($types === [] && is_string($filterInput) && in_array($filterInput, ['regular', 'vip', 'urgent'], true)) {
                        $types = [$filterInput];
                    }
                    $query->where(function (Builder $promotionQuery) use ($types): void {
                        if (in_array('regular', $types, true)) {
                            $promotionQuery->orWhereDoesntHave('promotions', fn (Builder $promotions) => $promotions->currentlyActive());
                        }
                        foreach (array_intersect($types, ['vip', 'urgent']) as $type) {
                            $promotionQuery->orWhereHas('promotions', fn (Builder $promotions) => $promotions->currentlyActive()->where('type', $type));
                        }
                    });

                    continue;
                }

                // normalize boolean-like params: support true/false (bool), 'true'/'false' (strings), and '1'/'0'
                $booleanFields = [
                    'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
                    'is_business_owner', 'is_full_apartment', 'is_for_aura',
                ];

                if (in_array($field, $booleanFields, true)) {
                    $raw = $filterInput;
                    if ($raw === null || $raw === '') {
                        continue; // nothing to apply
                    }

                    $vals = [];
                    if (is_array($raw)) {
                        foreach ($raw as $v) {
                            if ($v === true || $v === 'true' || $v === '1' || $v === 1) {
                                $vals[] = '1';
                            } elseif ($v === false || $v === 'false' || $v === '0' || $v === 0) {
                                $vals[] = '0';
                            }
                        }
                    } else {
                        $v = $raw;
                        if ($v === true || $v === 'true' || $v === '1' || $v === 1) {
                            $vals = ['1'];
                        } elseif ($v === false || $v === 'false' || $v === '0' || $v === 0) {
                            $vals = ['0'];
                        } else {
                            $vals = [$v];
                        }
                    }

                    $vals = array_values(array_unique(array_filter($vals, fn ($x) => $x !== '')));
                    if (! empty($vals)) {
                        $query->whereIn($field, $vals);
                    }

                    continue;
                }

                $vals = $toArray($filterInput);
                if (empty($vals)) {
                    $val = $filterInput;
                    if ($val !== null && $val !== '') {
                        $query->where($field, $val);
                    }
                } else {
                    $query->whereIn($field, $vals);
                }
            }
        }

        if (
            ($request->filled('priceFrom') || $request->filled('priceTo'))
            && ! $request->filled('currency')
        ) {
            $query->where('currency', 'TJS');
        }

        // Алиасы
        $aliases = ['area' => 'total_area'];

        // Диапазоны
        foreach ([
            'rooms' => 'rooms',
            'total_area' => 'total_area',
            'living_area' => 'living_area',
            'floor' => 'floor',
            'total_floors' => 'total_floors',
            'year_built' => 'year_built',
            'area' => $aliases['area'],
        ] as $param => $column) {
            $from = $request->input($param.'From');
            $to = $request->input($param.'To');
            if ($from !== null && $from !== '') {
                $query->where($column, '>=', $from);
            }
            if ($to !== null && $to !== '') {
                $query->where($column, '<=', $to);
            }
        }

        $this->applyEffectivePriceRange(
            $query,
            $request->input('priceFrom'),
            $request->input('priceTo')
        );

        // Диапазон по датам (date_from, date_to) — фильтрация по created_at.
        // Формат ожидается YYYY-MM-DD или любой распознаваемый Carbon формát.
        if ($request->has('date_from') || $request->has('date_to')) {
            $from = $request->input('date_from');
            $to = $request->input('date_to');

            try {
                if (! empty($from)) {
                    $query->whereDate('created_at', '>=', \Carbon\Carbon::parse($from)->toDateString());
                }
            } catch (\Exception $e) {
                // При желании логировать ошибку или игнорировать неверный формат
            }

            try {
                if (! empty($to)) {
                    $query->whereDate('created_at', '<=', \Carbon\Carbon::parse($to)->toDateString());
                }
            } catch (\Exception $e) {
                // При желании логировать ошибку или игнорировать неверный формат
            }
        }

        // Диапазон по датам продажи (sold_at_from, sold_at_to) — фильтрация по sold_at
        // Применяется только к закрытым статусам
        if ($request->has('sold_at_from') || $request->has('sold_at_to')) {
            $soldFrom = $request->input('sold_at_from');
            $soldTo = $request->input('sold_at_to');

            // sold_at имеет смысл только для закрытых объявлений
            //            $query->whereIn('moderation_status', ['sold', 'rented', 'sold_by_owner']);

            try {
                if (! empty($soldFrom)) {
                    $query->whereDate('sold_at', '>=', \Carbon\Carbon::parse($soldFrom)->toDateString());
                }
            } catch (\Exception $e) {
                // можно логировать при необходимости
            }

            try {
                if (! empty($soldTo)) {
                    $query->whereDate('sold_at', '<=', \Carbon\Carbon::parse($soldTo)->toDateString());
                }
            } catch (\Exception $e) {
                // можно логировать при необходимости
            }
        }
    }

    /**
     * Filter by the customer-facing price: discount_price when present,
     * otherwise the regular price.
     *
     * Production uses the indexed generated effective_price column. The
     * fallback keeps isolated tests and rolling deployments compatible until
     * the migration has been applied everywhere.
     */
    private function applyEffectivePriceRange(Builder $query, mixed $from, mixed $to): void
    {
        $hasFrom = $from !== null && $from !== '';
        $hasTo = $to !== null && $to !== '';

        if (! $hasFrom && ! $hasTo) {
            return;
        }

        if (Schema::hasColumn('properties', 'effective_price')) {
            if ($hasFrom) {
                $query->where('effective_price', '>=', $from);
            }
            if ($hasTo) {
                $query->where('effective_price', '<=', $to);
            }

            return;
        }

        if (! Schema::hasColumn('properties', 'discount_price')) {
            if ($hasFrom) {
                $query->where('price', '>=', $from);
            }
            if ($hasTo) {
                $query->where('price', '<=', $to);
            }

            return;
        }

        $query->where(function (Builder $effectivePriceQuery) use ($from, $to, $hasFrom, $hasTo) {
            $effectivePriceQuery
                ->where(function (Builder $discountedQuery) use ($from, $to, $hasFrom, $hasTo) {
                    $discountedQuery->where('discount_price', '>', 0);
                    if ($hasFrom) {
                        $discountedQuery->where('discount_price', '>=', $from);
                    }
                    if ($hasTo) {
                        $discountedQuery->where('discount_price', '<=', $to);
                    }
                })
                ->orWhere(function (Builder $regularQuery) use ($from, $to, $hasFrom, $hasTo) {
                    $regularQuery->where(function (Builder $noDiscountQuery) {
                        $noDiscountQuery
                            ->whereNull('discount_price')
                            ->orWhere('discount_price', '<=', 0);
                    });
                    if ($hasFrom) {
                        $regularQuery->where('price', '>=', $from);
                    }
                    if ($hasTo) {
                        $regularQuery->where('price', '<=', $to);
                    }
                });
        });
    }

    private function effectivePriceSql(?string $table = null): string
    {
        $prefix = $table ? $table.'.' : '';

        if (Schema::hasColumn('properties', 'effective_price')) {
            return $prefix.'effective_price';
        }

        if (Schema::hasColumn('properties', 'discount_price')) {
            return "COALESCE(NULLIF({$prefix}discount_price, 0), {$prefix}price)";
        }

        return $prefix.'price';
    }

    /**
     * Query validation for list/map property filters.
     * Returns 422 for unsupported construction_status values.
     *
     * @queryParam construction_status string Filter by construction stage.
     * @queryParam document_type_ids array Multiple property document type IDs. Example: [1,3]
     * @queryParam document_type_id integer Single property document type ID. Example: 1
     * Allowed: under_construction, built, commissioned.
     * Example: built
     */
    private function validateListFilters(Request $request): void
    {
        $validated = $request->validate([
            'construction_status' => ['sometimes', 'nullable', Rule::in(['under_construction', 'built', 'commissioned'])],
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'document_type_ids' => ['sometimes', 'nullable', 'array'],
            'document_type_ids.*' => ['integer', 'distinct', 'exists:document_types,id'],
            'priceFrom' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priceTo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'roomsFrom' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'roomsTo' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'total_areaFrom' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_areaTo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'living_areaFrom' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'living_areaTo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'areaFrom' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'areaTo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'floorFrom' => ['sometimes', 'nullable', 'integer'],
            'floorTo' => ['sometimes', 'nullable', 'integer'],
            'total_floorsFrom' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_floorsTo' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'year_builtFrom' => ['sometimes', 'nullable', 'integer', 'min:1800'],
            'year_builtTo' => ['sometimes', 'nullable', 'integer', 'min:1800'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'sold_at_from' => ['sometimes', 'nullable', 'date'],
            'sold_at_to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', Rule::in(['none', 'listing_type', 'created_at', 'listing_updated_at', 'date', 'price', 'total_area', 'area', 'rooms', 'views_count', 'id'])],
            'dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'compact' => ['sometimes', 'boolean'],
        ], [
            'construction_status.in' => 'Поле construction_status должно быть одним из значений: under_construction, built, commissioned.',
        ]);

        $this->validateRangeOrder($validated, [
            ['priceFrom', 'priceTo'],
            ['roomsFrom', 'roomsTo'],
            ['total_areaFrom', 'total_areaTo'],
            ['living_areaFrom', 'living_areaTo'],
            ['areaFrom', 'areaTo'],
            ['floorFrom', 'floorTo'],
            ['total_floorsFrom', 'total_floorsTo'],
            ['year_builtFrom', 'year_builtTo'],
            ['date_from', 'date_to'],
            ['sold_at_from', 'sold_at_to'],
        ]);

        foreach ([
            'moderation_status' => ['pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented', 'sold_by_owner', 'denied', 'deposit'],
            'source_type' => ['external_agent'],
        ] as $field => $allowed) {
            if (! $request->filled($field)) {
                continue;
            }

            $values = is_array($request->input($field))
                ? $request->input($field)
                : explode(',', (string) $request->input($field));
            $invalid = array_diff(array_map('trim', $values), $allowed);

            if ($invalid !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $field => 'Передано недопустимое значение фильтра.',
                ]);
            }
        }
    }

    private function validateRangeOrder(array $values, array $ranges): void
    {
        foreach ($ranges as [$fromKey, $toKey]) {
            if (
                ! array_key_exists($fromKey, $values)
                || ! array_key_exists($toKey, $values)
                || $values[$fromKey] === null
                || $values[$toKey] === null
            ) {
                continue;
            }

            $fromValue = $values[$fromKey];
            $toValue = $values[$toKey];

            if (str_contains($fromKey, 'date') || str_contains($fromKey, 'sold_at')) {
                $fromValue = \Carbon\Carbon::parse($fromValue)->getTimestamp();
                $toValue = \Carbon\Carbon::parse($toValue)->getTimestamp();
            }

            if ($fromValue > $toValue) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $toKey => "Поле {$toKey} должно быть больше или равно {$fromKey}.",
                ]);
            }
        }
    }

    private function applyBranchGroupFilter(Builder $query, array $branchGroupIds): void
    {
        $branchGroupIds = array_values(array_filter(array_map('intval', $branchGroupIds)));

        if (empty($branchGroupIds) || ! Schema::hasColumn('users', 'branch_group_id')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $branchGroupQuery) use ($branchGroupIds) {
            $hasPropertyBranchGroupId = Schema::hasColumn('properties', 'branch_group_id');

            if ($hasPropertyBranchGroupId) {
                $branchGroupQuery->whereIn('branch_group_id', $branchGroupIds);
            }

            $branchGroupQuery->orWhere(function (Builder $agentQuery) use ($branchGroupIds, $hasPropertyBranchGroupId) {
                if ($hasPropertyBranchGroupId) {
                    $agentQuery->whereNull('branch_group_id');
                }

                $agentQuery
                    ->whereNotNull('agent_id')
                    ->whereIn('agent_id', User::query()
                        ->whereIn('branch_group_id', $branchGroupIds)
                        ->select('id'));
            });

            $branchGroupQuery->orWhere(function (Builder $creatorQuery) use ($branchGroupIds, $hasPropertyBranchGroupId) {
                if ($hasPropertyBranchGroupId) {
                    $creatorQuery->whereNull('branch_group_id');
                }

                $creatorQuery
                    ->whereNotNull('created_by')
                    ->where(function (Builder $agentFallbackQuery) {
                        $agentFallbackQuery
                            ->whereNull('agent_id')
                            ->orWhereNotIn('agent_id', User::query()
                                ->whereNotNull('branch_group_id')
                                ->select('id'));
                    })
                    ->whereIn('created_by', User::query()
                        ->whereIn('branch_group_id', $branchGroupIds)
                        ->select('id'));
            });
        });
    }

    private function applyBranchIdFilter(Builder $query, array $branchIds): void
    {
        $branchIds = array_values(array_filter(array_map('intval', $branchIds)));

        if (empty($branchIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $hasPropertyBranchId = Schema::hasColumn('properties', 'branch_id');
        $hasUsersBranchId = Schema::hasColumn('users', 'branch_id');

        if (! $hasPropertyBranchId && ! $hasUsersBranchId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $branchQuery) use ($branchIds, $hasPropertyBranchId, $hasUsersBranchId) {
            if ($hasPropertyBranchId) {
                $branchQuery->whereIn('branch_id', $branchIds);
            }

            if (! $hasUsersBranchId) {
                return;
            }

            $branchQuery->orWhere(function (Builder $agentQuery) use ($branchIds, $hasPropertyBranchId) {
                if ($hasPropertyBranchId) {
                    $agentQuery->whereNull('branch_id');
                }

                $agentQuery
                    ->whereNotNull('agent_id')
                    ->whereIn('agent_id', User::query()
                        ->whereIn('branch_id', $branchIds)
                        ->select('id'));
            });

            $branchQuery->orWhere(function (Builder $creatorQuery) use ($branchIds, $hasPropertyBranchId) {
                if ($hasPropertyBranchId) {
                    $creatorQuery->whereNull('branch_id');
                }

                $creatorQuery
                    ->whereNotNull('created_by')
                    ->where(function (Builder $agentFallbackQuery) {
                        $agentFallbackQuery
                            ->whereNull('agent_id')
                            ->orWhereNotIn('agent_id', User::query()
                                ->whereNotNull('branch_id')
                                ->select('id'));
                    })
                    ->whereIn('created_by', User::query()
                        ->whereIn('branch_id', $branchIds)
                        ->select('id'));
            });
        });
    }

    public function store(Request $request)
    {
        $user = $this->crmAuthUser();
        $this->moderation->assertCanCreate($user);
        $this->moderation->assertNoProtectedFields($request, ['branch_id', 'branch_group_id'], $user);

        $promotionInput = $request->validate(['requested_listing_type' => 'nullable|in:regular,vip,urgent']);
        $requestedType = $promotionInput['requested_listing_type'] ?? 'regular';
        $requiresReview = $requestedType !== 'regular';
        $validated = $this->validateProperty($request);
        $featureIds = $validated['features'] ?? [];
        $tagIds = $validated['tags'] ?? [];
        unset($validated['features']);
        unset($validated['tags']);
        $validated = $this->normalizeMopBranchGroupPayload($user, $validated);
        $this->ensureVisibleClientsForProperty($validated);
        $validated = $this->syncPropertyClientSnapshots($validated);

        $dups = $this->propertyDuplicateService->find($validated);
        $qualityWarnings = $this->propertyQualityService->inspect($validated);
        $validated['created_by'] = $user->id;
        $validated = $this->moderation->creationState($validated, $dups, $qualityWarnings, $requiresReview);

        $property = DB::transaction(function () use ($request, $validated, $featureIds, $tagIds, $dups, $qualityWarnings, $user, $requiresReview, $requestedType) {
            $property = Property::create($validated);
            if ($this->supportsPropertyFeatures()) {
                $property->features()->sync($featureIds);
            }
            if ($this->supportsPropertyTags()) {
                $property->tags()->sync($tagIds);
            }
            $this->storePhotosFromRequest($request, $property);
            $this->moderation->recordCreation($property, $user, $dups, $qualityWarnings, $requiresReview);
            if ($requiresReview) {
                app(\App\Services\PropertyModeration\PropertyPromotionService::class)->request(
                    $property, $user, $requestedType, 'Тип выбран при добавлении объявления',
                    (int) config('property-moderation.promotion_default_days', 7), (int) $property->moderation_version,
                );
            }

            return $property;
        });

        $fresh = $property->fresh($this->propertyMutationRelations());
        $fresh->setAttribute('duplicate_candidates', $dups->take(10)->values());
        $fresh->setAttribute('quality_warnings', $qualityWarnings);

        return response()->json($this->withModerationWorkflow($fresh, $user));
    }

    public function duplicateCandidates(Request $request, Property $property)
    {
        $this->authorizePropertyMutation($property);

        $data = $property->getAttributes();

        return response()->json([
            'property_id' => (int) $property->id,
            'duplicates' => $this->propertyDuplicateService->find($data, (int) $property->id),
            'cases' => Schema::hasTable('property_moderation_cases')
                ? $property->moderationCases()->with(['submitter.role', 'duplicateCandidates.candidateProperty.photos'])->latest()->get()
                : [],
            'quality_warnings' => $this->propertyQualityService->inspect($data),
        ]);
    }

    public function update(Request $request, Property $property)
    {
        $user = $this->authorizePropertyMutation($property);
        $this->moderation->assertNoProtectedFields($request, [], $user, $property);

        $validated = $this->validateProperty($request, isUpdate: true, property: $property);
        $shouldSyncFeatures = array_key_exists('features', $validated);
        $featureIds = $validated['features'] ?? [];
        $shouldSyncTags = array_key_exists('tags', $validated);
        $tagIds = $validated['tags'] ?? [];
        unset($validated['features']);
        unset($validated['tags']);
        $validated = $this->normalizeMopBranchGroupPayload($user, $validated);
        $this->ensureVisibleClientsForProperty($validated, $property);
        $validated = $this->syncPropertyClientSnapshots($validated);

        DB::transaction(function () use (
            $request,
            $property,
            $validated,
            $shouldSyncFeatures,
            $featureIds,
            $shouldSyncTags,
            $tagIds,
            $user
        ): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $this->moderation->assertMutationVersion($request, $property);
            abort_unless($this->moderationAccess->canEdit($user, $property), 403);
            $property->fill($validated);
            $listingContentChanged = $property->isDirty(Property::LISTING_CONTENT_FIELDS);
            $relationsChanged = false;
            $beforePhotos = $this->moderation->photoSnapshot($property);
            $qualityWarnings = $this->propertyQualityService->inspect($property->getAttributes());
            $outcome = $this->moderation->evaluateUpdate($property, $user, $qualityWarnings);
            $property->save();

            if ($shouldSyncFeatures && $this->supportsPropertyFeatures()) {
                $syncChanges = $property->features()->sync($featureIds);
                $featuresChanged = $this->relationSyncChanged($syncChanges);
                $relationsChanged = $relationsChanged || $featuresChanged;
                $listingContentChanged = $listingContentChanged || $featuresChanged;
            }

            if ($shouldSyncTags && $this->supportsPropertyTags()) {
                $syncChanges = $property->tags()->sync($tagIds);
                $tagsChanged = $this->relationSyncChanged($syncChanges);
                $relationsChanged = $relationsChanged || $tagsChanged;
                $listingContentChanged = $listingContentChanged || $tagsChanged;
            }

            // Optional: allow adding more photos on update
            $mediaChanged = $this->storePhotosFromRequest($request, $property, append: true);
            $listingContentChanged = $mediaChanged || $listingContentChanged;

            // Optional: reorder via `photo_order` = [photoId1, photoId2, ...]
            if ($request->filled('photo_order') && is_array($request->photo_order)) {
                $reordered = $this->applyOrder($property, $request->photo_order);
                $mediaChanged = $reordered || $mediaChanged;
                $listingContentChanged = $reordered || $listingContentChanged;
            }

            $this->moderation->recordUpdateOutcome($property, $user, $outcome);
            if ($mediaChanged || $relationsChanged) {
                $this->moderation->handleMediaMutation($property, $user, [
                    'action' => 'listing_media_or_relations_changed',
                    'before_photos' => $beforePhotos,
                    'photos_changed' => $mediaChanged,
                    'relations_changed' => $relationsChanged,
                ]);
            }

            if ($listingContentChanged) {
                $property->markListingUpdated();
            }
        });

        return response()->json($this->withModerationWorkflow(
            $property->fresh($this->propertyMutationRelations()),
            $user,
        ));
    }

    public function updateCoOwner(Request $request, Property $property)
    {
        $validated = $request->validate([
            'co_owner_user_id' => 'nullable|integer|exists:users,id',
            'reason' => 'required|string|min:5|max:2000',
            'version' => 'required|integer|min:0',
        ]);
        $updated = $this->moderation->transfer(
            $property,
            $request->user(),
            ['co_owner_user_id' => $validated['co_owner_user_id'] ?? null],
            $validated['reason'],
            $validated['version'],
        );

        return response()->json($updated->load(['coOwner.role', 'creator.role', 'agent.role']));
    }

    private function relationSyncChanged(array $changes): bool
    {
        return ! empty($changes['attached'])
            || ! empty($changes['detached'])
            || ! empty($changes['updated']);
    }

    private function storePhotosFromRequest(Request $request, Property $property, bool $append = false): bool
    {
        $changed = false;
        $preserveDeletedFiles = (array) $property->approved_content_snapshot !== [];

        // Delete selected photos if requested
        if ($request->filled('delete_photo_ids')) {
            foreach ($property->photos()->whereIn('id', $request->delete_photo_ids)->get() as $old) {
                if (! $preserveDeletedFiles) {
                    \Storage::disk('public')->delete($old->file_path);
                }
                $old->delete();
                $changed = true;
            }
        }

        if (! $request->hasFile('photos')) {
            return $changed;
        }

        // Determine base position (append to the end)
        $basePos = $append ? (int) ($property->photos()->max('position') ?? -1) + 1 : 0;

        $files = $request->file('photos');
        $positions = $request->input('photo_positions', []); // optional parallel array

        foreach (array_values($files) as $i => $photo) {
            $image = $this->imageManager->read($photo)->scaleDown(1600, null);
            $watermark = $this->imageManager->read(public_path('watermark/logo.png'))
                ->scale((int) round($image->width() * 0.14));
            $image->place($watermark, 'bottom-right', 36, 28);

            $binary = $image->encode(new JpegEncoder(50));
            $filename = 'properties/'.uniqid('', true).'.jpg';
            \Storage::disk('public')->put($filename, $binary);

            $position = $positions[$i] ?? ($basePos + $i);

            $property->photos()->create([
                'file_path' => $filename,
                'position' => $position,
            ]);
            $changed = true;
        }

        // Normalize positions to be 0..N-1 with no gaps
        return $this->normalizePositions($property) || $changed;
    }

    private function applyOrder(Property $property, array $orderedIds): bool
    {
        $changed = false;

        foreach ($orderedIds as $pos => $id) {
            $photo = $property->photos()->whereKey($id)->first();

            if ($photo && (int) $photo->position !== $pos) {
                $photo->update(['position' => $pos]);
                $changed = true;
            }
        }

        return $this->normalizePositions($property) || $changed;
    }

    private function normalizePositions(Property $property): bool
    {
        $changed = false;
        $photos = $property->photos()->orderBy('position')->orderBy('id')->get();
        foreach ($photos as $idx => $p) {
            if ((int) $p->position !== $idx) {
                $p->update(['position' => $idx]);
                $changed = true;
            }
        }

        return $changed;
    }

    public function show(Request $request, Property $property)
    {
        $authUser = $this->propertyShowAuthUser($request);
        $this->moderation->publicOrFail($property, $authUser);

        $property->load($this->propertyDetailRelations());
        if ($authUser && Schema::hasTable('property_moderation_cases') && (
            $this->moderationAccess->canEdit($authUser, $property)
            || $this->moderationAccess->canModerate($authUser, $property)
        )) {
            $property->load(['moderationCases.duplicateCandidates.candidateProperty.photos', 'promotions']);
        }

        if (Schema::hasTable('reels')) {
            $property->load([
                'reels' => fn ($query) => $query->published()->ordered(),
            ]);
        }

        return response()->json(
            $this->serializePropertyShow($property, $authUser)
        );
    }

    public function refreshListingDate(Property $property)
    {
        $actor = $this->authorizePropertyMutation($property);

        return DB::transaction(function () use ($property, $actor) {
            /** @var Property $lockedProperty */
            $lockedProperty = Property::query()
                ->whereKey($property->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProperty->moderation_status !== Property::PUBLIC_MODERATION_STATUS) {
                return response()->json([
                    'message' => 'Для этого статуса обновление даты недоступно',
                    'code' => 'LISTING_DATE_REFRESH_STATUS_NOT_ALLOWED',
                ], 422);
            }

            $refreshState = $this->listingDateRefreshState($lockedProperty);
            if (! $refreshState['available']) {
                return response()->json([
                    'message' => 'Дата объявления недавно обновлялась',
                    'code' => 'LISTING_DATE_REFRESH_COOLDOWN',
                    'next_available_at' => $refreshState['next_available_at'],
                    'listing_date_refresh' => $refreshState,
                ], 409);
            }

            $createdAtBefore = $lockedProperty->getRawOriginal('created_at');
            $oldListingUpdatedAt = $lockedProperty->listing_updated_at?->copy();
            $newListingUpdatedAt = now();

            if (! $lockedProperty->markListingUpdated($newListingUpdatedAt)) {
                throw new \RuntimeException('Не удалось обновить дату объявления.');
            }

            if ((string) $lockedProperty->getRawOriginal('created_at') !== (string) $createdAtBefore) {
                throw new \LogicException('Дата создания объявления была неожиданно изменена.');
            }

            PropertyLog::create([
                'property_id' => $lockedProperty->id,
                'user_id' => $actor->id,
                'action' => 'listing_date_refreshed',
                'changes' => [
                    'listing_updated_at' => [
                        'old' => $oldListingUpdatedAt?->toJSON(),
                        'new' => $lockedProperty->listing_updated_at?->toJSON(),
                    ],
                ],
            ]);

            $nextState = $this->listingDateRefreshState($lockedProperty);

            return response()->json([
                'message' => 'Дата объявления обновлена',
                'data' => [
                    'id' => (int) $lockedProperty->id,
                    'created_at' => $lockedProperty->created_at?->toJSON(),
                    'listing_updated_at' => $lockedProperty->listing_updated_at?->toJSON(),
                    'capabilities' => $this->propertyCapabilities($actor, $lockedProperty, $nextState),
                    'listing_date_refresh' => $nextState,
                ],
            ]);
        }, 3);
    }

    public function matchingClients(Request $request, Property $property)
    {
        $authUser = $this->crmAuthUser();
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        return response()->json([
            'property' => [
                'id' => $property->id,
                'title' => $property->title,
                'price' => $property->price,
                'currency' => $property->currency,
                'offer_type' => $property->offer_type,
                'district' => $property->district,
                'rooms' => $property->rooms,
                'total_area' => $property->total_area,
                'moderation_status' => $property->moderation_status,
            ],
            'matches' => $this->clientPropertyMatcher->forProperty(
                $authUser,
                $property,
                (int) ($validated['limit'] ?? 10)
            ),
        ]);
    }

    public function destroy(Property $property)
    {
        $actor = $this->authorizePropertyMutation($property);

        if (Schema::hasColumn('properties', 'publication_status')) {
            $this->moderation->withdrawListing($property, $actor, PropertyModerationService::PUBLICATION_ARCHIVED);
        } else {
            $property->update(['moderation_status' => 'deleted']);
        }

        if (Schema::hasTable('reels')) {
            $property->reels()->update([
                'status' => \App\Models\Reel::STATUS_ARCHIVED,
                'published_at' => null,
            ]);
        }

        return response()->json(['message' => 'Объект помечен как удалён']);
    }

    //    public function updateModerationAndListingType(Request $request, Property $property)
    //    {
    //        $user = auth()->user();
    //
    //        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('agent'))) {
    //            return response()->json(['message' => 'Доступ запрещён'], 403);
    //        }
    //
    //        $validated = $request->validate([
    //            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented,sold_by_owner,denied',
    //            'listing_type' => 'sometimes|in:regular,vip,urgent',
    //            'status_comment' => 'nullable|string',
    //        ]);
    //
    //        if (
    //            isset($validated['moderation_status']) &&
    //            in_array($validated['moderation_status'], ['sold', 'rented', 'sold_by_owner'], true)
    //        ) {
    //            $validated['sold_at'] = now();
    //        }
    //
    //        $property->update($validated);
    //
    //        return response()->json([
    //            'message' => 'Обновлено успешно',
    //            'data' => $property->only(['id', 'moderation_status', 'listing_type']),
    //        ]);
    //    }

    public function updateModerationAndListingType(Request $request, Property $property)
    {
        $actor = $this->authorizePropertyMutation($property);
        $this->moderation->assertNoProtectedFields($request, [], $actor, $property);
        return response()->json([
            'code' => 'MODERATION_ENDPOINT_RETIRED',
            'message' => 'Используйте отдельные действия модерации и endpoint /properties/{id}/deal.',
        ], 410);
    }

    /**
     * @return array
     */
    public function validateProperty(Request $request, bool $isUpdate = false, ?Property $property = null)
    {
        if (is_string($request->input('instagram_link'))) {
            $request->merge([
                'instagram_link' => trim($request->input('instagram_link')),
            ]);
        }

        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'district' => 'nullable|string',
            'address' => 'nullable|string',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'document_type_id' => 'nullable|exists:document_types,id',
            'type_id' => 'required|exists:property_types,id',
            'status_id' => 'nullable|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'price' => 'required|numeric',
            'discount_price' => 'nullable|numeric|gt:0',
            'currency' => 'required|in:TJS,USD',
            'offer_type' => 'required|in:rent,sale',
            'rooms' => 'nullable|integer|min:1|max:10',
            'youtube_link' => 'nullable|url',
            'instagram_link' => [
                'nullable',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                    $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                    $isInstagramHost = $host === 'instagram.com' || str_ends_with($host, '.instagram.com');

                    if ($scheme !== 'https' || ! $isInstagramHost) {
                        $fail('Поле Инстаграм должно содержать HTTPS-ссылку на instagram.com.');
                    }
                },
            ],
            'total_area' => 'nullable|numeric',
            'land_size' => 'sometimes|nullable|numeric|min:0|max:65535',
            'living_area' => 'nullable|numeric',
            'floor' => 'nullable|integer',
            'total_floors' => 'nullable|integer',
            'year_built' => 'nullable|integer|min:1900|max:'.date('Y'),
            'condition' => 'nullable|string',
            'construction_status' => 'nullable|in:under_construction,built,commissioned',
            'renovation_permission_status' => 'nullable|in:not_allowed,allowed',
            'apartment_type' => 'nullable|string',
            'has_garden' => 'sometimes|boolean',
            'has_parking' => 'sometimes|boolean',
            'is_mortgage_available' => 'sometimes|boolean',
            'is_from_developer' => 'sometimes|boolean',
            'landmark' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'owner_phone' => 'nullable|string|max:30',
            'owner_name' => 'nullable|string|max:255',
            'owner_client_id' => 'nullable|exists:clients,id',
            'object_key' => 'nullable|string|max:255',
            'features' => 'sometimes|nullable|array',
            'features.*' => 'integer|distinct|exists:features,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'integer|distinct|exists:tags,id',

            // Photos (optional on update)
            'photos' => [$isUpdate ? 'sometimes' : 'nullable', 'array', 'max:40'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['integer', 'min:0'],

            // Reorder existing
            'photo_order' => ['sometimes', 'array'],
            'photo_order.*' => ['integer', 'exists:property_photos,id'],

            // Delete list
            'delete_photo_ids' => ['sometimes', 'array'],
            'delete_photo_ids.*' => ['integer', 'exists:property_photos,id'],

            'developer_id' => 'nullable|exists:developers,id',
            'heating_type_id' => 'nullable|exists:heating_types,id',
            'parking_type_id' => 'nullable|exists:parking_types,id',
            'is_business_owner' => 'sometimes|boolean',
            'is_full_apartment' => 'sometimes|boolean',
            'is_for_aura' => 'sometimes|boolean',
        ]);

        $effectivePrice = array_key_exists('price', $validated)
            ? (float) $validated['price']
            : ($property ? (float) $property->price : null);

        if (
            array_key_exists('discount_price', $validated)
            && $validated['discount_price'] !== null
            && $effectivePrice !== null
            && (float) $validated['discount_price'] > $effectivePrice
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount_price' => 'Цена со скидкой не может быть выше основной цены.',
            ]);
        }

        return $validated;
    }

    public function applySorts(Builder $query, ?string $sort = 'listing_type', ?string $dir = 'desc'): void
    {
        // Если явно указано 'none' — не применяем сортировку
        if ($sort === 'none') {
            return;
        }

        // Нормализуем направление
        $dir = strtolower($dir ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Специальный порядок для listing_type (urgent -> vip -> regular -> others)
        if ($sort === 'listing_type') {
            $bindings = [];
            if (Schema::hasTable('property_promotions')) {
                $activeUrgent = "EXISTS (SELECT 1 FROM property_promotions pp
                    WHERE pp.property_id = properties.id AND pp.status = 'active'
                    AND pp.starts_at <= ? AND pp.ends_at > ? AND pp.type = ?)";
                $activeVip = "EXISTS (SELECT 1 FROM property_promotions pp
                    WHERE pp.property_id = properties.id AND pp.status = 'active'
                    AND pp.starts_at <= ? AND pp.ends_at > ? AND pp.type = ?)";
                $orderExpr = "CASE WHEN {$activeUrgent} THEN 1 WHEN {$activeVip} THEN 2 ELSE 3 END";
                $bindings = [now(), now(), 'urgent', now(), now(), 'vip'];
            } else {
                $orderExpr = "CASE listing_type
                WHEN 'urgent' THEN 1
                WHEN 'vip' THEN 2
                WHEN 'regular' THEN 3
                ELSE 4 END";
            }
            // Сначала по listing_type согласно CASE, затем по дате (чтобы детерминировать порядок)
            $query->orderByRaw($orderExpr, $bindings)->orderBy('created_at', $dir);

            return;
        }

        if ($sort === 'price') {
            $query->orderByRaw($this->effectivePriceSql()." {$dir}");

            return;
        }

        if ($sort === 'listing_updated_at') {
            $column = Schema::hasColumn('properties', 'listing_updated_at')
                ? 'COALESCE(listing_updated_at, created_at)'
                : 'created_at';
            $query->orderByRaw("{$column} {$dir}");

            return;
        }

        // Разрешённые поля сортировки — whitelist для защиты от произвольных колонок
        $allowed = [
            'created_at' => 'created_at', // можно также принимать alias 'date'
            'date' => 'created_at',
            'total_area' => 'total_area',
            'area' => 'total_area',
            'rooms' => 'rooms',
            'views_count' => 'views_count',
            'id' => 'id',
        ];

        // Если передали что-то вроде 'price' или 'total_area' — применим
        if (isset($allowed[$sort])) {
            $col = $allowed[$sort];
            $query->orderBy($col, $dir);

            return;
        }

        // По умолчанию — сортируем по созданию (дата)
        $query->orderBy('created_at', $dir);
    }

    public function trackView(Request $request, Property $property)
    {
        $this->moderation->publicOrFail($property, $this->propertyShowAuthUser($request));
        // Ключ "видел" = по объекту + IP + UA + текущая дата
        $fingerprint = sha1(
            ($request->ip() ?? '0.0.0.0').'|'.
            (string) $request->userAgent().'|'.
            now()->format('Y-m-d')
        );
        $cacheKey = "prop:{$property->id}:viewed:{$fingerprint}";

        // Инкрементим только если ещё не считали сегодня
        if (! Cache::has($cacheKey)) {
            $property->increment('views_count'); // атомарно
            Cache::put($cacheKey, 1, now()->addDay());
        }

        return response()->noContent();
    }

    /** Подготовка строки: нижний регистр, схлопнуть пробелы */
    private function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s ?? '';
    }

    /** 3-граммы для мультибайта */
    private function ngrams(string $s, int $n = 3): array
    {
        $len = mb_strlen($s, 'UTF-8');
        if ($len === 0) {
            return [];
        }
        if ($len < $n) {
            return [$s];
        } // короткие строки целиком

        $grams = [];
        for ($i = 0; $i <= $len - $n; $i++) {
            $grams[] = mb_substr($s, $i, $n, 'UTF-8');
        }

        return $grams;
    }

    /** Jaccard-похожесть по n-граммам (0..1) */
    private function jaccard(string $a, string $b, int $n = 3): float
    {
        $a = $this->norm($a);
        $b = $this->norm($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $A = array_unique($this->ngrams($a, $n));
        $B = array_unique($this->ngrams($b, $n));

        if (empty($A) && empty($B)) {
            return 1.0;
        }
        if (empty($A) || empty($B)) {
            return 0.0;
        }

        $Ai = array_fill_keys($A, true);
        $inter = 0;
        foreach ($B as $g) {
            if (isset($Ai[$g])) {
                $inter++;
            }
        }

        $union = count($A) + count($B) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }

    public function similar(Property $property, Request $request)
    {
        $this->moderation->publicOrFail($property, $this->propertyShowAuthUser($request));
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'price_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'radius_km' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'use_radius' => ['sometimes', 'boolean'],
        ]);
        $limit = (int) ($validated['limit'] ?? 6);
        $priceTolerance = (float) ($validated['price_tolerance'] ?? 0.2); // 20%
        $radiusKm = (float) ($validated['radius_km'] ?? 5); // 5 km by default
        $useRadius = $request->boolean('use_radius', true);

        $query = Property::query()->publicSearchable();

        // всегда исключаем текущий объект
        $query->where('id', '!=', $property->id);

        // совпадающий тип — даёт приоритет
        if ($property->type_id) {
            $query->where('type_id', $property->type_id);
        }

        // совпадающая локация (город / район) — если есть
        if ($property->location_id) {
            $query->where('location_id', $property->location_id);
        } elseif (! empty($property->district)) {
            $query->where('district', $property->district);
        }

        if ($property->developer_id) {
            $query->where('developer_id', $property->developer_id);
        }

        // совпадающий тип предложения (продажа/аренда)
        if (! empty($property->offer_type)) {
            $query->where('offer_type', $property->offer_type);
        }

        if (! empty($property->currency)) {
            $query->where('currency', $property->currency);
        }

        // комнаты — если указаны
        if (! empty($property->rooms)) {
            // ищем либо ровно такое значение, либо +-1 комнату
            $query->whereBetween('rooms', [max(0, $property->rooms - 1), $property->rooms + 1]);
        }

        // ценовой диапазон
        $sourcePrice = (float) (($property->discount_price > 0 ? $property->discount_price : null) ?? $property->price);
        if ($sourcePrice > 0) {
            $minPrice = $sourcePrice * (1 - $priceTolerance);
            $maxPrice = $sourcePrice * (1 + $priceTolerance);
            $this->applyEffectivePriceRange($query, $minPrice, $maxPrice);
        }

        // поиск по радиусу — если есть координаты и включена опция
        if ($useRadius && $property->latitude && $property->longitude) {
            $lat = (float) $property->latitude;
            $lng = (float) $property->longitude;
            // Хаверсин: расстояние в км
            $haversine = '(6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?))
            + sin(radians(?)) * sin(radians(latitude))
        ))';

            // присоединяем расстояние как поле и фильтруем по radius
            // используем selectRaw чтобы включить всё необходимое
            $query->select(['properties.*'])
                ->selectRaw("$haversine AS distance", [$lat, $lng, $lat])
                ->whereRaw("$haversine <= ?", [$lat, $lng, $lat, $radiusKm])
                ->orderBy('distance', 'asc');
        } else {
            // если расстояние не используется - сортируем по дате и релевантности
            $query->orderBy('created_at', 'desc');
        }

        // Добавим дополнительные нестрогие критерии (например, same repair type) как опция
        if ($property->repair_type_id) {
            $query->where('repair_type_id', $property->repair_type_id);
        }

        // eager load
        $result = $query->with(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'contractType', 'ownerClient.type', 'buyerClient.type'])
            ->limit($limit)
            ->get();

        return response()->json($result);
    }

    /**
     * Return audit logs for a property (paginated).
     * GET /api/properties/{property}/logs
     */
    public function logs(Request $request, Property $property)
    {
        $this->authorizePropertyMutation($property);
        $perPage = (int) $request->input('per_page', 50);

        $logs = $property->logs()->with('user')->paginate($perPage);

        return response()->json($logs);
    }

    public function saveDeal(
        SavePropertyDealRequest $request,
        Property $property
    ) {
        $user = $this->authorizePropertyDealMutation(
            $property,
            $request->deal_status,
            $request->filled('sale_user_id') ? (int) $request->input('sale_user_id') : null
        );
        $this->moderation->assertNoProtectedFields($request, $this->dealProtectedFieldExceptions(), $user, $property);
        $this->ensureDealAssignmentUsersInScope($user, $request->validated(), $property);

        $updated = DB::transaction(function () use ($request, $property): Property {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $user = $this->authorizePropertyDealMutation(
                $locked,
                $request->deal_status,
                $request->filled('sale_user_id') ? (int) $request->input('sale_user_id') : null
            );
            abort_if(
                Schema::hasColumn('properties', 'moderation_version')
                    && (int) $locked->moderation_version !== (int) $request->integer('version'),
                409,
                'MODERATION_VERSION_CONFLICT'
            );
            $this->ensureDealAssignmentUsersInScope($user, $request->validated(), $locked);
            $isDepositStatus = $request->deal_status === 'deposit';
            $isSaleStatus = in_array($request->deal_status, ['sold', 'sold_by_owner', 'rented'], true);
            $legacyStatus = match ($request->deal_status) {
                'client_denied' => 'denied',
                'available' => $locked->moderation_status,
                default => $request->deal_status,
            };
            $payload = [
                // Legacy field is server-owned compatibility output. The client
                // controls only deal_status through this endpoint.
                'moderation_status' => $legacyStatus,
                ...Schema::hasColumn('properties', 'deal_status')
                    ? ['deal_status' => $request->deal_status]
                    : [],
                ...Schema::hasColumn('properties', 'publication_status') && $isSaleStatus
                    ? ['publication_status' => PropertyModerationService::PUBLICATION_ARCHIVED]
                    : [],
                'sold_at' => $isSaleStatus
                    ? now()
                    : $locked->sold_at,
                'sale_user_id' => $request->input('sale_user_id')
                    ?? ($isSaleStatus ? $user->id : $locked->sale_user_id),
                ...Schema::hasColumn('properties', 'moderation_version')
                    ? ['moderation_version' => (int) $locked->moderation_version + 1]
                    : [],
            ];
            foreach ([
                'status_comment', 'buyer_client_id', 'buyer_full_name', 'buyer_phone',
                'actual_sale_price', 'actual_sale_currency', 'company_commission_amount',
                'company_commission_currency', 'money_holder', 'money_received_at',
                'contract_signed_at', 'deposit_amount', 'deposit_currency',
                'deposit_received_at', 'deposit_taken_at', 'planned_contract_signed_at',
                'company_expected_income', 'company_expected_income_currency',
            ] as $field) {
                if ($request->has($field)) {
                    $payload[$field] = $request->input($field);
                }
            }
            if ($request->has('deposit_user_id') || $isDepositStatus) {
                $payload['deposit_user_id'] = $request->input('deposit_user_id') ?? $user->id;
            }

            $this->ensureVisibleClientsForProperty($payload, $locked);
            $payload = $this->syncPropertyClientSnapshots($payload);

            // 1️⃣ Обновляем сам объект
            $locked->update($payload);

            // 2️⃣ Агенты — заменяем только если клиент прислал список
            if ($request->has('agents')) {
                $locked->saleAgents()->sync($this->saleAgentsSyncPayload($request->input('agents', [])));
            }
            $this->moderation->auditPropertyEvent($locked, $user, 'property_deal_status_changed', [
                'status_comment' => $request->status_comment,
            ]);

            return $locked->fresh();
        });

        return response()->json([
            'message' => 'Сделка успешно сохранена',
            'property_id' => $updated->id,
            'data' => $updated,
        ]);
    }

    private function saleAgentsSyncPayload(array $agents): array
    {
        $payload = collect($agents)
            ->filter(fn ($agent) => ! empty($agent['agent_id']))
            ->groupBy(fn ($agent) => (int) $agent['agent_id'])
            ->map(function ($group, $agentId) {
                $agent = collect($group)->first();

                return [
                    'agent_id' => (int) $agentId,
                    'pivot' => [
                        'role' => $agent['role'] ?? 'assistant',
                        'agent_commission_amount' => $agent['commission_amount'] ?? null,
                        'agent_commission_currency' => $agent['commission_currency'] ?? 'TJS',
                        'agent_paid_at' => $agent['paid_at'] ?? null,
                    ],
                ];
            })
            ->values();

        if ($payload->count() > 3) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'agents' => 'Можно указать не более 3 продавцов для одного объекта.',
            ]);
        }

        return $payload
            ->mapWithKeys(fn (array $item) => [$item['agent_id'] => $item['pivot']])
            ->toArray();
    }
}
