<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\User;

trait SerializesConversationPayloads
{
    private function serializeConversation(Conversation $conversation, User $viewer): array
    {
        $conversation->loadMissing([
            'participants.user.role',
            'latestMessage.author.role',
            'supportThread',
        ]);

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
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
                'chat_session_id' => $conversation->supportThread->chat_session_id,
                'summary' => $conversation->supportThread->summary,
            ] : null,
        ];
    }

    private function serializeMessage(ConversationMessage $message, User $viewer, ?Conversation $conversation = null): array
    {
        $message->loadMissing('author.role');

        $conversation ??= $message->conversation;

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'author_id' => $message->author_id,
            'type' => $message->type,
            'body' => $message->body,
            'meta' => $message->meta,
            'created_at' => $message->created_at?->toIso8601String(),
            'role' => $this->messageRole($message, $viewer),
            'delivery_status' => $this->deliveryStatus($message, $viewer, $conversation),
            'sender' => $message->author ? $this->serializeSender($message->author) : null,
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

    private function messageRole(ConversationMessage $message, User $viewer): string
    {
        if ((int) $message->author_id === (int) $viewer->id) {
            return 'me';
        }

        return $message->author?->role?->slug
            ?? ($message->author_id ? 'user' : 'system');
    }

    private function deliveryStatus(ConversationMessage $message, User $viewer, ?Conversation $conversation): string
    {
        $metaStatus = $message->meta['delivery_status'] ?? null;

        if (in_array($metaStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return $metaStatus;
        }

        if ((int) $message->author_id !== (int) $viewer->id || ! $conversation) {
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
