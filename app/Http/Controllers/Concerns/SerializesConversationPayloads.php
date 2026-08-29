<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\GuestSupportSession;
use App\Models\User;

trait SerializesConversationPayloads
{
    private function serializeConversation(Conversation $conversation, User $viewer): array
    {
        $conversation->loadMissing([
            'participants.user.role',
            'latestMessage.author.role',
            'latestMessage.guestSession',
            'supportThread.requester.role',
            'supportThread.guestSession',
        ]);

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'is_support' => $conversation->type === Conversation::TYPE_SUPPORT,
            'name' => $this->conversationDisplayName($conversation, $viewer),
            'created_by' => $conversation->created_by,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'unread_count' => $this->unreadCount($conversation, $viewer),
            'meta' => $conversation->meta,
            'latest_message' => $conversation->latestMessage
                ? $this->serializeMessage($conversation->latestMessage, $viewer, $conversation)
                : null,
            'participants' => $conversation->participants
                ->map(fn (ConversationParticipant $participant) => $this->serializeParticipant($participant))
                ->values()
                ->all(),
            'support_thread' => $conversation->supportThread ? [
                'id' => $conversation->supportThread->id,
                'status' => $conversation->supportThread->status,
                'requester_user_id' => $conversation->supportThread->requester_user_id,
                'guest_session_id' => $conversation->supportThread->guestSession?->public_id,
                'chat_session_id' => $conversation->supportThread->chat_session_id,
                'summary' => $conversation->supportThread->summary,
                'source' => $conversation->supportThread->meta['source']
                    ?? ($conversation->supportThread->chat_session_id ? 'chat' : 'support_form'),
                'requester' => $conversation->supportThread->requester
                    ? $this->serializeIdentity($conversation->supportThread->requester)
                    : ($conversation->supportThread->guestSession
                        ? $this->serializeGuestIdentity($conversation->supportThread->guestSession)
                        : null),
                'responsibility' => $this->serializeResponsibility($conversation),
            ] : null,
        ];
    }

    private function serializeMessage(
        ConversationMessage $message,
        ?User $viewer,
        ?Conversation $conversation = null,
        ?GuestSupportSession $guestViewer = null
    ): array {
        $message->loadMissing('author.role', 'guestSession');

        $conversation ??= $message->conversation;

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'author_id' => $message->author_id,
            'type' => $message->type,
            'body' => $message->body,
            'meta' => $message->meta,
            'created_at' => $message->created_at?->toIso8601String(),
            'role' => $this->messageRole($message, $viewer, $guestViewer),
            'delivery_status' => $this->deliveryStatus($message, $viewer, $conversation),
            'sender' => $message->author
                ? $this->serializeSender($message->author)
                : ($message->guestSession ? $this->serializeGuestSender($message->guestSession) : null),
            'sender_identity' => $message->author
                ? $this->serializeIdentity($message->author)
                : ($message->guestSession ? $this->serializeGuestIdentity($message->guestSession) : [
                    'kind' => 'system',
                    'role_slug' => null,
                    'id' => null,
                    'name' => 'System',
                ]),
            'author' => $message->author ? [
                'id' => $message->author->id,
                'name' => $message->author->name,
                'photo' => $this->userPhoto($message->author),
                'role_slug' => $message->author->role?->slug,
            ] : null,
        ];
    }

    private function serializeParticipant(ConversationParticipant $participant): array
    {
        $participant->loadMissing('user.role');

        return [
            'id' => $participant->id,
            'user_id' => $participant->user_id,
            'role' => $participant->role,
            'joined_at' => $participant->joined_at?->toIso8601String(),
            'last_read_message_id' => $participant->last_read_message_id,
            'last_read_at' => $participant->last_read_at?->toIso8601String(),
            'user' => $participant->user ? $this->serializeChatUser($participant->user) : null,
        ];
    }

    private function serializeChatUser(User $user): array
    {
        $isOnline = (bool) ($user->getAttribute('is_online') ?? false);
        $lastSeenAt = $user->getAttribute('last_seen_at');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'photo' => $this->userPhoto($user),
            'is_online' => $isOnline,
            'status' => $isOnline ? 'online' : 'offline',
            'last_seen_at' => $lastSeenAt instanceof \DateTimeInterface
                ? $lastSeenAt->format(\DateTimeInterface::ATOM)
                : $lastSeenAt,
            'role_slug' => $user->role?->slug,
        ];
    }

    private function serializeSender(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'photo' => $this->userPhoto($user),
        ];
    }

    private function serializeIdentity(User $user): array
    {
        return [
            'kind' => match ($user->role?->slug) {
                'client' => 'client',
                'agent' => 'agent',
                'manager', 'operator', 'admin', 'superadmin' => 'support_staff',
                default => 'internal_user',
            },
            'id' => $user->id,
            'name' => $user->name,
            'role_slug' => $user->role?->slug,
        ];
    }

    private function serializeGuestIdentity(GuestSupportSession $session): array
    {
        return [
            'kind' => 'guest',
            'id' => $session->public_id,
            'name' => 'Гость / потенциальный клиент без аккаунта',
            'role_slug' => null,
        ];
    }

    private function serializeGuestSender(GuestSupportSession $session): array
    {
        return [
            'id' => $session->public_id,
            'name' => 'Гость / потенциальный клиент без аккаунта',
            'photo' => null,
        ];
    }

    private function serializeResponsibility(Conversation $conversation): array
    {
        $latestMessage = $conversation->latestMessage;
        $latestAuthorId = $latestMessage?->author_id;
        $requesterId = $conversation->supportThread?->requester_user_id;
        $latestFromRequester = $requesterId
            ? (int) $latestAuthorId === (int) $requesterId
            : ($conversation->supportThread?->guest_session_id
                && (int) $latestMessage?->guest_session_id === (int) $conversation->supportThread->guest_session_id);
        $eligibleResponders = $conversation->participants
            ->filter(fn (ConversationParticipant $participant) => in_array(
                $participant->user?->role?->slug,
                ['manager', 'operator', 'admin', 'superadmin'],
                true
            ))
            ->pluck('user_id')
            ->values()
            ->all();

        return [
            'queue' => 'support',
            'assigned_to_user_id' => null,
            'eligible_responder_user_ids' => $eligibleResponders,
            'response_required_from' => ! $latestAuthorId && ! $latestMessage?->guest_session_id
                ? 'none'
                : ($latestFromRequester ? 'support_staff' : 'requester'),
        ];
    }

    private function messageRole(
        ConversationMessage $message,
        ?User $viewer,
        ?GuestSupportSession $guestViewer = null
    ): string {
        if ($viewer && (int) $message->author_id === (int) $viewer->id) {
            return 'me';
        }

        if ($guestViewer && (int) $message->guest_session_id === (int) $guestViewer->id) {
            return 'me';
        }

        if ($message->guest_session_id) {
            return 'guest';
        }

        return $message->author?->role?->slug
            ?? ($message->author_id ? 'user' : 'system');
    }

    private function deliveryStatus(ConversationMessage $message, ?User $viewer, ?Conversation $conversation): string
    {
        $metaStatus = $message->meta['delivery_status'] ?? null;

        if (in_array($metaStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return $metaStatus;
        }

        if (! $viewer || (int) $message->author_id !== (int) $viewer->id || ! $conversation) {
            return 'delivered';
        }

        $unreadParticipant = $conversation->participants()
            ->where('user_id', '!=', $viewer->id)
            ->where(function ($query) use ($message) {
                $query->whereNull('last_read_message_id')
                    ->orWhere('last_read_message_id', '<', $message->id);
            })
            ->exists();

        return $unreadParticipant ? 'sent' : 'read';
    }

    private function unreadCount(Conversation $conversation, User $viewer): int
    {
        $participant = $conversation->participants()
            ->where('user_id', $viewer->id)
            ->first();
        $lastReadMessageId = (int) ($participant?->last_read_message_id ?? 0);

        return $conversation->messages()
            ->where('id', '>', $lastReadMessageId)
            ->where(function ($query) use ($viewer) {
                $query->whereNull('author_id')
                    ->orWhere('author_id', '!=', $viewer->id);
            })
            ->count();
    }

    private function conversationDisplayName(Conversation $conversation, User $viewer): ?string
    {
        if (filled($conversation->name)) {
            return $conversation->name;
        }

        if ($conversation->type !== Conversation::TYPE_DIRECT) {
            return $conversation->name;
        }

        return $conversation->participants
            ->first(fn (ConversationParticipant $participant) => (int) $participant->user_id !== (int) $viewer->id)
            ?->user
            ?->name;
    }

    private function userPhoto(User $user): ?string
    {
        return $user->getAttribute('photo') ?: $user->getAttribute('telegram_photo_url');
    }
}
