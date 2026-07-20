<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ExternalPropertyRequest;
use App\Models\ExternalPropertyRequestLog;
use App\Models\Notification;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExternalPropertyRequestService
{
    public function scopedInternalQuery(User $user): Builder
    {
        $user->loadMissing('role');

        $query = ExternalPropertyRequest::query()
            ->with([
                'externalAgent.role',
                'assignedAgent.role',
                'property',
                'type',
                'location',
                'photos',
            ]);

        if ($user->hasRole('admin') || $user->hasRole('superadmin')) {
            return $query;
        }

        if ($user->hasRole('mop')) {
            if (empty($user->branch_group_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('branch_group_id', $user->branch_group_id);
        }

        if ($user->hasRole('rop') || $user->hasRole('branch_director')) {
            if (empty($user->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('branch_id', $user->branch_id);
        }

        if ($user->hasRole('agent') || $user->hasRole('manager') || $user->hasRole('operator')) {
            return $query->where(function (Builder $scope) use ($user) {
                $scope->where('assigned_agent_id', $user->id);

                if (!empty($user->branch_group_id)) {
                    $scope->orWhere('branch_group_id', $user->branch_group_id);
                }

                if (!empty($user->branch_id)) {
                    $scope->orWhere('branch_id', $user->branch_id);
                }
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function ensureInternalCanAccess(User $user, ExternalPropertyRequest $request): void
    {
        abort_unless(
            $this->scopedInternalQuery($user)->whereKey($request->getKey())->exists(),
            403,
            'Доступ запрещён'
        );
    }

    public function applyCreateDefaultsForExternalAgent(User $externalAgent, array $data, bool $draft = false): array
    {
        $externalAgent->loadMissing('role');

        abort_unless($externalAgent->hasRole('external_agent'), 403, 'Доступ запрещён');

        $data['external_agent_id'] = $externalAgent->id;
        $data['status'] = $draft ? ExternalPropertyRequest::STATUS_DRAFT : ExternalPropertyRequest::STATUS_SUBMITTED;
        $data['submitted_at'] = $draft ? null : now();
        $data['owner_phone_normalized'] = ClientPhone::normalize($data['owner_phone'] ?? null);

        $data['branch_id'] = $externalAgent->branch_id;
        $data['branch_group_id'] = $externalAgent->branch_group_id;

        return $data;
    }

    public function updateExternalRequest(ExternalPropertyRequest $request, array $data): ExternalPropertyRequest
    {
        abort_unless(
            in_array($request->status, ExternalPropertyRequest::editableByExternalAgentStatuses(), true),
            422,
            'Заявку уже нельзя редактировать.'
        );

        $data['owner_phone_normalized'] = ClientPhone::normalize($data['owner_phone'] ?? $request->owner_phone);
        $oldStatus = $request->status;

        if ($request->status === ExternalPropertyRequest::STATUS_NEEDS_INFO) {
            $data['status'] = ExternalPropertyRequest::STATUS_SUBMITTED;
            $data['submitted_at'] = now();
        }

        $request->update($data);

        if ($oldStatus !== $request->status) {
            $this->log($request, $request->externalAgent, 'updated_by_external_agent', $oldStatus, $request->status);
        }

        if ($request->status === ExternalPropertyRequest::STATUS_SUBMITTED) {
            $this->detectAndMarkDuplicateCandidate($request, $request->externalAgent);
        }

        return $request->fresh(['photos', 'property']);
    }

    public function submitDraft(ExternalPropertyRequest $request, User $actor): ExternalPropertyRequest
    {
        abort_unless($request->status === ExternalPropertyRequest::STATUS_DRAFT, 422, 'Можно отправить только черновик.');
        $this->ensureReadyForSubmit($request);

        $oldStatus = $request->status;
        $request->update([
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->detectAndMarkDuplicateCandidate($request, $actor);
        $request->refresh();

        $this->log($request, $actor, 'submitted', $oldStatus, $request->status);
        $this->notifyInternalNewRequest($request, $actor);

        return $request->fresh(['photos', 'property']);
    }

    public function assign(ExternalPropertyRequest $request, User $actor, ?int $assignedAgentId): ExternalPropertyRequest
    {
        if ($assignedAgentId) {
            $this->ensureAssignableAgent($actor, $assignedAgentId, $request);
        }

        $oldStatus = $request->status;
        $request->update([
            'assigned_agent_id' => $assignedAgentId,
            'status' => $assignedAgentId ? ExternalPropertyRequest::STATUS_ASSIGNED : ExternalPropertyRequest::STATUS_SUBMITTED,
            'assigned_at' => $assignedAgentId ? now() : null,
        ]);

        $this->log($request, $actor, 'assigned', $oldStatus, $request->status, null, [
            'assigned_agent_id' => $assignedAgentId,
        ]);

        if ($assignedAgentId) {
            $this->notifyUserId(
                $assignedAgentId,
                $request,
                $actor,
                'external_property_request_assigned',
                'Назначена заявка внешнего агента',
                'Вам назначена заявка внешнего агента #' . $request->id . '.',
                '/external-agent-requests/' . $request->id
            );
        }

        return $request->fresh(['externalAgent', 'assignedAgent', 'property', 'photos']);
    }

    public function changeStatus(ExternalPropertyRequest $request, User $actor, string $status, ?string $comment = null): ExternalPropertyRequest
    {
        abort_if($request->status === ExternalPropertyRequest::STATUS_CONVERTED, 422, 'Сконвертированную заявку нельзя менять.');

        $oldStatus = $request->status;
        $payload = ['status' => $status];

        if ($status === ExternalPropertyRequest::STATUS_NEEDS_INFO) {
            $payload['needs_info_comment'] = $comment;
        }

        if ($status === ExternalPropertyRequest::STATUS_REJECTED) {
            $payload['rejection_reason'] = $comment;
            $payload['rejected_at'] = now();
        }

        $request->update($payload);

        $this->log($request, $actor, 'status_changed', $oldStatus, $status, $comment);

        if ($status === ExternalPropertyRequest::STATUS_NEEDS_INFO) {
            $this->notifyUserId(
                $request->external_agent_id,
                $request,
                $actor,
                'external_property_request_needs_info',
                'Нужно уточнить заявку',
                $comment ?: 'По вашей заявке #' . $request->id . ' нужны уточнения.',
                '/external/property-requests/' . $request->id
            );
        }

        if ($status === ExternalPropertyRequest::STATUS_REJECTED) {
            $this->notifyUserId(
                $request->external_agent_id,
                $request,
                $actor,
                'external_property_request_rejected',
                'Заявка отклонена',
                $comment ?: 'Ваша заявка #' . $request->id . ' отклонена.',
                '/external/property-requests/' . $request->id
            );
        }

        return $request->fresh(['externalAgent', 'assignedAgent', 'property', 'photos']);
    }

    public function prefillPayload(ExternalPropertyRequest $request, User $actor): array
    {
        return array_filter([
            'title' => $this->defaultTitle($request),
            'description' => $request->external_comment,
            'offer_type' => $request->offer_type,
            'type_id' => $request->type_id,
            'status_id' => $this->defaultPropertyStatusId(),
            'location_id' => $request->location_id,
            'repair_type_id' => $request->repair_type_id,
            'price' => $request->price,
            'currency' => $request->currency,
            'rooms' => $request->rooms,
            'total_area' => $request->total_area,
            'living_area' => $request->living_area,
            'land_size' => $request->land_size,
            'floor' => $request->floor,
            'total_floors' => $request->total_floors,
            'condition' => $request->condition,
            'district' => $request->district,
            'address' => $request->address,
            'landmark' => $request->landmark,
            'owner_name' => $request->owner_name,
            'owner_phone' => $request->owner_phone,
            'branch_id' => $request->branch_id ?: $actor->branch_id,
            'branch_group_id' => $request->branch_group_id ?: $actor->branch_group_id,
            'agent_id' => $request->assigned_agent_id ?: $actor->id,
            'moderation_status' => 'pending',
            'listing_type' => 'regular',
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function convert(
        ExternalPropertyRequest $request,
        User $actor,
        array $payload,
        bool $copyPhotos = true,
        bool $force = false
    ): Property
    {
        abort_if($request->property_id || $request->status === ExternalPropertyRequest::STATUS_CONVERTED, 422, 'Заявка уже сконвертирована.');
        abort_if(in_array($request->status, [ExternalPropertyRequest::STATUS_REJECTED, ExternalPropertyRequest::STATUS_ARCHIVED], true), 422, 'Закрытую заявку нельзя конвертировать.');
        abort_if($actor->hasRole('intern') || $actor->hasRole('external_agent') || $actor->hasRole('client'), 403, 'Доступ запрещён');
        abort_if($request->duplicate_property_id && !$force, 409, 'Найден возможный дубль. Подтвердите конвертацию с force=true.');

        return DB::transaction(function () use ($request, $actor, $payload, $copyPhotos, $force) {
            $request->refresh();
            abort_if($request->property_id, 422, 'Заявка уже сконвертирована.');
            abort_if($request->duplicate_property_id && !$force, 409, 'Найден возможный дубль. Подтвердите конвертацию с force=true.');

            $propertyPayload = array_merge($this->prefillPayload($request, $actor), $payload);
            $propertyPayload['created_by'] = $actor->id;
            $propertyPayload['agent_id'] = $propertyPayload['agent_id'] ?? $request->assigned_agent_id ?? $actor->id;
            $propertyPayload['branch_id'] = $propertyPayload['branch_id'] ?? $request->branch_id ?? $actor->branch_id;
            $propertyPayload['branch_group_id'] = $propertyPayload['branch_group_id'] ?? $request->branch_group_id ?? $actor->branch_group_id;
            $propertyPayload['external_agent_id'] = $request->external_agent_id;
            $propertyPayload['external_property_request_id'] = $request->id;
            $propertyPayload['source_type'] = ExternalPropertyRequest::SOURCE_TYPE;
            $propertyPayload['moderation_status'] = $propertyPayload['moderation_status'] ?? 'pending';
            $propertyPayload['listing_type'] = $propertyPayload['listing_type'] ?? 'regular';

            $ownerClient = $this->findOrCreateOwnerClient($request, $actor, $propertyPayload);
            if ($ownerClient) {
                $propertyPayload['owner_client_id'] = $ownerClient->id;
                $propertyPayload['owner_name'] = $ownerClient->full_name;
                $propertyPayload['owner_phone'] = $ownerClient->phone;
            }

            $property = Property::create($propertyPayload);

            if ($copyPhotos) {
                $this->copyPhotosToProperty($request, $property);
            }

            $oldStatus = $request->status;
            $request->update([
                'status' => ExternalPropertyRequest::STATUS_CONVERTED,
                'property_id' => $property->id,
                'owner_client_id' => $ownerClient?->id,
                'assigned_agent_id' => $request->assigned_agent_id ?: $actor->id,
                'converted_at' => now(),
            ]);

            $this->log($request, $actor, 'converted_to_property', $oldStatus, ExternalPropertyRequest::STATUS_CONVERTED, null, [
                'property_id' => $property->id,
            ]);

            $this->notifyUserId(
                $request->external_agent_id,
                $request,
                $actor,
                'external_property_request_converted',
                'Объявление создано',
                'По вашей заявке #' . $request->id . ' создано объявление #' . $property->id . '.',
                '/external/property-requests/' . $request->id,
                ['property_id' => $property->id]
            );

            return $property->fresh(['photos', 'ownerClient.type', 'externalAgent', 'externalPropertyRequest']);
        });
    }

    public function log(
        ExternalPropertyRequest $request,
        ?User $actor,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        ?array $payload = null
    ): ExternalPropertyRequestLog {
        return $request->logs()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'payload' => $payload,
        ]);
    }

    public function notifyInternalNewRequest(ExternalPropertyRequest $request, ?User $actor = null): void
    {
        foreach ($this->internalNotificationRecipients($request) as $recipient) {
            $this->notifyUser(
                $recipient,
                $request,
                $actor,
                'external_property_request_new',
                'Новая заявка внешнего агента',
                'Поступила новая заявка внешнего агента #' . $request->id . '.',
                '/external-agent-requests/' . $request->id
            );
        }
    }

    public function notifyExternalUpdated(ExternalPropertyRequest $request, ?User $actor = null): void
    {
        $recipientId = $request->assigned_agent_id;

        if (!$recipientId) {
            return;
        }

        $this->notifyUserId(
            $recipientId,
            $request,
            $actor,
            'external_property_request_updated',
            'Заявка внешнего агента обновлена',
            'Внешний агент обновил заявку #' . $request->id . '.',
            '/external-agent-requests/' . $request->id
        );
    }

    public function detectAndMarkDuplicateCandidate(ExternalPropertyRequest $request, ?User $actor = null): ?Property
    {
        if ($request->property_id || $request->status === ExternalPropertyRequest::STATUS_CONVERTED) {
            return null;
        }

        $candidate = $this->duplicateCandidateQuery($request)->first();

        if (!$candidate) {
            if ($request->duplicate_property_id && $request->status === ExternalPropertyRequest::STATUS_DUPLICATE) {
                $request->update([
                    'duplicate_property_id' => null,
                    'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
                ]);
            }

            return null;
        }

        $oldStatus = $request->status;
        $request->update([
            'duplicate_property_id' => $candidate->id,
            'status' => ExternalPropertyRequest::STATUS_DUPLICATE,
        ]);

        $this->log($request, $actor, 'duplicate_detected', $oldStatus, ExternalPropertyRequest::STATUS_DUPLICATE, null, [
            'duplicate_property_id' => $candidate->id,
        ]);

        return $candidate;
    }

    public function ensureReadyForSubmit(ExternalPropertyRequest $request): void
    {
        $errors = [];

        foreach (['offer_type', 'type_id', 'price', 'currency', 'owner_phone'] as $field) {
            if ($request->{$field} === null || $request->{$field} === '') {
                $errors[$field][] = 'Поле обязательно для отправки заявки.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ensureAssignableAgent(User $actor, int $assignedAgentId, ExternalPropertyRequest $request): void
    {
        $assignee = User::query()->with('role')->findOrFail($assignedAgentId);

        abort_unless(
            $assignee->hasRole('agent')
                || $assignee->hasRole('manager')
                || $assignee->hasRole('operator')
                || $assignee->hasRole('mop')
                || $assignee->hasRole('rop')
                || $assignee->hasRole('branch_director'),
            422,
            'Назначить можно только внутреннего сотрудника.'
        );

        if ($actor->hasRole('admin') || $actor->hasRole('superadmin')) {
            return;
        }

        if ($request->branch_group_id && $assignee->branch_group_id) {
            abort_unless((int) $assignee->branch_group_id === (int) $request->branch_group_id, 403, 'Сотрудник вне зоны заявки.');
            return;
        }

        if ($request->branch_id && $assignee->branch_id) {
            abort_unless((int) $assignee->branch_id === (int) $request->branch_id, 403, 'Сотрудник вне зоны заявки.');
            return;
        }

        abort(403, 'Сотрудник вне зоны заявки.');
    }

    private function findOrCreateOwnerClient(ExternalPropertyRequest $request, User $actor, array $propertyPayload): ?Client
    {
        if (!empty($propertyPayload['owner_client_id'])) {
            $client = Client::query()->find($propertyPayload['owner_client_id']);
            if ($client) {
                $mergedKind = $client->mergedContactKindFor(Client::CONTACT_KIND_SELLER);
                if ($client->contact_kind !== $mergedKind) {
                    $client->update(['contact_kind' => $mergedKind]);
                }

                return $client;
            }
        }

        $phone = $propertyPayload['owner_phone'] ?? $request->owner_phone;
        $normalizedPhone = ClientPhone::normalize($phone);
        $name = $propertyPayload['owner_name'] ?? $request->owner_name;

        if (!$normalizedPhone && !$name) {
            return null;
        }

        $client = null;
        if ($normalizedPhone) {
            $client = Client::query()
                ->where('phone_normalized', $normalizedPhone)
                ->when(Schema::hasColumn('clients', 'branch_id') && $actor->branch_id, fn ($query) => $query->where(function ($scope) use ($actor) {
                    $scope->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
                }))
                ->first();
        }

        if (!$client) {
            $client = Client::query()->create([
                'full_name' => $name ?: 'Владелец объекта',
                'phone' => $phone,
                'phone_normalized' => $normalizedPhone,
                'branch_id' => $propertyPayload['branch_id'] ?? $actor->branch_id,
                'branch_group_id' => $propertyPayload['branch_group_id'] ?? $actor->branch_group_id,
                'created_by' => $actor->id,
                'responsible_agent_id' => $actor->id,
                'contact_kind' => Client::CONTACT_KIND_SELLER,
                'status' => 'active',
            ]);
        } else {
            $mergedKind = $client->mergedContactKindFor(Client::CONTACT_KIND_SELLER);
            if ($client->contact_kind !== $mergedKind) {
                $client->update(['contact_kind' => $mergedKind]);
            }
        }

        return $client;
    }

    private function copyPhotosToProperty(ExternalPropertyRequest $request, Property $property): void
    {
        foreach ($request->photos()->orderBy('position')->orderBy('id')->get() as $index => $photo) {
            $targetPath = $photo->file_path;

            if (Storage::disk('public')->exists($photo->file_path)) {
                $extension = pathinfo($photo->file_path, PATHINFO_EXTENSION) ?: 'jpg';
                $targetPath = 'properties/external-' . $request->id . '-' . $photo->id . '-' . uniqid('', true) . '.' . $extension;
                Storage::disk('public')->copy($photo->file_path, $targetPath);
            }

            PropertyPhoto::query()->create([
                'property_id' => $property->id,
                'file_path' => $targetPath,
                'position' => $index,
            ]);
        }
    }

    private function defaultTitle(ExternalPropertyRequest $request): ?string
    {
        $typeName = $request->type?->name
            ?: ($request->type_id ? PropertyType::query()->whereKey($request->type_id)->value('name') : null)
            ?: 'Объект';

        $parts = [];
        if ($request->rooms) {
            $parts[] = $request->rooms . '-комн.';
        }

        $parts[] = mb_strtolower($typeName, 'UTF-8');

        if ($request->district) {
            $parts[] = $request->district;
        }

        return trim(implode(', ', $parts));
    }

    private function defaultPropertyStatusId(): ?int
    {
        return PropertyStatus::query()->orderBy('id')->value('id');
    }

    private function duplicateCandidateQuery(ExternalPropertyRequest $request): Builder
    {
        $query = Property::query()->where('moderation_status', '!=', 'deleted');

        // Продажа и аренда одного объекта могут существовать одновременно.
        if (in_array($request->offer_type, ['rent', 'sale'], true)) {
            $query->where('offer_type', $request->offer_type);
        }

        $normalizedPhone = ClientPhone::normalize($request->owner_phone);
        $lastNine = $normalizedPhone ? substr($normalizedPhone, -9) : null;

        $hasStrongSignal = false;
        $query->where(function (Builder $duplicates) use ($request, $lastNine, &$hasStrongSignal) {
            if ($request->owner_phone && Schema::hasColumn('properties', 'owner_phone')) {
                $hasStrongSignal = true;
                $duplicates->orWhere('owner_phone', $request->owner_phone);

                if ($lastNine) {
                    $normalizedOwnerPhoneSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(owner_phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '')";
                    $duplicates->orWhereRaw($normalizedOwnerPhoneSql . ' LIKE ?', ['%' . $lastNine]);
                }
            }

            if ($request->address && Schema::hasColumn('properties', 'address')) {
                $hasStrongSignal = true;
                $duplicates->orWhere(function (Builder $addressQuery) use ($request) {
                    $addressQuery->where('address', $request->address);

                    if ($request->district && Schema::hasColumn('properties', 'district')) {
                        $addressQuery->where('district', $request->district);
                    }

                    if ($request->floor !== null && Schema::hasColumn('properties', 'floor')) {
                        $addressQuery->where('floor', $request->floor);
                    }
                });
            }
        });

        if (!$hasStrongSignal) {
            return Property::query()->whereRaw('1 = 0');
        }

        if ($request->type_id && Schema::hasColumn('properties', 'type_id')) {
            $query->where('type_id', $request->type_id);
        }

        if ($request->rooms && Schema::hasColumn('properties', 'rooms')) {
            $query->where(function (Builder $roomsQuery) use ($request) {
                $roomsQuery->whereNull('rooms')->orWhere('rooms', $request->rooms);
            });
        }

        return $query->latest('id');
    }

    private function internalNotificationRecipients(ExternalPropertyRequest $request)
    {
        return User::query()
            ->with('role')
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn (Builder $query) => $query->whereIn('slug', [
                'admin',
                'superadmin',
                'rop',
                'branch_director',
                'mop',
                'agent',
                'manager',
                'operator',
            ]))
            ->where(function (Builder $query) use ($request) {
                $query->whereHas('role', fn (Builder $roleQuery) => $roleQuery->whereIn('slug', ['admin', 'superadmin']));

                if ($request->branch_id) {
                    $query->orWhere(function (Builder $branchQuery) use ($request) {
                        $branchQuery
                            ->where('branch_id', $request->branch_id)
                            ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->whereIn('slug', [
                                'rop',
                                'branch_director',
                                'agent',
                                'manager',
                                'operator',
                            ]));
                    });
                }

                if ($request->branch_group_id) {
                    $query->orWhere(function (Builder $groupQuery) use ($request) {
                        $groupQuery
                            ->where('branch_group_id', $request->branch_group_id)
                            ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->whereIn('slug', [
                                'mop',
                                'agent',
                                'manager',
                                'operator',
                            ]));
                    });
                }
            })
            ->limit(50)
            ->get();
    }

    private function notifyUserId(
        ?int $recipientId,
        ExternalPropertyRequest $request,
        ?User $actor,
        string $type,
        string $title,
        string $body,
        string $actionUrl,
        array $data = []
    ): void {
        if (!$recipientId) {
            return;
        }

        $recipient = User::query()->find($recipientId);
        if ($recipient) {
            $this->notifyUser($recipient, $request, $actor, $type, $title, $body, $actionUrl, $data);
        }
    }

    private function notifyUser(
        User $recipient,
        ExternalPropertyRequest $request,
        ?User $actor,
        string $type,
        string $title,
        string $body,
        string $actionUrl,
        array $data = []
    ): void {
        if (!$this->canWriteNotification() || ($actor && (int) $actor->id === (int) $recipient->id)) {
            return;
        }

        try {
            $dedupeKey = $type . ':' . $request->id . ':' . $recipient->id;
            $existing = Notification::query()
                ->where('user_id', $recipient->id)
                ->where('type', $type)
                ->where('dedupe_key', $dedupeKey)
                ->whereNull('read_at')
                ->first();

            $payload = [
                'actor_id' => $actor?->id,
                'category' => 'workflow',
                'status' => 'unread',
                'priority' => 2,
                'channels' => ['in_app'],
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'action_type' => 'open_external_property_request',
                'last_occurred_at' => now(),
                'subject_type' => $request->getMorphClass(),
                'subject_id' => $request->id,
                'data' => array_merge([
                    'external_property_request_id' => $request->id,
                    'status' => $request->status,
                ], $data),
            ];

            if ($existing) {
                $existing->update(array_merge($payload, [
                    'occurrences_count' => ((int) $existing->occurrences_count) + 1,
                ]));
                return;
            }

            Notification::query()->create(array_merge($payload, [
                'user_id' => $recipient->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'occurrences_count' => 1,
            ]));
        } catch (\Throwable $exception) {
            Log::warning('External property request notification skipped.', [
                'request_id' => $request->id,
                'recipient_id' => $recipient->id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function canWriteNotification(): bool
    {
        return Schema::hasTable('notifications')
            && Schema::hasColumn('notifications', 'user_id')
            && Schema::hasColumn('notifications', 'type')
            && Schema::hasColumn('notifications', 'dedupe_key');
    }
}
