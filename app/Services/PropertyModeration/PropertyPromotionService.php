<?php

namespace App\Services\PropertyModeration;

use App\Models\Property;
use App\Models\PropertyLog;
use App\Models\PropertyModerationEvent;
use App\Models\PropertyPromotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PropertyPromotionService
{
    public function __construct(
        private readonly PropertyModerationAccess $access,
        private readonly PropertyModerationService $moderation,
    ) {}

    public function request(Property $property, User $actor, string $type, string $comment, int $requestedDays, int $expectedVersion): PropertyPromotion
    {
        abort_unless(in_array($type, [PropertyPromotion::TYPE_VIP, PropertyPromotion::TYPE_URGENT], true), 422);

        return DB::transaction(function () use ($property, $actor, $type, $comment, $requestedDays, $expectedVersion): PropertyPromotion {
            $lockedProperty = Property::query()->lockForUpdate()->findOrFail($property->id);
            abort_if((int) $lockedProperty->moderation_version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canEdit($actor, $lockedProperty), 403, 'PROMOTION_PERMISSION_DENIED');
            abort_unless(in_array($lockedProperty->publication_status, ['published', 'pending'], true), 409, 'PROMOTION_BLOCKED_BY_MODERATION');
            abort_if($lockedProperty->promotions()->where('status', PropertyPromotion::STATUS_REQUESTED)->exists(), 409, 'PROMOTION_ALREADY_REQUESTED');
            abort_unless(trim($comment) !== '' && $requestedDays >= 1 && $requestedDays <= (int) config('property-moderation.promotion_max_days', 30), 422);

            $promotion = PropertyPromotion::create([
                'property_id' => $lockedProperty->id,
                'type' => $type,
                'status' => PropertyPromotion::STATUS_REQUESTED,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'request_comment' => $comment,
                'requested_days' => $requestedDays,
                'source' => 'manual',
                'version' => 1,
            ]);
            $lockedProperty->forceFill([
                'moderation_version' => (int) $lockedProperty->moderation_version + 1,
            ])->save();
            app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'requested', $actor);
            $this->moderation->auditPromotionEvent($lockedProperty, $actor, 'property_promotion_requested', ['promotion_id' => $promotion->id, 'type' => $type, 'requested_days' => $requestedDays]);

            return $promotion;
        });
    }

    public function approve(PropertyPromotion $promotion, User $actor, int $days, ?string $comment, ?int $expectedVersion = null): PropertyPromotion
    {
        return DB::transaction(function () use ($promotion, $actor, $days, $comment, $expectedVersion): PropertyPromotion {
            $property = Property::query()->lockForUpdate()->findOrFail($promotion->property_id);
            $promotion = PropertyPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $promotion->setRelation('property', $property);
            abort_unless($promotion->status === PropertyPromotion::STATUS_REQUESTED, 409, 'PROMOTION_NOT_REQUESTED');
            abort_if($expectedVersion !== null && $promotion->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canModerate($actor, $promotion->property), 403, 'PROMOTION_PERMISSION_DENIED');
            abort_if(in_array((int) $actor->id, array_filter([
                (int) $promotion->requested_by,
                (int) $promotion->property->created_by,
                (int) $promotion->property->agent_id,
                (int) ($promotion->property->co_owner_user_id ?? 0),
            ]), true), 403, 'SELF_APPROVAL_FORBIDDEN');
            abort_if($this->lastSubstantialEditorId($promotion->property) === (int) $actor->id, 403, 'SELF_APPROVAL_FORBIDDEN');
            abort_unless($this->moderation->isPublic($promotion->property), 409, 'PROMOTION_BLOCKED_BY_MODERATION');

            $days = min(max(1, $days), (int) config('property-moderation.promotion_max_days', 30));
            $promotion->property->promotions()
                ->where('status', PropertyPromotion::STATUS_ACTIVE)
                ->update(['status' => PropertyPromotion::STATUS_REVOKED, 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => 'Заменено новым продвижением']);
            $promotion->update([
                'status' => PropertyPromotion::STATUS_ACTIVE,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'starts_at' => now(),
                'ends_at' => now()->addDays($days),
                'version' => $promotion->version + 1,
            ]);
            $promotion->property->forceFill([
                'listing_type' => $promotion->type,
                'moderation_version' => (int) $promotion->property->moderation_version + 1,
            ])->save();
            app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'approved', $actor);
            $this->moderation->auditPromotionEvent($promotion->property, $actor, 'property_promotion_approved', ['promotion_id' => $promotion->id, 'type' => $promotion->type, 'starts_at' => $promotion->starts_at, 'ends_at' => $promotion->ends_at]);

            return $promotion->fresh('property');
        });
    }

    public function reject(PropertyPromotion $promotion, User $actor, string $comment, ?int $expectedVersion = null): PropertyPromotion
    {
        return DB::transaction(function () use ($promotion, $actor, $comment, $expectedVersion): PropertyPromotion {
            $property = Property::query()->lockForUpdate()->findOrFail($promotion->property_id);
            $promotion = PropertyPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $promotion->setRelation('property', $property);
            abort_unless($promotion->status === PropertyPromotion::STATUS_REQUESTED, 409, 'PROMOTION_NOT_REQUESTED');
            abort_if($expectedVersion !== null && $promotion->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($this->access->canModerate($actor, $property), 403, 'PROMOTION_PERMISSION_DENIED');
            abort_if(in_array((int) $actor->id, array_filter([
                (int) $promotion->requested_by,
                (int) $property->created_by,
                (int) $property->agent_id,
                (int) ($property->co_owner_user_id ?? 0),
            ]), true), 403, 'SELF_APPROVAL_FORBIDDEN');
            $promotion->update([
                'status' => PropertyPromotion::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
                'version' => $promotion->version + 1,
            ]);
            $property->forceFill(['moderation_version' => (int) $property->moderation_version + 1])->save();
            app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'rejected', $actor);
            $this->moderation->auditPromotionEvent($property, $actor, 'property_promotion_rejected', ['promotion_id' => $promotion->id, 'comment' => $comment]);

            return $promotion->fresh('property');
        });
    }

    public function revoke(PropertyPromotion $promotion, User $actor, string $comment, ?int $expectedVersion = null): PropertyPromotion
    {
        return DB::transaction(function () use ($promotion, $actor, $comment, $expectedVersion): PropertyPromotion {
            $property = Property::query()->lockForUpdate()->findOrFail($promotion->property_id);
            $promotion = PropertyPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $promotion->setRelation('property', $property);
            abort_unless(in_array($promotion->status, [PropertyPromotion::STATUS_ACTIVE, PropertyPromotion::STATUS_REQUESTED], true), 409, 'PROMOTION_NOT_ACTIVE');
            $wasActive = $promotion->status === PropertyPromotion::STATUS_ACTIVE;
            abort_if($expectedVersion !== null && $promotion->version !== $expectedVersion, 409, 'MODERATION_VERSION_CONFLICT');
            abort_unless($wasActive ? $this->access->canModerate($actor, $property) : $this->access->canEdit($actor, $property), 403, 'PROMOTION_PERMISSION_DENIED');
            $promotion->update([
                'status' => PropertyPromotion::STATUS_REVOKED,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revoke_reason' => $comment,
                'version' => $promotion->version + 1,
            ]);
            $property->forceFill([
                'listing_type' => $wasActive ? 'regular' : $property->listing_type,
                'moderation_version' => (int) $property->moderation_version + 1,
            ])->save();
            app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'revoked', $actor);
            $this->moderation->auditPromotionEvent($property, $actor, 'property_promotion_revoked', ['promotion_id' => $promotion->id, 'comment' => $comment]);

            return $promotion->fresh('property');
        });
    }

    public function expireDue(): int
    {
        PropertyPromotion::query()
            ->where('status', PropertyPromotion::STATUS_ACTIVE)
            ->whereBetween('ends_at', [now(), now()->addDay()])
            ->with('property')
            ->chunkById(100, function ($promotions): void {
                foreach ($promotions as $promotion) {
                    app(PropertyModerationNotifier::class)->promotionEvent($promotion, 'expiring', null);
                }
            });

        $expired = 0;
        PropertyPromotion::query()
            ->whereIn('status', [PropertyPromotion::STATUS_ACTIVE, PropertyPromotion::STATUS_SUSPENDED])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($promotions) use (&$expired): void {
                foreach ($promotions as $promotion) {
                    DB::transaction(function () use ($promotion, &$expired): void {
                        $property = Property::query()->lockForUpdate()->find($promotion->property_id);
                        $locked = PropertyPromotion::query()->lockForUpdate()->find($promotion->id);
                        if (! $locked || ! in_array($locked->status, [PropertyPromotion::STATUS_ACTIVE, PropertyPromotion::STATUS_SUSPENDED], true) || $locked->ends_at?->isFuture()) {
                            return;
                        }
                        if (! $property) {
                            return;
                        }
                        $locked->setRelation('property', $property);
                        $locked->update(['status' => PropertyPromotion::STATUS_EXPIRED, 'version' => $locked->version + 1]);
                        $hasOtherActive = $locked->property->promotions()
                            ->whereKeyNot($locked->id)
                            ->where('status', PropertyPromotion::STATUS_ACTIVE)
                            ->where('starts_at', '<=', now())
                            ->where('ends_at', '>', now())
                            ->exists();
                        if (! $hasOtherActive) {
                            $locked->property->forceFill([
                                'listing_type' => 'regular',
                                'moderation_version' => (int) $locked->property->moderation_version + 1,
                            ])->save();
                        }
                        app(PropertyModerationNotifier::class)->promotionEvent($locked, 'expired', null);
                        $this->moderation->auditPromotionEvent($locked->property, null, 'property_promotion_expired', ['promotion_id' => $locked->id]);
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    private function lastSubstantialEditorId(Property $property): ?int
    {
        $event = PropertyModerationEvent::query()->where('property_id', $property->id)
            ->whereIn('event_type', ['property_sent_to_moderation', 'property_media_changed', 'property_updated_without_review'])
            ->whereNotNull('actor_id')->latest('id')->first();
        if ($event) {
            return (int) $event->actor_id;
        }

        if (! Schema::hasTable('property_logs')) {
            return null;
        }

        $contentFields = array_flip(Property::LISTING_CONTENT_FIELDS);
        $log = PropertyLog::query()
            ->where('property_id', $property->id)
            ->whereNotNull('user_id')
            ->latest('id')
            ->cursor()
            ->first(function (PropertyLog $log) use ($contentFields): bool {
                $changes = (array) $log->changes;

                return array_intersect_key($changes, $contentFields) !== [];
            });

        return $log?->user_id ? (int) $log->user_id : null;
    }
}
