<?php

namespace App\Services\PropertyModeration;

use App\Models\EmployeeTrustEvent;
use App\Models\Property;
use App\Models\PropertyDuplicateCandidate;
use App\Models\PropertyModerationCase;
use App\Models\PropertyModerationEvent;
use App\Models\PropertyPromotion;
use App\Models\User;
use App\Services\PropertyDuplicateService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PropertyModerationService
{
    public const PUBLICATION_DRAFT = 'draft';

    public const PUBLICATION_PENDING = 'pending';

    public const PUBLICATION_PUBLISHED = 'published';

    public const PUBLICATION_REJECTED = 'rejected';

    public const PUBLICATION_HIDDEN = 'hidden';

    public const PUBLICATION_ARCHIVED = 'archived';

    public const PROTECTED_FIELDS = [
        'force',
        'moderation_status',
        'publication_status',
        'deal_status',
        'listing_type',
        'approved_price',
        'approved_discount_price',
        'approved_effective_price',
        'approved_currency',
        'price_approved_at',
        'price_approved_by',
        'approved_content_snapshot',
        'duplicate_of_property_id',
        'moderation_version',
        'created_by',
        'agent_id',
        'co_owner_user_id',
        'branch_id',
        'branch_group_id',
        'deposit_amount',
        'deposit_currency',
        'deposit_received_at',
        'deposit_taken_at',
        'deposit_user_id',
        'money_holder',
        'money_received_at',
        'contract_signed_at',
        'company_expected_income',
        'company_expected_income_currency',
        'company_commission_amount',
        'company_commission_currency',
        'actual_sale_price',
        'actual_sale_currency',
        'planned_contract_signed_at',
        'sale_user_id',
        'agents',
        'sale_agents',
        'sold_at',
        'status_comment',
        'buyer_client_id',
        'buyer_full_name',
        'buyer_phone',
        'rejection_comment',
    ];

    private const PRICE_FIELDS = ['price', 'discount_price', 'currency'];

    private const DUPLICATE_FIELDS = [
        'owner_phone', 'owner_name', 'owner_client_id', 'type_id', 'location_id', 'district', 'address',
        'landmark', 'rooms', 'total_area', 'land_size', 'living_area', 'floor', 'total_floors', 'latitude', 'longitude',
        'repair_type_id', 'developer_id', 'year_built', 'description', 'price', 'discount_price',
        'currency', 'offer_type',
    ];

    private const MATERIAL_FIELDS = [
        'owner_phone', 'owner_name', 'owner_client_id', 'type_id', 'location_id', 'district', 'address',
        'latitude', 'longitude', 'rooms', 'total_area', 'land_size', 'living_area', 'floor', 'total_floors', 'developer_id',
        'offer_type',
    ];

    private const RISK_FIELDS = [
        'title', 'description', 'landmark', 'condition', 'repair_type_id',
        'document_type_id', 'contract_type_id', 'youtube_link', 'instagram_link', 'year_built',
    ];

    public function __construct(
        private readonly PropertyDuplicateService $duplicates,
        private readonly PropertyModerationAccess $access,
    ) {}

    public function assertNoProtectedFields(Request $request, array $except = [], ?User $actor = null, ?Property $property = null): void
    {
        $present = array_values(array_diff(array_intersect(
            array_keys($request->all()),
            self::PROTECTED_FIELDS,
        ), $except));

        if ($present === []) {
            return;
        }

        if (Schema::hasTable('property_moderation_events')) {
            PropertyModerationEvent::create([
                'property_id' => $property?->id,
                'event_type' => 'protected_field_attempt',
                'actor_id' => $actor?->id,
                'actor_role' => $actor?->role?->slug,
                'payload' => ['fields' => $present, 'route' => $request->route()?->uri()],
                'request_id' => $request->headers->get('Idempotency-Key') ?: $request->headers->get('X-Request-Id'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        $code = in_array('listing_type', $present, true) ? 'PROMOTION_PROTECTED_FIELD' : 'PROTECTED_FIELD';
        throw new HttpResponseException(response()->json([
            'message' => 'Защищённые поля нельзя изменять этим запросом.',
            'code' => $code,
            'protected_fields' => $present,
        ], 422));
    }

    public function assertMutationVersion(Request $request, Property $property): void
    {
        if (! $this->workflowAvailable()) {
            return;
        }
        $data = $request->validate(['version' => 'required|integer|min:0']);
        abort_if((int) $property->moderation_version !== (int) $data['version'], 409, 'MODERATION_VERSION_CONFLICT');
    }

    public function assertCanCreate(User $actor): void
    {
        abort_unless($this->access->canCreate($actor), 403, 'Недостаточно прав для создания объявления.');
    }

    /** @param Collection<int, array<string, mixed>> $duplicates */
    public function creationState(array $payload, Collection $duplicates, array $qualityWarnings = []): array
    {
        $published = $duplicates->isEmpty() && $qualityWarnings === [];

        if (! $this->workflowAvailable()) {
            return array_merge($payload, [
                'listing_type' => 'regular',
                'moderation_status' => $published ? Property::PUBLIC_MODERATION_STATUS : 'pending',
            ]);
        }

        return array_merge($payload, [
            'listing_type' => 'regular',
            'moderation_status' => $published ? Property::PUBLIC_MODERATION_STATUS : 'pending',
            'publication_status' => $published ? self::PUBLICATION_PUBLISHED : self::PUBLICATION_PENDING,
            'deal_status' => 'available',
            'moderation_version' => 1,
        ], $published ? $this->approvedPricePayload($payload) : []);
    }

    /** @param Collection<int, array<string, mixed>> $duplicates */
    public function recordCreation(Property $property, User $actor, Collection $duplicates, array $qualityWarnings = []): void
    {
        if (! $this->workflowAvailable()) {
            return;
        }

        if ($duplicates->isEmpty() && $qualityWarnings === []) {
            $snapshot = $this->contentSnapshot($property);
            $property->forceFill(['approved_content_snapshot' => $snapshot])->saveQuietly();
            $this->event($property, null, 'property_auto_published', $actor, ['snapshot' => $snapshot]);

            return;
        }

        if ($duplicates->isNotEmpty()) {
            $case = $this->upsertOpenCase(
                $property,
                PropertyModerationCase::TYPE_DUPLICATE,
                $actor,
                null,
                $this->contentSnapshot($property),
                ['duplicate_candidates']
            );
            $this->syncDuplicateCandidates($case, $duplicates);
            $this->event($property, $case, 'duplicate_review_opened', $actor, [
                'candidate_ids' => $duplicates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ]);
        }

        if ($qualityWarnings !== []) {
            $codes = collect($qualityWarnings)->pluck('code')->filter()->map(fn ($code) => 'quality:'.$code)->values()->all();
            $case = $this->upsertOpenCase($property, PropertyModerationCase::TYPE_INITIAL, $actor, null, $this->contentSnapshot($property), $codes);
            $this->event($property, $case, 'quality_review_opened', $actor, ['warnings' => $qualityWarnings]);
        }
    }

    /**
     * Evaluate a filled, unsaved property and apply the server-owned publication state.
     *
     * @return array{cases:array<int,array<string,mixed>>,duplicates:Collection<int,array<string,mixed>>,withdraw_case_types:array<int,string>,event:string|null}
     */
    public function evaluateUpdate(Property $property, User $actor, array $qualityWarnings = []): array
    {
        if (! $this->workflowAvailable()) {
            return ['cases' => [], 'duplicates' => collect(), 'withdraw_case_types' => [], 'event' => null];
        }

        $dirty = array_keys($property->getDirty());
        $wasPublished = $this->publicationStatus($property, original: true) === self::PUBLICATION_PUBLISHED;
        $hasApprovedSnapshot = (array) $property->approved_content_snapshot !== [];
        $cases = [];
        $duplicates = collect();
        $withdrawCaseTypes = [];

        if (array_intersect($dirty, self::DUPLICATE_FIELDS) !== []) {
            $duplicates = $this->duplicates->find($property->getAttributes(), (int) $property->id);
            if ($duplicates->isNotEmpty()) {
                $cases[] = [
                    'type' => PropertyModerationCase::TYPE_DUPLICATE,
                    'reason_codes' => ['duplicate_candidates'],
                ];
            }
        }

        $priceReview = $this->requiresPriceReview($property, $dirty);
        if ($priceReview) {
            $cases[] = [
                'type' => PropertyModerationCase::TYPE_PRICE_INCREASE,
                'reason_codes' => $priceReview['reasons'],
                'baseline_snapshot' => $priceReview['baseline'],
                'proposed_snapshot' => $priceReview['proposed'],
            ];
        } elseif (array_intersect($dirty, self::PRICE_FIELDS) !== [] && PropertyModerationCase::query()
            ->where('property_id', $property->id)
            ->where('type', PropertyModerationCase::TYPE_PRICE_INCREASE)
            ->open()
            ->exists()) {
            $withdrawCaseTypes[] = PropertyModerationCase::TYPE_PRICE_INCREASE;
        }

        $materialDirty = array_values(array_diff(
            array_intersect($dirty, self::MATERIAL_FIELDS),
            self::PRICE_FIELDS,
        ));
        $riskReasons = $this->substantialRiskReasons($property, $dirty);
        if ($qualityWarnings !== []) {
            $riskReasons = array_merge(
                $riskReasons,
                collect($qualityWarnings)->pluck('code')->filter()->map(fn ($code) => 'quality:'.$code)->all(),
            );
        }
        $contentReasons = array_values(array_unique(array_merge(
            array_map(fn (string $field) => 'changed:'.$field, $materialDirty),
            $riskReasons,
        )));
        if (($wasPublished || $hasApprovedSnapshot) && $contentReasons !== []) {
            $cases[] = [
                'type' => PropertyModerationCase::TYPE_CONTENT,
                'reason_codes' => $contentReasons,
            ];
        }

        if ($cases !== []) {
            $property->publication_status = self::PUBLICATION_PENDING;
            $property->moderation_status = 'pending';
            $this->suspendPromotions($property, $actor);
        } elseif ($wasPublished && array_intersect($dirty, self::PRICE_FIELDS) !== []) {
            $property->forceFill($this->approvedPricePayload($property->getAttributes()));
            $property->approved_content_snapshot = array_merge(
                (array) $property->approved_content_snapshot,
                $property->only(self::PRICE_FIELDS),
                ['effective_price' => $this->effectivePrice($property->getAttributes())],
            );
        }

        if ($dirty !== []) {
            $property->moderation_version = (int) $property->getOriginal('moderation_version') + 1;
        }

        return [
            'cases' => $cases,
            'duplicates' => $duplicates,
            'withdraw_case_types' => $withdrawCaseTypes,
            'event' => $cases !== [] ? 'property_sent_to_moderation' : ($dirty !== [] ? 'property_updated_without_review' : null),
        ];
    }

    /** @param array{cases:array<int,array<string,mixed>>,duplicates:Collection<int,array<string,mixed>>,withdraw_case_types:array<int,string>,event:string|null} $outcome */
    public function recordUpdateOutcome(Property $property, User $actor, array $outcome): void
    {
        if (($outcome['withdraw_case_types'] ?? []) !== []) {
            PropertyModerationCase::query()
                ->where('property_id', $property->id)
                ->whereIn('type', $outcome['withdraw_case_types'])
                ->open()
                ->update([
                    'status' => PropertyModerationCase::STATUS_WITHDRAWN,
                    'blocking' => false,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'decision_comment' => 'Предложение возвращено к одобренной цене или ниже.',
                    'updated_at' => now(),
                ]);
        }

        foreach ($outcome['cases'] as $caseData) {
            $case = $this->upsertOpenCase(
                $property,
                $caseData['type'],
                $actor,
                $caseData['baseline_snapshot'] ?? $property->approved_content_snapshot,
                $caseData['proposed_snapshot'] ?? $this->contentSnapshot($property),
                $caseData['reason_codes'] ?? []
            );

            if ($case->type === PropertyModerationCase::TYPE_DUPLICATE) {
                $this->syncDuplicateCandidates($case, $outcome['duplicates']);
            }
            $this->event($property, $case, match ($case->type) {
                PropertyModerationCase::TYPE_PRICE_INCREASE => 'price_review_opened',
                PropertyModerationCase::TYPE_DUPLICATE => 'duplicate_review_opened',
                default => 'content_review_opened',
            }, $actor);
        }

        if (($outcome['withdraw_case_types'] ?? []) !== [] && $outcome['cases'] === [] && ! $this->hasOpenBlockingCases($property) && ! $this->hasConfirmedDuplicate($property)) {
            $property->forceFill(array_merge([
                'publication_status' => self::PUBLICATION_PUBLISHED,
                'moderation_status' => Property::PUBLIC_MODERATION_STATUS,
                'approved_content_snapshot' => $this->contentSnapshot($property),
            ], $this->approvedPricePayload($property->getAttributes())))->saveQuietly();
        }

        $updatedTypes = array_column($outcome['cases'], 'type');
        if ($outcome['event']) {
            foreach ($property->moderationCases()->open()->whereNotIn('type', $updatedTypes)->get() as $pendingCase) {
                $pendingCase->update(['version' => $pendingCase->version + 1]);
                $this->event($property, $pendingCase, 'case_proposal_edited', $actor, [], false);
            }
        }

        if ($outcome['event']) {
            $this->event($property, null, $outcome['event'], $actor, [
                'case_types' => array_values(array_unique(array_column($outcome['cases'], 'type'))),
            ], $outcome['cases'] === []);
        }
    }

    public function approveCase(PropertyModerationCase $case, User $actor, ?string $comment = null, ?int $expectedVersion = null): Property
    {
        return DB::transaction(function () use ($case, $actor, $comment, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($case->property_id);
            $lockedCase = PropertyModerationCase::query()->lockForUpdate()->findOrFail($case->id);
            $lockedCase->setRelation('property', $property);

            abort_unless($lockedCase->status === PropertyModerationCase::STATUS_OPEN, 409, 'MODERATION_CASE_NOT_OPEN');
            abort_if($expectedVersion !== null && $lockedCase->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            if ($lockedCase->type === PropertyModerationCase::TYPE_APPEAL) {
                abort_if((int) $lockedCase->parentCase()->value('decided_by') === (int) $actor->id
                    || PropertyDuplicateCandidate::query()->where('moderation_case_id', $lockedCase->parent_case_id)->where('decided_by', $actor->id)->exists(), 403, 'APPEAL_REVIEWER_CONFLICT');
            }
            abort_unless($this->access->canDecideCase($actor, $lockedCase), 403, 'SELF_APPROVAL_FORBIDDEN');
            if ($lockedCase->type === PropertyModerationCase::TYPE_APPEAL) {
                $this->reverseDuplicateConfirmationForAppeal($lockedCase, $property, $actor, $comment);
            } else {
                abort_if($this->hasConfirmedDuplicate($property), 409, 'DUPLICATE_BLOCK_ACTIVE');
            }
            if ($lockedCase->type === PropertyModerationCase::TYPE_DUPLICATE) {
                abort_if($lockedCase->duplicateCandidates()->where('decision', PropertyDuplicateCandidate::DECISION_PENDING)->exists(), 409, 'DUPLICATE_CANDIDATES_NOT_RESOLVED');
            }

            $lockedCase->update([
                'status' => PropertyModerationCase::STATUS_APPROVED,
                'blocking' => false,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'version' => $lockedCase->version + 1,
            ]);

            if (! $this->hasOpenBlockingCases($property)) {
                $property->forceFill(array_merge([
                    'publication_status' => self::PUBLICATION_PUBLISHED,
                    'moderation_status' => Property::PUBLIC_MODERATION_STATUS,
                    'moderation_version' => (int) $property->moderation_version + 1,
                    'approved_content_snapshot' => $this->contentSnapshot($property),
                ], $this->approvedPricePayload($property->getAttributes(), $actor->id)))->save();
            }

            $this->event($property, $lockedCase, 'moderation_case_approved', $actor, ['comment' => $comment]);

            return $property->fresh();
        });
    }

    public function rejectCase(
        PropertyModerationCase $case,
        User $actor,
        string $comment,
        ?int $expectedVersion = null,
        string $action = 'keep_hidden',
        bool $confirmedViolation = false,
    ): Property {
        abort_unless(in_array($action, ['keep_hidden', 'restore_and_publish'], true), 422);

        return DB::transaction(function () use ($case, $actor, $comment, $expectedVersion, $action, $confirmedViolation): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($case->property_id);
            $lockedCase = PropertyModerationCase::query()->lockForUpdate()->findOrFail($case->id);
            $lockedCase->setRelation('property', $property);

            abort_unless($lockedCase->status === PropertyModerationCase::STATUS_OPEN, 409, 'MODERATION_CASE_NOT_OPEN');
            abort_if($expectedVersion !== null && $lockedCase->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            if ($lockedCase->type === PropertyModerationCase::TYPE_APPEAL) {
                abort_if((int) $lockedCase->parentCase()->value('decided_by') === (int) $actor->id
                    || PropertyDuplicateCandidate::query()->where('moderation_case_id', $lockedCase->parent_case_id)->where('decided_by', $actor->id)->exists(), 403, 'APPEAL_REVIEWER_CONFLICT');
            }
            abort_unless($this->access->canDecideCase($actor, $lockedCase), 403, 'SELF_APPROVAL_FORBIDDEN');

            $lockedCase->update([
                'status' => PropertyModerationCase::STATUS_REJECTED,
                'blocking' => $action === 'keep_hidden',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'version' => $lockedCase->version + 1,
            ]);
            if ($action === 'restore_and_publish') {
                abort_if($this->hasConfirmedDuplicate($property), 409, 'DUPLICATE_BLOCK_ACTIVE');
                $snapshot = (array) $property->approved_content_snapshot;
                abort_if($snapshot === [], 409, 'APPROVED_SNAPSHOT_MISSING');
                abort_if($this->hasOpenBlockingCases($property), 409, 'OPEN_BLOCKING_CASES');
                $property->fill(array_intersect_key($snapshot, array_flip(Property::LISTING_CONTENT_FIELDS)));
                $this->restoreApprovedPhotos($property, $snapshot);
                $this->restoreApprovedRelations($property, $snapshot);
                $property->forceFill([
                    'publication_status' => self::PUBLICATION_PUBLISHED,
                    'moderation_status' => Property::PUBLIC_MODERATION_STATUS,
                    'moderation_version' => (int) $property->moderation_version + 1,
                ])->save();
            } else {
                $property->forceFill([
                    'publication_status' => self::PUBLICATION_REJECTED,
                    'moderation_status' => 'rejected',
                    'moderation_version' => (int) $property->moderation_version + 1,
                ])->save();
                $this->suspendPromotions($property, $actor);
            }
            $this->event($property, $lockedCase, 'moderation_case_rejected', $actor, [
                'comment' => $comment,
                'action' => $action,
                'confirmed_violation' => $confirmedViolation,
            ]);
            if ($confirmedViolation && $lockedCase->type === PropertyModerationCase::TYPE_PRICE_INCREASE) {
                $this->trustEvent($lockedCase, $actor, 'confirmed_price_manipulation', $comment);
            }

            return $property->fresh();
        });
    }

    public function breakGlassApprove(PropertyModerationCase $case, User $actor, string $reason, int $expectedVersion): Property
    {
        abort_unless($actor->role?->slug === 'superadmin', 403, 'BREAK_GLASS_FORBIDDEN');

        return DB::transaction(function () use ($case, $actor, $reason, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($case->property_id);
            $lockedCase = PropertyModerationCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($lockedCase->status === PropertyModerationCase::STATUS_OPEN, 409, 'MODERATION_CASE_NOT_OPEN');
            abort_if($lockedCase->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_if($this->hasConfirmedDuplicate($property), 409, 'DUPLICATE_BLOCK_ACTIVE');
            abort_if($lockedCase->type === PropertyModerationCase::TYPE_DUPLICATE && $lockedCase->duplicateCandidates()->where('decision', PropertyDuplicateCandidate::DECISION_PENDING)->exists(), 409, 'DUPLICATE_REVIEW_REQUIRED');

            $lockedCase->update([
                'status' => PropertyModerationCase::STATUS_APPROVED,
                'blocking' => false,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => 'BREAK GLASS: '.$reason,
                'version' => $lockedCase->version + 1,
            ]);
            if (! $this->hasOpenBlockingCases($property)) {
                $property->forceFill(array_merge([
                    'publication_status' => self::PUBLICATION_PUBLISHED,
                    'moderation_status' => Property::PUBLIC_MODERATION_STATUS,
                    'approved_content_snapshot' => $this->contentSnapshot($property),
                    'moderation_version' => (int) $property->moderation_version + 1,
                ], $this->approvedPricePayload($property->getAttributes(), $actor->id)))->save();
            }
            $this->event($property, $lockedCase, 'moderation_break_glass_approved', $actor, ['reason' => $reason]);

            return $property->fresh();
        });
    }

    public function withdrawChanges(Property $property, User $actor, int $expectedVersion): Property
    {
        abort_unless($this->access->canEdit($actor, $property), 403, 'Доступ запрещён');

        return DB::transaction(function () use ($property, $actor, $expectedVersion): Property {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            abort_if(
                Schema::hasColumn('properties', 'moderation_version')
                    && (int) $locked->moderation_version !== $expectedVersion,
                409,
                'MODERATION_VERSION_CONFLICT'
            );
            abort_if($this->hasConfirmedDuplicate($locked), 409, 'MODERATION_WITHDRAW_NOT_ALLOWED');

            $snapshot = (array) $locked->approved_content_snapshot;
            $this->withdrawOpenCases($locked, $actor, PropertyModerationCase::STATUS_WITHDRAWN, $snapshot !== []);
            if ($snapshot === []) {
                $locked->forceFill([
                    'publication_status' => self::PUBLICATION_DRAFT,
                    'moderation_status' => 'draft',
                    'moderation_version' => (int) $locked->moderation_version + 1,
                ])->save();
            } else {
                $locked->fill(array_intersect_key($snapshot, array_flip(Property::LISTING_CONTENT_FIELDS)));
                $this->restoreApprovedPhotos($locked, $snapshot);
                $this->restoreApprovedRelations($locked, $snapshot);
                $locked->forceFill([
                    'publication_status' => $this->hasOpenBlockingCases($locked) ? self::PUBLICATION_PENDING : self::PUBLICATION_PUBLISHED,
                    'moderation_status' => $this->hasOpenBlockingCases($locked) ? 'pending' : Property::PUBLIC_MODERATION_STATUS,
                    'moderation_version' => (int) $locked->moderation_version + 1,
                ])->save();
            }

            $this->event($locked, null, 'moderation_changes_withdrawn', $actor, ['restored' => $snapshot !== []]);

            return $locked->fresh();
        });
    }

    public function submit(Property $property, User $actor, array $qualityWarnings = [], ?int $expectedVersion = null): Property
    {
        abort_unless($this->access->canEdit($actor, $property), 403, 'MODERATION_PERMISSION_DENIED');

        return DB::transaction(function () use ($property, $actor, $qualityWarnings, $expectedVersion): Property {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            abort_if($expectedVersion !== null && (int) $locked->moderation_version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_if($this->hasConfirmedDuplicate($locked), 409, 'DUPLICATE_BLOCK_ACTIVE');

            $locked->moderationCases()->where('status', PropertyModerationCase::STATUS_REJECTED)
                ->where('blocking', true)->update([
                    'status' => PropertyModerationCase::STATUS_OPEN,
                    'version' => DB::raw('version + 1'),
                ]);
            if ($this->hasOpenBlockingCases($locked)) {
                $locked->forceFill(['moderation_version' => (int) $locked->moderation_version + 1, 'publication_status' => self::PUBLICATION_PENDING, 'moderation_status' => 'pending'])->save();

                $this->suspendPromotions($locked, $actor);

                return $locked->fresh();
            }

            $duplicates = $this->duplicates->find($locked->getAttributes(), (int) $locked->id);
            $hasApprovedSnapshot = (array) $locked->approved_content_snapshot !== [];
            $requiresReview = $duplicates->isNotEmpty() || $qualityWarnings !== [] || $hasApprovedSnapshot;
            if ($requiresReview) {
                $this->suspendPromotions($locked, $actor);
                $locked->forceFill([
                    'publication_status' => self::PUBLICATION_PENDING,
                    'moderation_status' => 'pending',
                    'moderation_version' => (int) $locked->moderation_version + 1,
                ])->save();
                if ($duplicates->isNotEmpty()) {
                    $case = $this->upsertOpenCase($locked, PropertyModerationCase::TYPE_DUPLICATE, $actor, $locked->approved_content_snapshot, $this->contentSnapshot($locked), ['duplicate_candidates']);
                    $this->syncDuplicateCandidates($case, $duplicates);
                }
                if ($qualityWarnings !== [] || $hasApprovedSnapshot) {
                    $codes = $hasApprovedSnapshot
                        ? ['resubmitted_after_withdrawal']
                        : collect($qualityWarnings)->pluck('code')->filter()->map(fn ($code) => 'quality:'.$code)->values()->all();
                    $this->upsertOpenCase($locked, PropertyModerationCase::TYPE_INITIAL, $actor, $locked->approved_content_snapshot, $this->contentSnapshot($locked), $codes);
                }
            } else {
                $locked->forceFill(array_merge([
                    'publication_status' => self::PUBLICATION_PUBLISHED,
                    'moderation_status' => Property::PUBLIC_MODERATION_STATUS,
                    'approved_content_snapshot' => $this->contentSnapshot($locked),
                    'moderation_version' => (int) $locked->moderation_version + 1,
                ], $this->approvedPricePayload($locked->getAttributes())))->save();
            }
            $this->event($locked, null, $requiresReview ? 'property_submitted_to_moderation' : 'property_auto_published', $actor);

            return $locked->fresh();
        });
    }

    public function withdrawListing(Property $property, User $actor, string $target, ?int $expectedVersion = null): Property
    {
        abort_unless($this->access->canEdit($actor, $property), 403, 'Доступ запрещён');
        abort_unless(in_array($target, [self::PUBLICATION_DRAFT, self::PUBLICATION_ARCHIVED], true), 422);

        return DB::transaction(function () use ($property, $actor, $target, $expectedVersion): Property {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            abort_if($expectedVersion !== null && (int) $locked->moderation_version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            $locked->forceFill([
                'publication_status' => $target,
                'moderation_status' => $target === self::PUBLICATION_DRAFT ? 'draft' : 'deleted',
                'moderation_version' => (int) $locked->moderation_version + 1,
            ])->save();
            $this->suspendPromotions($locked, $actor);
            $this->withdrawOpenCases($locked, $actor, PropertyModerationCase::STATUS_WITHDRAWN_BY_AUTHOR);
            $this->event($locked, null, 'property_withdrawn', $actor, ['target' => $target]);

            return $locked->fresh();
        });
    }

    public function withdrawCase(
        PropertyModerationCase $case,
        User $actor,
        ?int $expectedVersion = null,
    ): Property {
        $case->loadMissing('property');
        abort_unless($this->access->canEdit($actor, $case->property), 403, 'MODERATION_PERMISSION_DENIED');

        return DB::transaction(function () use ($case, $actor, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($case->property_id);
            $lockedCase = PropertyModerationCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($lockedCase->status === PropertyModerationCase::STATUS_OPEN, 409, 'MODERATION_CASE_NOT_OPEN');
            abort_if(in_array($lockedCase->type, [PropertyModerationCase::TYPE_DUPLICATE, PropertyModerationCase::TYPE_APPEAL], true), 409, 'MODERATION_WITHDRAW_NOT_ALLOWED');
            abort_if($this->hasConfirmedDuplicate($property), 409, 'MODERATION_WITHDRAW_NOT_ALLOWED');
            abort_if($expectedVersion !== null && $lockedCase->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_if($lockedCase->duplicateCandidates()
                ->where('decision', PropertyDuplicateCandidate::DECISION_CONFIRMED)
                ->whereNull('reversed_at')
                ->exists(), 409, 'MODERATION_WITHDRAW_NOT_ALLOWED');

            $lockedCase->update([
                'status' => PropertyModerationCase::STATUS_WITHDRAWN,
                'blocking' => false,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => 'Кейс отозван автором изменения.',
                'version' => $lockedCase->version + 1,
            ]);

            $snapshot = (array) $property->approved_content_snapshot;
            if ($snapshot !== []) {
                $property->fill(array_intersect_key($snapshot, array_flip(Property::LISTING_CONTENT_FIELDS)));
                $this->restoreApprovedPhotos($property, $snapshot);
                $this->restoreApprovedRelations($property, $snapshot);
            }
            $blocked = $this->hasOpenBlockingCases($property);
            $property->forceFill([
                'publication_status' => $blocked ? self::PUBLICATION_PENDING : ($snapshot === [] ? self::PUBLICATION_DRAFT : self::PUBLICATION_PUBLISHED),
                'moderation_status' => $blocked ? 'pending' : ($snapshot === [] ? 'draft' : Property::PUBLIC_MODERATION_STATUS),
                'moderation_version' => (int) $property->moderation_version + 1,
            ])->save();
            foreach ($property->moderationCases()->open()->get() as $remaining) {
                $remaining->update([
                    'proposed_snapshot' => $this->contentSnapshot($property),
                    'version' => $remaining->version + 1,
                ]);
            }
            $this->event($property, $lockedCase, 'moderation_case_withdrawn', $actor);

            return $property->fresh();
        });
    }

    public function transfer(Property $property, User $actor, array $changes, string $reason, int $expectedVersion): Property
    {
        $changesOnlyCoOwner = array_keys($changes) === ['co_owner_user_id'];
        abort_unless(
            $changesOnlyCoOwner
                ? $this->access->canEdit($actor, $property)
                : $this->access->canModerate($actor, $property),
            403,
            'MODERATION_PERMISSION_DENIED'
        );

        return DB::transaction(function () use ($property, $actor, $changes, $reason, $expectedVersion, $changesOnlyCoOwner): Property {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            abort_if(
                Schema::hasColumn('properties', 'moderation_version')
                    && (int) $locked->moderation_version !== $expectedVersion,
                409,
                'MODERATION_VERSION_CONFLICT'
            );
            abort_unless(
                $changesOnlyCoOwner
                    ? $this->access->canEdit($actor, $locked)
                    : $this->access->canModerate($actor, $locked),
                403,
                'MODERATION_PERMISSION_DENIED'
            );

            $old = $locked->only(['agent_id', 'co_owner_user_id']);
            foreach (['agent_id', 'co_owner_user_id'] as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }
                $targetId = $changes[$field] !== null ? (int) $changes[$field] : null;
                if ($targetId !== null) {
                    $target = User::query()->where('status', User::STATUS_ACTIVE)->findOrFail($targetId);
                    $role = (string) $actor->role?->slug;
                    if (! in_array($role, config('property-moderation.global_moderator_roles', []), true)) {
                        abort_unless((int) $target->branch_id === (int) $actor->branch_id, 403, 'MODERATION_PERMISSION_DENIED');
                    }
                    if ($field === 'co_owner_user_id') {
                        abort_if(in_array($targetId, array_filter([
                            (int) $locked->created_by,
                            (int) $locked->agent_id,
                        ]), true), 422, 'INVALID_PROPERTY_OWNERSHIP');
                    }
                }
                $locked->{$field} = $targetId;
            }
            abort_if($locked->agent_id && (int) $locked->agent_id === (int) $locked->co_owner_user_id, 422, 'INVALID_PROPERTY_OWNERSHIP');
            if (Schema::hasColumn('properties', 'moderation_version')) {
                $locked->moderation_version = (int) $locked->moderation_version + 1;
            }
            $locked->save();
            $this->event($locked, null, 'property_transferred', $actor, [
                'reason' => $reason,
                'old' => $old,
                'new' => $locked->only(['agent_id', 'co_owner_user_id']),
            ]);

            return $locked->fresh();
        });
    }

    public function decideDuplicate(
        PropertyDuplicateCandidate $candidate,
        User $actor,
        string $decision,
        string $comment,
        ?int $expectedVersion = null,
    ): Property {
        abort_unless(in_array($decision, [PropertyDuplicateCandidate::DECISION_NOT_DUPLICATE, PropertyDuplicateCandidate::DECISION_CONFIRMED], true), 422);

        return DB::transaction(function () use ($candidate, $actor, $decision, $comment, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($candidate->moderationCase()->value('property_id'));
            $case = PropertyModerationCase::query()->lockForUpdate()->findOrFail($candidate->moderation_case_id);
            $candidate = PropertyDuplicateCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $case->setRelation('property', $property);
            abort_unless($case->status === PropertyModerationCase::STATUS_OPEN, 409, 'MODERATION_CASE_NOT_OPEN');
            abort_if($expectedVersion !== null && $case->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canDecideCase($actor, $case), 403, 'SELF_APPROVAL_FORBIDDEN');

            $candidate->update([
                'decision' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'comment' => $comment,
            ]);
            $case->increment('version');
            $case->refresh();

            if ($decision === PropertyDuplicateCandidate::DECISION_CONFIRMED) {
                $property->forceFill([
                    'publication_status' => self::PUBLICATION_REJECTED,
                    'moderation_status' => 'rejected',
                    'duplicate_of_property_id' => $candidate->candidate_property_id,
                    'moderation_version' => (int) $property->moderation_version + 1,
                ])->save();
                $this->suspendPromotions($property, $actor);
                $case->update([
                    'status' => PropertyModerationCase::STATUS_REJECTED,
                    'blocking' => false,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'decision_comment' => $comment,
                    'version' => $case->version + 1,
                ]);
                $this->trustEvent($case, $actor, 'confirmed_duplicate', $comment);
                $this->event($property, $case, 'duplicate_confirmed', $actor, ['candidate_property_id' => $candidate->candidate_property_id]);
            } elseif (! $case->duplicateCandidates()->where('decision', PropertyDuplicateCandidate::DECISION_PENDING)->exists()) {
                return $this->approveCase($case, $actor, $comment);
            }

            return $property->fresh();
        });
    }

    public function appeal(PropertyModerationCase $case, User $actor, string $comment, int $expectedVersion): PropertyModerationCase
    {
        return DB::transaction(function () use ($case, $actor, $comment, $expectedVersion): PropertyModerationCase {
            $property = Property::query()->lockForUpdate()->findOrFail($case->property_id);
            $case = PropertyModerationCase::query()->lockForUpdate()->findOrFail($case->id);
            $case->setRelation('property', $property);
            abort_unless($this->access->canEdit($actor, $case->property), 403, 'Доступ запрещён');
            abort_unless(in_array($case->status, [PropertyModerationCase::STATUS_REJECTED, PropertyModerationCase::STATUS_MERGED], true), 409, 'CASE_NOT_APPEALABLE');
            abort_if((int) $case->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_if(PropertyModerationCase::query()
                ->where('parent_case_id', $case->id)
                ->where('type', PropertyModerationCase::TYPE_APPEAL)
                ->open()
                ->exists(), 409, 'APPEAL_ALREADY_OPEN');

            $appeal = PropertyModerationCase::create([
                'property_id' => $case->property_id,
                'type' => PropertyModerationCase::TYPE_APPEAL,
                'status' => PropertyModerationCase::STATUS_OPEN,
                'blocking' => true,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'baseline_snapshot' => $case->baseline_snapshot,
                'proposed_snapshot' => $case->proposed_snapshot,
                'reason_codes' => ['appeal'],
                'parent_case_id' => $case->id,
                'decision_comment' => $comment,
                'version' => 1,
            ]);
            $property->forceFill(['moderation_version' => (int) $property->moderation_version + 1])->save();
            $this->suspendPromotions($property, $actor);
            $this->event($case->property, $appeal, 'moderation_appealed', $actor, ['comment' => $comment]);

            return $appeal;
        });
    }

    public function mergeDuplicate(PropertyDuplicateCandidate $candidate, User $actor, string $comment, ?int $expectedVersion = null): Property
    {
        return DB::transaction(function () use ($candidate, $actor, $comment, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($candidate->moderationCase()->value('property_id'));
            $case = PropertyModerationCase::query()->lockForUpdate()->findOrFail($candidate->moderation_case_id);
            $candidate = PropertyDuplicateCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $case->setRelation('property', $property);
            abort_unless(in_array($case->status, [
                PropertyModerationCase::STATUS_OPEN,
                PropertyModerationCase::STATUS_REJECTED,
            ], true), 409, 'MODERATION_CASE_NOT_MERGEABLE');
            abort_if($expectedVersion !== null && $case->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canDecideCase($actor, $case), 403, 'SELF_APPROVAL_FORBIDDEN');

            $newConfirmation = $candidate->decision !== PropertyDuplicateCandidate::DECISION_CONFIRMED;
            if (! $newConfirmation) {
                abort_if($candidate->reversed_at !== null, 409, 'DUPLICATE_CONFIRMATION_NOT_ACTIVE');
            }

            if ($newConfirmation) {
                $candidate->update(['decision' => PropertyDuplicateCandidate::DECISION_CONFIRMED, 'decided_by' => $actor->id, 'decided_at' => now(), 'comment' => $comment]);
            }
            $case->update([
                'status' => PropertyModerationCase::STATUS_MERGED,
                'blocking' => false,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'version' => $case->version + 1,
            ]);
            $transferredRelations = $this->transferMergeRelations(
                (int) $property->id,
                (int) $candidate->candidate_property_id,
            );
            $property->forceFill([
                'publication_status' => self::PUBLICATION_ARCHIVED,
                'moderation_status' => 'deleted',
                'duplicate_of_property_id' => $candidate->candidate_property_id,
                'moderation_version' => (int) $property->moderation_version + 1,
            ])->save();
            $this->suspendPromotions($property, $actor);
            if ($newConfirmation) {
                $this->trustEvent($case, $actor, 'confirmed_duplicate', $comment);
            }
            $this->event($property, $case, 'duplicate_merged', $actor, [
                'canonical_property_id' => $candidate->candidate_property_id,
                'transferred_relations' => $transferredRelations,
            ]);

            return $property->fresh();
        });
    }

    public function rejectDuplicate(PropertyDuplicateCandidate $candidate, User $actor, string $comment, ?int $expectedVersion = null): Property
    {
        return DB::transaction(function () use ($candidate, $actor, $comment, $expectedVersion): Property {
            $property = Property::query()->lockForUpdate()->findOrFail($candidate->moderationCase()->value('property_id'));
            $case = PropertyModerationCase::query()->lockForUpdate()->findOrFail($candidate->moderation_case_id);
            $candidate = PropertyDuplicateCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $case->setRelation('property', $property);
            $case->setRelation('property', $property);
            abort_unless($candidate->decision === PropertyDuplicateCandidate::DECISION_CONFIRMED && ! $candidate->reversed_at, 409, 'DUPLICATE_CONFIRMATION_NOT_ACTIVE');
            abort_if($expectedVersion !== null && $case->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canDecideCase($actor, $case), 403, 'SELF_APPROVAL_FORBIDDEN');

            $property->forceFill([
                'publication_status' => self::PUBLICATION_ARCHIVED,
                'moderation_status' => 'deleted',
                'duplicate_of_property_id' => $candidate->candidate_property_id,
                'moderation_version' => (int) $property->moderation_version + 1,
            ])->save();
            $this->suspendPromotions($property, $actor);
            $this->event($property, $case, 'duplicate_rejected', $actor, [
                'canonical_property_id' => $candidate->candidate_property_id,
                'comment' => $comment,
            ]);

            return $property->fresh();
        });
    }

    public function publicOrFail(Property $property, ?User $viewer): void
    {
        if ($this->isPublic($property)) {
            return;
        }

        if ($viewer && $this->access->canEdit($viewer, $property)) {
            return;
        }

        abort(404);
    }

    public function isPublic(Property $property): bool
    {
        return Property::query()->publicSearchable()->whereKey($property->id)->exists();
    }

    public function auditPromotionEvent(Property $property, ?User $actor, string $event, array $payload = []): void
    {
        $this->event($property, null, $event, $actor, $payload, false);
    }

    public function auditPropertyEvent(Property $property, ?User $actor, string $event, array $payload = []): void
    {
        $this->event($property, null, $event, $actor, $payload, false);
    }

    public function handleMediaMutation(Property $property, User $actor, array $details): void
    {
        if (! $this->workflowAvailable()) {
            return;
        }

        DB::transaction(function () use ($property, $actor, $details): void {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $hasApprovedSnapshot = (array) $locked->approved_content_snapshot !== [];
            $baseline = (array) $locked->approved_content_snapshot;
            $proposed = $this->contentSnapshot($locked);
            if ($this->publicationStatus($locked) === self::PUBLICATION_PUBLISHED || $hasApprovedSnapshot) {
                $locked->forceFill([
                    'publication_status' => self::PUBLICATION_PENDING,
                    'moderation_status' => 'pending',
                    'moderation_version' => (int) $locked->moderation_version + 1,
                ])->save();
                $this->suspendPromotions($locked, $actor);
                $mediaCase = $this->upsertOpenCase(
                    $locked,
                    PropertyModerationCase::TYPE_CONTENT,
                    $actor,
                    $baseline,
                    $proposed,
                    ['changed:'.($details['action'] ?? 'photos')]
                );
            }
            $this->event($locked, $mediaCase ?? null, 'property_media_changed', $actor, array_merge($details, [
                'baseline_snapshot' => $baseline,
                'proposed_snapshot' => $proposed,
            ]));
        });
    }

    private function approvedPricePayload(array $attributes, ?int $approvedBy = null): array
    {
        $price = (float) ($attributes['price'] ?? 0);
        $discount = isset($attributes['discount_price']) && (float) $attributes['discount_price'] > 0
            ? (float) $attributes['discount_price']
            : null;

        return [
            'approved_price' => $price,
            'approved_discount_price' => $discount,
            'approved_effective_price' => $discount ?? $price,
            'approved_currency' => $attributes['currency'] ?? null,
            'price_approved_at' => now(),
            'price_approved_by' => $approvedBy,
        ];
    }

    public function photoSnapshot(Property $property): array
    {
        return $property->photos()->orderBy('position')->orderBy('id')->get()->map(function ($photo): array {
            $path = \Storage::disk('public')->path($photo->file_path);

            return [
                'id' => (int) $photo->id,
                'file_path' => $photo->file_path,
                'hash' => is_file($path) ? hash_file('sha256', $path) : null,
                'position' => (int) $photo->position,
            ];
        })->all();
    }

    private function contentSnapshot(Property $property): array
    {
        $fields = array_values(array_unique(array_merge(Property::LISTING_CONTENT_FIELDS, [
            'owner_phone', 'owner_name', 'owner_client_id',
        ])));
        $snapshot = array_intersect_key($property->getAttributes(), array_flip($fields));
        $snapshot['effective_price'] = $this->effectivePrice($property->getAttributes());
        $snapshot['photos'] = $this->photoSnapshot($property);
        $snapshot['photo_ids'] = array_column($snapshot['photos'], 'id');
        if (Schema::hasTable('feature_property')) {
            $snapshot['feature_ids'] = $property->features()->pluck('features.id')->map(fn ($id) => (int) $id)->values()->all();
        }
        if (Schema::hasTable('property_tag')) {
            $snapshot['tag_ids'] = $property->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return $snapshot;
    }

    private function requiresPriceReview(Property $property, array $dirty): ?array
    {
        if (array_intersect($dirty, self::PRICE_FIELDS) === []) {
            return null;
        }

        $approvedPrice = $property->approved_effective_price;
        $approvedCurrency = $property->approved_currency;
        if ($approvedPrice === null || $approvedCurrency === null) {
            return null;
        }

        $proposed = [
            'price' => (float) $property->price,
            'discount_price' => $property->discount_price !== null ? (float) $property->discount_price : null,
            'effective_price' => $this->effectivePrice($property->getAttributes()),
            'currency' => $property->currency,
        ];
        $baseline = [
            'price' => (float) $property->approved_price,
            'discount_price' => $property->approved_discount_price !== null ? (float) $property->approved_discount_price : null,
            'effective_price' => (float) $approvedPrice,
            'currency' => $approvedCurrency,
        ];
        $reasons = [];

        if ($proposed['currency'] !== $approvedCurrency) {
            $reasons[] = 'currency_changed';
        } else {
            $threshold = max(0.0, (float) config('property-moderation.price_increase_review_percent', 0));
            $limit = (float) $approvedPrice * (1 + $threshold / 100);
            if ($proposed['effective_price'] > $limit) {
                $reasons[] = 'effective_price_increased';
            }
            if (
                ($property->approved_discount_price !== null || $proposed['discount_price'] !== null)
                && $proposed['price'] > (float) $property->approved_price
            ) {
                $reasons[] = 'display_price_increased';
            }
        }

        $proposed['absolute_difference'] = $proposed['currency'] === $approvedCurrency
            ? $proposed['effective_price'] - (float) $approvedPrice
            : null;
        $proposed['percent_difference'] = $proposed['currency'] === $approvedCurrency && (float) $approvedPrice > 0
            ? round((($proposed['effective_price'] - (float) $approvedPrice) / (float) $approvedPrice) * 100, 2)
            : null;

        return $reasons === [] ? null : compact('baseline', 'proposed', 'reasons');
    }

    private function effectivePrice(array $attributes): float
    {
        $discount = isset($attributes['discount_price']) ? (float) $attributes['discount_price'] : 0.0;

        return $discount > 0 ? $discount : (float) ($attributes['price'] ?? 0);
    }

    /** @return list<string> */
    private function substantialRiskReasons(Property $property, array $dirty): array
    {
        $reasons = [];
        foreach (array_intersect($dirty, self::RISK_FIELDS) as $field) {
            $baseline = (array) $property->approved_content_snapshot;
            $old = trim(mb_strtolower((string) ($baseline[$field] ?? $property->getOriginal($field))));
            $new = trim(mb_strtolower((string) $property->{$field}));
            if (in_array($field, ['title', 'description', 'landmark', 'condition'], true)) {
                $maxLength = max(mb_strlen($old), mb_strlen($new), 1);
                $distanceRatio = levenshtein($old, $new) / max(strlen($old), strlen($new), 1);
                $lengthRatio = abs(mb_strlen($old) - mb_strlen($new)) / $maxLength;
                if ($old !== '' && $new !== '' && $distanceRatio < 0.35 && $lengthRatio < 0.35) {
                    continue;
                }
            }
            $reasons[] = 'risk_changed:'.$field;
        }

        return $reasons;
    }

    private function restoreApprovedPhotos(Property $property, array $snapshot): void
    {
        if (! array_key_exists('photos', $snapshot) || ! is_array($snapshot['photos'])) {
            return;
        }

        $approved = collect($snapshot['photos'])->filter(fn ($photo) => is_array($photo) && ! empty($photo['file_path']));
        $approvedPaths = $approved->pluck('file_path')->all();
        $property->photos()->whereNotIn('file_path', $approvedPaths ?: [''])->delete();
        foreach ($approved as $index => $photo) {
            $property->photos()->updateOrCreate(
                ['file_path' => $photo['file_path']],
                ['position' => (int) ($photo['position'] ?? $index)]
            );
        }
    }

    private function restoreApprovedRelations(Property $property, array $snapshot): void
    {
        if (array_key_exists('feature_ids', $snapshot) && Schema::hasTable('feature_property')) {
            $property->features()->sync(array_map('intval', (array) $snapshot['feature_ids']));
        }
        if (array_key_exists('tag_ids', $snapshot) && Schema::hasTable('property_tag')) {
            $property->tags()->sync(array_map('intval', (array) $snapshot['tag_ids']));
        }
    }

    private function suspendPromotions(Property $property, ?User $actor = null): void
    {
        if (! Schema::hasTable('property_promotions')) {
            return;
        }

        $promotions = $property->promotions()
            ->where('status', PropertyPromotion::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();
        foreach ($promotions as $promotion) {
            $promotion->update([
                'status' => PropertyPromotion::STATUS_SUSPENDED,
                'version' => (int) $promotion->version + 1,
            ]);
            app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'suspended', $actor);
            $this->event($property, null, 'property_promotion_suspended', $actor, [
                'promotion_id' => $promotion->id,
                'promotion_type' => $promotion->type,
            ], false);
        }
    }

    private function upsertOpenCase(
        Property $property,
        string $type,
        User $actor,
        ?array $baseline,
        ?array $proposed,
        array $reasonCodes,
    ): PropertyModerationCase {
        $case = PropertyModerationCase::query()
            ->where('property_id', $property->id)
            ->where('type', $type)
            ->open()
            ->lockForUpdate()
            ->first();

        if ($case) {
            $case->update([
                'proposed_snapshot' => $proposed,
                'reason_codes' => array_values(array_unique(array_merge($case->reason_codes ?? [], $reasonCodes))),
                'version' => $case->version + 1,
            ]);

            return $case;
        }

        return PropertyModerationCase::create([
            'property_id' => $property->id,
            'type' => $type,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
            'baseline_snapshot' => $baseline,
            'proposed_snapshot' => $proposed,
            'reason_codes' => $reasonCodes,
            'version' => 1,
        ]);
    }

    /** @param Collection<int, array<string, mixed>> $duplicates */
    private function syncDuplicateCandidates(PropertyModerationCase $case, Collection $duplicates): void
    {
        foreach ($duplicates as $duplicate) {
            PropertyDuplicateCandidate::query()->firstOrCreate(
                [
                    'moderation_case_id' => $case->id,
                    'candidate_property_id' => (int) $duplicate['id'],
                ],
                [
                    'score' => (float) ($duplicate['score'] ?? 0),
                    'signals' => $duplicate['signals'] ?? [],
                    'candidate_snapshot' => $duplicate,
                ]
            );
        }
    }

    private function hasOpenBlockingCases(Property $property): bool
    {
        if (! Schema::hasTable('property_moderation_cases')) {
            return false;
        }

        return PropertyModerationCase::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [PropertyModerationCase::STATUS_OPEN, PropertyModerationCase::STATUS_REJECTED])
            ->where('blocking', true)
            ->exists();
    }

    private function hasConfirmedDuplicate(Property $property): bool
    {
        if (! Schema::hasTable('property_duplicate_candidates')) {
            return false;
        }

        return PropertyDuplicateCandidate::query()
            ->whereHas('moderationCase', fn ($query) => $query->where('property_id', $property->id))
            ->where('decision', PropertyDuplicateCandidate::DECISION_CONFIRMED)
            ->whereNull('reversed_at')
            ->exists();
    }

    private function reverseDuplicateConfirmationForAppeal(
        PropertyModerationCase $appeal,
        Property $property,
        User $actor,
        ?string $comment,
    ): void {
        $parent = $appeal->parentCase()->lockForUpdate()->first();
        abort_unless($parent && in_array($parent->status, [
            PropertyModerationCase::STATUS_REJECTED,
            PropertyModerationCase::STATUS_MERGED,
        ], true), 409, 'APPEAL_PARENT_INVALID');

        $confirmed = $parent->duplicateCandidates()
            ->where('decision', PropertyDuplicateCandidate::DECISION_CONFIRMED)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->get();
        abort_if($parent->type === PropertyModerationCase::TYPE_DUPLICATE && $confirmed->isEmpty(), 409, 'DUPLICATE_CONFIRMATION_NOT_ACTIVE');

        foreach ($confirmed as $candidate) {
            $candidate->update([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_comment' => $comment,
            ]);
        }

        if ($confirmed->isNotEmpty()) {
            $property->forceFill(['duplicate_of_property_id' => null])->saveQuietly();
        }
        $parent->update(['blocking' => false]);
        $originalTrustEvent = EmployeeTrustEvent::query()
            ->where('moderation_case_id', $parent->id)
            ->where('type', $parent->type === PropertyModerationCase::TYPE_PRICE_INCREASE ? 'confirmed_price_manipulation' : 'confirmed_duplicate')
            ->whereDoesntHave('reversal')
            ->latest('id')
            ->first();
        if ($originalTrustEvent) {
            EmployeeTrustEvent::create([
                'user_id' => $originalTrustEvent->user_id,
                'property_id' => $property->id,
                'moderation_case_id' => $appeal->id,
                'type' => $parent->type === PropertyModerationCase::TYPE_PRICE_INCREASE ? 'price_confirmation_reversed' : 'duplicate_confirmation_reversed',
                'points_delta' => -1 * (float) $originalTrustEvent->points_delta,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'comment' => $comment,
                'expires_at' => $originalTrustEvent->expires_at,
                'reverses_event_id' => $originalTrustEvent->id,
            ]);
        }
        $this->event($property, $appeal, 'duplicate_confirmation_reversed', $actor, [
            'parent_case_id' => $parent->id,
            'candidate_ids' => $confirmed->pluck('id')->all(),
            'comment' => $comment,
        ]);
    }

    private function withdrawOpenCases(Property $property, User $actor, string $status, bool $changesOnly = false): void
    {
        PropertyModerationCase::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [PropertyModerationCase::STATUS_OPEN, PropertyModerationCase::STATUS_REJECTED])
            ->when($changesOnly, fn ($query) => $query->whereIn('type', [PropertyModerationCase::TYPE_PRICE_INCREASE, PropertyModerationCase::TYPE_CONTENT]))
            ->whereDoesntHave('duplicateCandidates', fn ($query) => $query->where('decision', PropertyDuplicateCandidate::DECISION_CONFIRMED))
            ->update([
                'status' => $status,
                'blocking' => false,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => 'Отозвано автором объявления.',
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);
    }

    /**
     * Move only user-facing references that can safely follow the canonical
     * listing. Transactional/history records intentionally keep the duplicate
     * property id so the audit trail remains truthful.
     *
     * @return array{favorites:int,selections:int}
     */
    private function transferMergeRelations(int $duplicateId, int $canonicalId): array
    {
        $counts = ['favorites' => 0, 'selections' => 0];

        if (Schema::hasTable('favorites')) {
            $hasTypedColumns = Schema::hasColumn('favorites', 'entity_type')
                && Schema::hasColumn('favorites', 'entity_id');
            $favorites = DB::table('favorites')
                ->where(function ($query) use ($duplicateId, $hasTypedColumns): void {
                    $query->where('property_id', $duplicateId);
                    if ($hasTypedColumns) {
                        $query->orWhere(function ($typed) use ($duplicateId): void {
                            $typed->where('entity_type', 'property')->where('entity_id', $duplicateId);
                        });
                    }
                })
                ->orderBy('id')
                ->get();

            foreach ($favorites as $favorite) {
                $alreadyCanonical = DB::table('favorites')
                    ->where('user_id', $favorite->user_id)
                    ->where('id', '!=', $favorite->id)
                    ->where(function ($query) use ($canonicalId, $hasTypedColumns): void {
                        $query->where('property_id', $canonicalId);
                        if ($hasTypedColumns) {
                            $query->orWhere(function ($typed) use ($canonicalId): void {
                                $typed->where('entity_type', 'property')->where('entity_id', $canonicalId);
                            });
                        }
                    })
                    ->exists();

                if ($alreadyCanonical) {
                    DB::table('favorites')->where('id', $favorite->id)->delete();
                } else {
                    DB::table('favorites')->where('id', $favorite->id)->update([
                        'property_id' => $canonicalId,
                        ...$hasTypedColumns ? ['entity_type' => 'property', 'entity_id' => $canonicalId] : [],
                        'updated_at' => now(),
                    ]);
                }
                $counts['favorites']++;
            }
        }

        if (Schema::hasTable('selections') && Schema::hasColumn('selections', 'property_ids')) {
            DB::table('selections')->orderBy('id')->chunkById(100, function ($selections) use ($duplicateId, $canonicalId, &$counts): void {
                foreach ($selections as $selection) {
                    $ids = json_decode((string) $selection->property_ids, true);
                    if (! is_array($ids) || ! in_array($duplicateId, array_map('intval', $ids), true)) {
                        continue;
                    }
                    $normalized = collect($ids)
                        ->map(fn ($id) => (int) $id === $duplicateId ? $canonicalId : (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                    DB::table('selections')->where('id', $selection->id)->update([
                        'property_ids' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                    $counts['selections']++;
                }
            });
        }

        return $counts;
    }

    private function publicationStatus(Property $property, bool $original = false): string
    {
        if (Schema::hasColumn('properties', 'publication_status')) {
            return (string) ($original ? $property->getOriginal('publication_status') : $property->publication_status);
        }

        $legacy = (string) ($original ? $property->getOriginal('moderation_status') : $property->moderation_status);

        return $legacy === Property::PUBLIC_MODERATION_STATUS ? self::PUBLICATION_PUBLISHED : self::PUBLICATION_PENDING;
    }

    private function workflowAvailable(): bool
    {
        return Schema::hasColumn('properties', 'publication_status')
            && Schema::hasTable('property_moderation_cases');
    }

    private function event(
        Property $property,
        ?PropertyModerationCase $case,
        string $type,
        ?User $actor,
        array $payload = [],
        bool $notify = true,
    ): void {
        if (! Schema::hasTable('property_moderation_events')) {
            return;
        }

        $request = app()->bound('request') ? request() : null;
        $previous = method_exists($property, 'getPrevious') ? $property->getPrevious() : [];
        $payload = array_merge([
            'old_publication_status' => $previous['publication_status'] ?? $property->publication_status ?? null,
            'new_publication_status' => $property->publication_status ?? null,
            'old_deal_status' => $previous['deal_status'] ?? $property->deal_status ?? null,
            'new_deal_status' => $property->deal_status ?? null,
            'old_price' => $previous['price'] ?? $property->price,
            'new_price' => $property->price,
            'old_discount_price' => $previous['discount_price'] ?? $property->discount_price,
            'new_discount_price' => $property->discount_price,
            'old_currency' => $previous['currency'] ?? $property->currency,
            'new_currency' => $property->currency,
            'publication_status' => $property->publication_status ?? null,
            'deal_status' => $property->deal_status ?? null,
            'approved_price' => $property->approved_price,
            'approved_discount_price' => $property->approved_discount_price,
            'approved_effective_price' => $property->approved_effective_price,
            'approved_currency' => $property->approved_currency,
            'proposed_price' => $property->price,
            'proposed_discount_price' => $property->discount_price,
            'proposed_currency' => $property->currency,
            'baseline_snapshot' => $case?->baseline_snapshot,
            'proposed_snapshot' => $case?->proposed_snapshot,
        ], $payload);
        PropertyModerationEvent::create([
            'property_id' => $property->id,
            'moderation_case_id' => $case?->id,
            'event_type' => $type,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->slug,
            'payload' => $payload,
            'request_id' => $request?->headers->get('Idempotency-Key') ?: $request?->headers->get('X-Request-Id'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
        if ($notify) {
            app(PropertyModerationNotifier::class)->moderationEvent($property, $type, $actor, $case);
        }
    }

    private function trustEvent(PropertyModerationCase $case, User $actor, string $type, string $comment): void
    {
        if (! Schema::hasTable('employee_trust_events') || ! $case->submitted_by) {
            return;
        }

        $basePoints = (float) config('property-moderation.trust_points.'.$type, 0);
        $repeatCount = EmployeeTrustEvent::query()
            ->where('user_id', $case->submitted_by)
            ->where('type', $type)
            ->whereDoesntHave('reversal')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
        $repeatMultiplier = max(1.0, (float) config('property-moderation.trust_repeat_multiplier', 1.5));
        $multiplier = match (true) {
            $repeatCount === 0 => 1.0,
            $repeatCount === 1 => $repeatMultiplier,
            default => 2.0,
        };
        $points = $basePoints < 0 ? round($basePoints * $multiplier, 2) : $basePoints;

        EmployeeTrustEvent::create([
            'user_id' => $case->submitted_by,
            'property_id' => $case->property_id,
            'moderation_case_id' => $case->id,
            'type' => $type,
            'points_delta' => $points,
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
            'comment' => $comment,
            'expires_at' => now()->addDays((int) config('property-moderation.trust_window_days', 90)),
        ]);
        $employee = User::query()->find($case->submitted_by);
        $property = Property::query()->find($case->property_id);
        if ($employee && $property && $points < 0) {
            app(PropertyModerationNotifier::class)->trustDecreased($property, $employee, $actor, $points);
        }
    }
}
