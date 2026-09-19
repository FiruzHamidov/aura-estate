<?php

namespace App\Services\GroupAccess;

use App\Models\{Booking, Client, Conversation, CrmTask, DailyReport, Deal, Lead, Notification, Property, Selection, User};
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\Schema;

/** Notification text is a snapshot: both its original group and current subject must be visible. */
final class NotificationGroupAccess
{
    private const ROOTS = [Property::class, Client::class, Lead::class, Deal::class, Booking::class, CrmTask::class, DailyReport::class, Selection::class];

    public function __construct(private readonly RopGroupAccess $groups) {}

    public function subjectAllowed(User $recipient, ?Model $subject): bool
    {
        if (! $recipient->hasRole('rop')) return true;
        if ($subject instanceof Conversation) {
            return $subject->participants()->where('user_id', $recipient->id)->exists();
        }
        return $subject !== null && in_array($subject::class, self::ROOTS, true)
            && $this->groups->allows($recipient, $subject);
    }

    public function selectionEventAllowed(User $recipient, Selection $selection, ?array $payload): bool
    {
        if (! $recipient->hasRole('rop')) return true;
        if (! $this->subjectAllowed($recipient, $selection)) return false;
        if (! isset($payload['property_id'])) return true;
        $propertyId = (int) $payload['property_id'];
        return in_array($propertyId, array_map('intval', $selection->property_ids ?? []), true)
            && $this->groups->scope(Property::query(), $recipient, 'properties.branch_group_id', 'properties.branch_id')->whereKey($propertyId)->exists();
    }

    public function snapshot(?Model $subject): ?int
    {
        return $subject && in_array($subject::class, self::ROOTS, true)
            ? ($subject->getAttributes()['branch_group_id'] ?? null) : null;
    }

    public function scope(Builder $query, User $recipient): Builder
    {
        $query->whereIn('notifications.user_id', User::query()->whereKey($recipient->id)
            ->where('status', User::STATUS_ACTIVE)->select('users.id'));
        if (! $recipient->hasRole('rop')) return $query;

        return $query->where(function (Builder $allowed) use ($recipient) {
            $allowed->whereRaw('1 = 0');
            if (Schema::hasTable('conversation_participants')) {
                $allowed->orWhere(function (Builder $chat) use ($recipient) {
                    $chat->where('subject_type', (new Conversation)->getMorphClass())
                        ->whereIn('subject_id', \DB::table('conversation_participants')->select('conversation_id')->where('user_id', $recipient->id));
                });
            }
            if (! Schema::hasColumn('notifications', 'branch_group_id')) return;
            $allowed->orWhere(function (Builder $business) use ($recipient) {
                $this->groups->scope($business, $recipient, 'notifications.branch_group_id');
                $business->where(function (Builder $event) use ($recipient) {
                    $event->where('subject_type', '!=', (new Selection)->getMorphClass())
                        ->orWhereNull('data->payload->property_id');
                    if (Schema::hasColumn('properties', 'branch_group_id')) {
                        $properties = $this->groups->scope(Property::query(), $recipient, 'properties.branch_group_id', 'properties.branch_id');
                        $event->orWhereIn('data->payload->property_id', $properties->select('properties.id'));
                    }
                });
                $business->where(function (Builder $subjects) use ($recipient) {
                    $subjects->whereRaw('1 = 0');
                    foreach (self::ROOTS as $class) {
                        $model = new $class;
                        $table = $model->getTable();
                        if (! Schema::hasColumn($table, 'branch_group_id')) continue;
                        $source = $class::query()->select($table.'.id')
                            ->whereColumn($table.'.branch_group_id', 'notifications.branch_group_id');
                        $this->groups->scope($source, $recipient, $table.'.branch_group_id', Schema::hasColumn($table, 'branch_id') ? $table.'.branch_id' : null);
                        $subjects->orWhere(fn (Builder $kind) => $kind->where('subject_type', $model->getMorphClass())->whereIn('subject_id', $source));
                    }
                });
            });
        });
    }

    public function allows(Notification $notification, User $recipient): bool
    {
        return (int) $notification->user_id === (int) $recipient->id
            && $this->scope(Notification::query(), $recipient)->whereKey($notification->id)->exists();
    }
}
