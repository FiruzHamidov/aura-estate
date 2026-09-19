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

// Retire the shared target channel: an already connected ROP must never receive new coordinates there.
Broadcast::channel('location.user.{targetUserId}', fn () => false);

Broadcast::channel('location.viewer.{viewerId}.scope.{version}', function (User $viewer, int $viewerId, int $version) {
    $current = User::query()->find($viewer->id);
    return $current && $current->status === User::STATUS_ACTIVE && (int) $current->id === $viewerId
        && (int) $current->access_scope_version === $version
        && in_array(app(LocationAccessService::class)->role($current), config('location_tracking.viewer_roles', []), true);
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
