<?php

namespace App\Services\PropertyModeration;

use App\Models\Notification;
use App\Models\Property;
use App\Models\PropertyModerationCase;
use App\Models\PropertyPromotion;
use App\Models\User;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class PropertyModerationNotifier
{
    public function moderationEvent(Property $property, string $event, ?User $actor, ?PropertyModerationCase $case = null): void
    {
        if (! $this->available()) {
            return;
        }

        $opened = in_array($event, [
            'price_review_opened',
            'duplicate_review_opened',
            'content_review_opened',
            'quality_review_opened',
            'property_sent_to_moderation',
            'moderation_appealed',
        ], true);
        $recipients = $opened
            ? $this->moderators($property)->concat($this->owners($property))
            : $this->owners($property);
        if (in_array($event, ['duplicate_confirmed', 'duplicate_merged'], true)) {
            $recipients = $recipients->concat($this->moderators($property));
        }
        if ($event === 'moderation_appealed' && $case?->parent_case_id) {
            $excludedReviewerId = $case->parentCase()->value('decided_by');
            $recipients = $recipients->reject(fn (User $user) => (int) $user->id === (int) $excludedReviewerId);
        }
        if ($case?->submitted_by) {
            $recipients = $recipients->concat(User::query()->whereKey($case->submitted_by)->get());
        }
        $recipients = $recipients->unique('id')->values();
        $title = $opened ? 'Объявление требует решения' : 'Решение по объявлению';
        $body = $opened
            ? 'Объявление #'.$property->id.' поступило в очередь модерации.'
            : 'Статус объявления #'.$property->id.' изменён.';

        foreach ($recipients as $recipient) {
            $type = match ($event) {
                'duplicate_review_opened' => 'PROPERTY_DUPLICATE_REVIEW_REQUIRED',
                'price_review_opened' => 'PROPERTY_PRICE_REVIEW_REQUIRED',
                'moderation_case_approved', 'moderation_break_glass_approved' => 'PROPERTY_MODERATION_APPROVED',
                'moderation_case_rejected' => 'PROPERTY_MODERATION_REJECTED',
                'duplicate_confirmed', 'duplicate_merged' => 'PROPERTY_DUPLICATE_CONFIRMED',
                'moderation_appealed' => 'PROPERTY_APPEAL_CREATED',
                'property_promotion_suspended' => 'PROPERTY_PROMOTION_SUSPENDED',
                default => 'PROPERTY_MODERATION_EVENT',
            };
            $this->write(
                $recipient,
                $property,
                $actor,
                $type,
                $title,
                $body,
                $event.':'.($case?->id ?? 'property'),
                $case ? '/profile/moderation#case-'.$case->id : null,
            );
        }
    }

    public function promotionEvent(PropertyPromotion $promotion, string $event, ?User $actor): void
    {
        if (! $this->available()) {
            return;
        }
        $promotion->loadMissing('property');
        $recipients = $event === 'requested'
            ? $this->moderators($promotion->property)
            : User::query()->whereKey($promotion->requested_by)->get()
                ->concat($this->owners($promotion->property))
                ->unique('id')
                ->values();
        foreach ($recipients as $recipient) {
            $this->write(
                $recipient,
                $promotion->property,
                $actor,
                match ($event) {
                    'requested' => 'PROPERTY_PROMOTION_REQUESTED',
                    'approved' => 'PROPERTY_PROMOTION_APPROVED',
                    'rejected' => 'PROPERTY_PROMOTION_REJECTED',
                    'suspended' => 'PROPERTY_PROMOTION_SUSPENDED',
                    'expired', 'expiring' => 'PROPERTY_PROMOTION_EXPIRING',
                    default => 'PROPERTY_PROMOTION_EVENT',
                },
                'Продвижение объявления',
                'Заявка на '.strtoupper($promotion->type).' для объявления #'.$promotion->property_id.': '.$event.'.',
                $event.':'.$promotion->id,
                '/profile/moderation#promotion-'.$promotion->id,
            );
        }
    }

    public function trustDecreased(Property $property, User $employee, User $actor, float $points): void
    {
        if (! $this->available()) {
            return;
        }
        $recipients = $this->moderators($property)->push($employee)->unique('id');
        foreach ($recipients as $recipient) {
            $this->write(
                $recipient,
                $property,
                $actor,
                'EMPLOYEE_TRUST_DECREASED',
                'Снижен рейтинг доверия сотрудника',
                'Рейтинг '.$employee->name.' изменён на '.$points.' баллов по объявлению #'.$property->id.'.',
                'trust:'.$employee->id.':'.$property->id.':'.$points,
            );
        }
    }

    private function moderators(Property $property)
    {
        $property->loadMissing(['agent', 'creator']);
        $branchId = $property->branch_id
            ?: $property->agent?->branch_id
            ?: $property->creator?->branch_id;

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', config('property-moderation.moderator_roles', [])))
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', config('property-moderation.global_moderator_roles', [])));
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->get();
    }

    private function owners(Property $property)
    {
        return User::query()->whereIn('id', array_values(array_unique(array_filter([
            $property->created_by, $property->agent_id, $property->co_owner_user_id ?? null,
        ]))))->get();
    }

    private function write(
        User $recipient,
        Property $property,
        ?User $actor,
        string $type,
        string $title,
        string $body,
        string $dedupe,
        ?string $actionUrl = null,
    ): void {
        Notification::query()->firstOrCreate(
            ['dedupe_key' => $type.':'.$property->id.':'.$recipient->id.':'.$dedupe],
            [
                'user_id' => $recipient->id,
                'actor_id' => $actor?->id,
                'type' => $type,
                'category' => 'property',
                'status' => 'pending',
                'priority' => NotificationType::defaultPriority($type),
                'channels' => ['in_app'],
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl ?? '/profile/edit-post/'.$property->id,
                'action_type' => 'open_property',
                'occurrences_count' => 1,
                'last_occurred_at' => now(),
                'subject_type' => Property::class,
                'subject_id' => $property->id,
                'data' => ['property_id' => $property->id],
            ]
        );
    }

    private function available(): bool
    {
        return Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'dedupe_key');
    }
}
