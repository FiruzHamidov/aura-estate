<?php

namespace App\Http\Controllers;

use App\Models\ExternalPropertyRequest;
use App\Models\ExternalPropertyRequestPhoto;
use App\Models\User;
use App\Services\ExternalPropertyRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExternalPropertyRequestController extends Controller
{
    public function __construct(
        private readonly ExternalPropertyRequestService $service
    ) {
    }

    public function myIndex(Request $request)
    {
        $user = $this->externalAgent($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(ExternalPropertyRequest::statuses())],
            'q' => ['sometimes', 'nullable', 'string'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ExternalPropertyRequest::query()
            ->with(['property', 'photos', 'type', 'location'])
            ->where('external_agent_id', $user->id);

        $this->applyListFilters($query, $validated);

        return response()->json($this->externalPaginator(
            $query->latest('id')->paginate((int) ($validated['per_page'] ?? 20))
        ));
    }

    public function myStats(Request $request)
    {
        $user = $this->externalAgent($request);
        $validated = $request->validate([
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
        ]);

        $query = ExternalPropertyRequest::query()
            ->where('external_agent_id', $user->id);

        $this->applyListFilters($query, $validated);

        return response()->json($this->statsPayload($query));
    }

    public function myStore(Request $request)
    {
        $user = $this->externalAgent($request);
        $draft = $request->boolean('draft', false);
        $validated = $this->validateRequestPayload($request, $draft);

        $externalRequest = ExternalPropertyRequest::query()->create(
            $this->service->applyCreateDefaultsForExternalAgent($user, $validated, $draft)
        );

        if (!$draft) {
            $this->service->detectAndMarkDuplicateCandidate($externalRequest, $user);
            $externalRequest->refresh();
        }

        $this->service->log(
            $externalRequest,
            $user,
            $draft ? 'created' : 'submitted',
            null,
            $externalRequest->status
        );

        if (!$draft) {
            $this->service->notifyInternalNewRequest($externalRequest, $user);
        }

        return response()->json($this->externalPayload($externalRequest->fresh(['photos', 'property'])), 201);
    }

    public function myShow(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->externalAgent($request);
        $this->ensureOwnRequest($externalPropertyRequest, $user);

        return response()->json($this->externalPayload(
            $externalPropertyRequest->load(['property', 'photos', 'type', 'location', 'logs'])
        ));
    }

    public function myUpdate(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->externalAgent($request);
        $this->ensureOwnRequest($externalPropertyRequest, $user);

        $validated = $this->validateRequestPayload($request, draft: true, updating: true);
        $updated = $this->service->updateExternalRequest($externalPropertyRequest, $validated);
        $this->service->notifyExternalUpdated($updated, $user);

        return response()->json($this->externalPayload($updated));
    }

    public function mySubmit(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->externalAgent($request);
        $this->ensureOwnRequest($externalPropertyRequest, $user);
        Validator::make($externalPropertyRequest->toArray(), [
            'offer_type' => ['required', Rule::in(['rent', 'sale'])],
            'type_id' => ['required', 'integer', 'exists:property_types,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['TJS', 'USD'])],
            'owner_phone' => ['required', 'string', 'max:40'],
        ])->validate();

        return response()->json($this->externalPayload($this->service->submitDraft($externalPropertyRequest, $user)));
    }

    public function myStorePhoto(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->externalAgent($request);
        $this->ensureOwnRequest($externalPropertyRequest, $user);
        $this->ensurePhotoEditable($externalPropertyRequest);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'max:40'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $created = [];
        $basePosition = (int) ($externalPropertyRequest->photos()->max('position') ?? -1) + 1;
        foreach ($validated['photos'] as $index => $file) {
            $path = $file->store('external-property-requests/' . $externalPropertyRequest->id, 'public');
            $created[] = $externalPropertyRequest->photos()->create([
                'file_path' => $path,
                'position' => $basePosition + $index,
            ]);
        }

        $this->service->log($externalPropertyRequest, $user, 'photos_added', null, null, null, [
            'count' => count($created),
        ]);

        return response()->json([
            'data' => $created,
        ], 201);
    }

    public function myDestroyPhoto(
        Request $request,
        ExternalPropertyRequest $externalPropertyRequest,
        ExternalPropertyRequestPhoto $photo
    ) {
        $user = $this->externalAgent($request);
        $this->ensureOwnRequest($externalPropertyRequest, $user);
        $this->ensurePhotoEditable($externalPropertyRequest);
        abort_unless((int) $photo->external_property_request_id === (int) $externalPropertyRequest->id, 404);

        Storage::disk('public')->delete($photo->file_path);
        $photo->delete();

        $this->service->log($externalPropertyRequest, $user, 'photo_deleted');

        return response()->json(['message' => 'Фото удалено']);
    }

    public function internalIndex(Request $request)
    {
        $user = $this->internalUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(ExternalPropertyRequest::statuses())],
            'external_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['sometimes', 'nullable', 'integer', 'exists:branch_groups,id'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
            'has_duplicate' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->service->scopedInternalQuery($user);
        $this->applyListFilters($query, $validated, internal: true);

        return response()->json($query->latest('id')->paginate((int) ($validated['per_page'] ?? 20)));
    }

    public function internalStats(Request $request)
    {
        $user = $this->internalUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(ExternalPropertyRequest::statuses())],
            'external_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['sometimes', 'nullable', 'integer', 'exists:branch_groups,id'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
            'has_duplicate' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string'],
        ]);

        $query = $this->service->scopedInternalQuery($user);
        $this->applyListFilters($query, $validated, internal: true);

        return response()->json($this->statsPayload($query));
    }

    public function internalLeaderboard(Request $request)
    {
        $user = $this->internalUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(ExternalPropertyRequest::statuses())],
            'external_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['sometimes', 'nullable', 'integer', 'exists:branch_groups,id'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
            'has_duplicate' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->service->scopedInternalQuery($user)
            ->with(['externalAgent', 'property']);
        $this->applyListFilters($query, $validated, internal: true);

        $rows = $query
            ->get()
            ->groupBy('external_agent_id')
            ->map(function ($requests, $externalAgentId) {
                $total = $requests->count();
                $converted = $requests->where('status', ExternalPropertyRequest::STATUS_CONVERTED)->count();
                $published = $requests
                    ->filter(fn (ExternalPropertyRequest $item) => $item->property?->moderation_status === 'approved')
                    ->count();
                $closedDeal = $requests
                    ->filter(fn (ExternalPropertyRequest $item) => in_array($item->property?->moderation_status, [
                        'sold',
                        'rented',
                        'sold_by_owner',
                    ], true))
                    ->count();

                return [
                    'external_agent_id' => (int) $externalAgentId,
                    'external_agent_name' => $requests->first()?->externalAgent?->name,
                    'total' => $total,
                    'converted' => $converted,
                    'published' => $published,
                    'closed_deal' => $closedDeal,
                    'duplicates' => $requests->where('status', ExternalPropertyRequest::STATUS_DUPLICATE)->count(),
                    'rejected' => $requests->where('status', ExternalPropertyRequest::STATUS_REJECTED)->count(),
                    'conversion_rate' => $total > 0 ? round($converted / $total, 4) : 0,
                ];
            })
            ->sort(function (array $left, array $right) {
                return [$right['converted'], $right['total'], $right['closed_deal'], $left['external_agent_id']]
                    <=> [$left['converted'], $left['total'], $left['closed_deal'], $right['external_agent_id']];
            })
            ->values()
            ->take((int) ($validated['limit'] ?? 50))
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function internalShow(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->internalUser($request);
        $this->service->ensureInternalCanAccess($user, $externalPropertyRequest);

        return response()->json($externalPropertyRequest->load([
            'externalAgent.role',
            'assignedAgent.role',
            'branch',
            'branchGroup',
            'property',
            'ownerClient',
            'duplicateProperty',
            'type',
            'location',
            'repairType',
            'photos',
            'logs.actor.role',
        ]));
    }

    public function assign(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->internalUser($request);
        $this->service->ensureInternalCanAccess($user, $externalPropertyRequest);
        $validated = $request->validate([
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return response()->json($this->service->assign(
            $externalPropertyRequest,
            $user,
            $validated['assigned_agent_id'] ?? null
        ));
    }

    public function changeStatus(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->internalUser($request);
        $this->service->ensureInternalCanAccess($user, $externalPropertyRequest);
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_diff(
                ExternalPropertyRequest::statuses(),
                [ExternalPropertyRequest::STATUS_DRAFT, ExternalPropertyRequest::STATUS_CONVERTED]
            ))],
            'comment' => ['sometimes', 'nullable', 'string'],
        ]);

        return response()->json($this->service->changeStatus(
            $externalPropertyRequest,
            $user,
            $validated['status'],
            $validated['comment'] ?? null
        ));
    }

    public function prefill(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->internalUser($request);
        $this->service->ensureInternalCanAccess($user, $externalPropertyRequest);
        $externalPropertyRequest->load(['externalAgent', 'photos', 'type']);

        return response()->json([
            'property_payload' => $this->service->prefillPayload($externalPropertyRequest, $user),
            'photos' => $externalPropertyRequest->photos->map(fn (ExternalPropertyRequestPhoto $photo) => [
                'id' => $photo->id,
                'url' => Storage::disk('public')->url($photo->file_path),
                'position' => $photo->position,
            ])->values(),
            'source' => [
                'external_agent_id' => $externalPropertyRequest->external_agent_id,
                'external_agent_name' => $externalPropertyRequest->externalAgent?->name,
            ],
        ]);
    }

    public function convert(Request $request, ExternalPropertyRequest $externalPropertyRequest)
    {
        $user = $this->internalUser($request);
        $this->service->ensureInternalCanAccess($user, $externalPropertyRequest);

        $incoming = $request->except(['copy_photos', 'force']);
        $merged = array_merge($this->service->prefillPayload($externalPropertyRequest, $user), $incoming);
        $validated = Validator::make($merged, $this->convertRules())->validate();

        $property = $this->service->convert(
            $externalPropertyRequest,
            $user,
            $validated,
            $request->boolean('copy_photos', true),
            $request->boolean('force', false)
        );

        return response()->json([
            'message' => 'Объявление создано из заявки внешнего агента',
            'data' => $property,
        ], 201);
    }

    private function validateRequestPayload(Request $request, bool $draft = false, bool $updating = false): array
    {
        $requiredWhenSubmitted = $draft || $updating ? 'sometimes' : 'required';

        return $request->validate([
            'offer_type' => [$requiredWhenSubmitted, 'nullable', Rule::in(['rent', 'sale'])],
            'type_id' => [$requiredWhenSubmitted, 'nullable', 'integer', 'exists:property_types,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => [$requiredWhenSubmitted, 'nullable', 'numeric', 'min:0'],
            'currency' => [$requiredWhenSubmitted, 'nullable', Rule::in(['TJS', 'USD'])],
            'rooms' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'total_area' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'living_area' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'land_size' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'floor' => ['sometimes', 'nullable', 'integer'],
            'total_floors' => ['sometimes', 'nullable', 'integer'],
            'repair_type_id' => ['sometimes', 'nullable', 'integer', 'exists:repair_types,id'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_phone' => [$requiredWhenSubmitted, 'nullable', 'string', 'max:40'],
            'external_comment' => ['sometimes', 'nullable', 'string'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function convertRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type_id' => ['required', 'integer', 'exists:property_types,id'],
            'status_id' => ['required', 'integer', 'exists:property_statuses,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'repair_type_id' => ['nullable', 'integer', 'exists:repair_types,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['TJS', 'USD'])],
            'offer_type' => ['required', Rule::in(['rent', 'sale'])],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:20'],
            'total_area' => ['nullable', 'numeric', 'min:0'],
            'living_area' => ['nullable', 'numeric', 'min:0'],
            'land_size' => ['nullable', 'numeric', 'min:0'],
            'floor' => ['nullable', 'integer'],
            'total_floors' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:40'],
            'owner_client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['nullable', 'integer', 'exists:branch_groups,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'moderation_status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'draft', 'deleted', 'deposit', 'sold', 'rented', 'sold_by_owner', 'denied'])],
            'listing_type' => ['sometimes', Rule::in(['regular', 'vip', 'urgent'])],
        ];
    }

    private function applyListFilters(Builder $query, array $filters, bool $internal = false): void
    {
        foreach (['status', 'external_agent_id', 'assigned_agent_id', 'branch_id', 'branch_group_id'] as $field) {
            if ($internal && array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (!$internal && !empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        if (array_key_exists('has_duplicate', $filters)) {
            $filters['has_duplicate']
                ? $query->whereNotNull('duplicate_property_id')
                : $query->whereNull('duplicate_property_id');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $search) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q, 'UTF-8')) . '%';
                if (ctype_digit($q)) {
                    $search->orWhere('id', (int) $q);
                }
                foreach (['district', 'address', 'owner_name', 'owner_phone', 'external_comment'] as $column) {
                    $search->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$like]);
                }
            });
        }
    }

    private function statsPayload(Builder $query): array
    {
        $statusCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $byStatus = collect(ExternalPropertyRequest::statuses())
            ->mapWithKeys(fn (string $status) => [$status => (int) ($statusCounts[$status] ?? 0)])
            ->all();

        $convertedQuery = (clone $query)->where('status', ExternalPropertyRequest::STATUS_CONVERTED);

        return [
            'total' => (clone $query)->count(),
            'by_status' => $byStatus,
            'work_queue' => [
                'new' => $byStatus[ExternalPropertyRequest::STATUS_SUBMITTED],
                'assigned' => $byStatus[ExternalPropertyRequest::STATUS_ASSIGNED],
                'in_review' => $byStatus[ExternalPropertyRequest::STATUS_IN_REVIEW],
                'needs_info' => $byStatus[ExternalPropertyRequest::STATUS_NEEDS_INFO],
                'duplicates' => $byStatus[ExternalPropertyRequest::STATUS_DUPLICATE],
            ],
            'converted' => [
                'total' => (clone $convertedQuery)->count(),
                'published' => (clone $convertedQuery)
                    ->whereHas('property', fn (Builder $propertyQuery) => $propertyQuery->where('moderation_status', 'approved'))
                    ->count(),
                'closed_deal' => (clone $convertedQuery)
                    ->whereHas('property', fn (Builder $propertyQuery) => $propertyQuery->whereIn('moderation_status', [
                        'sold',
                        'rented',
                        'sold_by_owner',
                    ]))
                    ->count(),
            ],
            'rejected' => $byStatus[ExternalPropertyRequest::STATUS_REJECTED],
            'archived' => $byStatus[ExternalPropertyRequest::STATUS_ARCHIVED],
        ];
    }

    private function externalPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->getCollection()->transform(fn (ExternalPropertyRequest $request) => $this->externalPayload($request));

        return $paginator;
    }

    private function externalPayload(ExternalPropertyRequest $request): array
    {
        $request->loadMissing(['photos', 'property', 'type', 'location']);

        $payload = $request->only([
            'id',
            'status',
            'display_status',
            'offer_type',
            'type_id',
            'location_id',
            'district',
            'address',
            'landmark',
            'price',
            'currency',
            'rooms',
            'total_area',
            'living_area',
            'land_size',
            'floor',
            'total_floors',
            'repair_type_id',
            'condition',
            'owner_name',
            'owner_phone',
            'external_comment',
            'needs_info_comment',
            'rejection_reason',
            'duplicate_property_id',
            'property_id',
            'submitted_at',
            'converted_at',
            'rejected_at',
            'created_at',
            'updated_at',
        ]);

        $payload['type'] = $request->type;
        $payload['location'] = $request->location;
        $payload['photos'] = $request->photos
            ->map(fn (ExternalPropertyRequestPhoto $photo) => [
                'id' => $photo->id,
                'url' => Storage::disk('public')->url($photo->file_path),
                'position' => $photo->position,
            ])
            ->values();
        $payload['property'] = $request->property ? [
            'id' => $request->property->id,
            'moderation_status' => $request->property->moderation_status,
            'public_url' => $request->property->moderation_status === 'approved'
                ? url('/apartment/' . $request->property->id)
                : null,
        ] : null;

        if ($request->relationLoaded('logs')) {
            $payload['logs'] = $request->logs
                ->filter(fn ($log) => in_array($log->action, [
                    'created',
                    'submitted',
                    'updated_by_external_agent',
                    'duplicate_detected',
                    'converted_to_property',
                    'status_changed',
                ], true))
                ->map(function ($log) {
                    $showComment = $log->to_status === ExternalPropertyRequest::STATUS_NEEDS_INFO
                        || $log->to_status === ExternalPropertyRequest::STATUS_REJECTED;

                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'from_status' => $log->from_status,
                        'to_status' => $log->to_status,
                        'comment' => $showComment ? $log->comment : null,
                        'created_at' => $log->created_at,
                    ];
                })
                ->values();
        }

        return $payload;
    }

    private function externalAgent(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');
        abort_unless($user->hasRole('external_agent'), 403, 'Доступ запрещён');

        return $user;
    }

    private function internalUser(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');
        abort_if($user->hasRole('client') || $user->hasRole('external_agent'), 403, 'Доступ запрещён');

        return $user;
    }

    private function ensureOwnRequest(ExternalPropertyRequest $request, User $user): void
    {
        abort_unless((int) $request->external_agent_id === (int) $user->id, 403, 'Доступ запрещён');
    }

    private function ensurePhotoEditable(ExternalPropertyRequest $request): void
    {
        abort_unless(
            in_array($request->status, ExternalPropertyRequest::editableByExternalAgentStatuses(), true),
            422,
            'Фото этой заявки уже нельзя менять.'
        );
    }
}
