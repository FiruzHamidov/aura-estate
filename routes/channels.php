<?php

use App\Models\Conversation;
use App\Models\GuestSupportSession;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\LocationTracking\LocationAccessService;
use App\Services\Messaging\MessageAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('location.user.{targetUserId}', function (User $viewer, int $targetUserId) {
    $target = User::query()->find($targetUserId);

    return $target !== null && app(LocationAccessService::class)->canView($viewer, $target);
});

Broadcast::channel('messaging.user.{userId}', function ($viewer, int $userId) {
    return $viewer instanceof User && (int) $viewer->id === $userId;
});

Broadcast::channel('messaging.conversation.{conversationId}', function ($viewer, int $conversationId) {
    if (! $viewer instanceof User) {
        return false;
    }

    $conversation = Conversation::query()->find($conversationId);

    return $conversation !== null
        && app(MessageAccessService::class)->canAccessConversation($viewer, $conversation);
});

Broadcast::channel('guest-support.conversation.{threadPublicId}', function ($viewer, string $threadPublicId) {
    if (! $viewer instanceof GuestSupportSession) {
        return false;
    }

    return SupportThread::query()
        ->where('public_id', $threadPublicId)
        ->where('guest_session_id', $viewer->id)
        ->whereHas('conversation', fn ($query) => $query->where('type', Conversation::TYPE_SUPPORT))
        ->exists();
});
