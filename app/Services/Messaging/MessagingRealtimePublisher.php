<?php

namespace App\Services\Messaging;

use App\Events\ConversationMessageCreated;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;

class MessagingRealtimePublisher
{
    public const PAYLOAD_VERSION = 1;

    public function publishMessageCreated(ConversationMessage $message): void
    {
        if (! $this->enabled() || $message->type === ConversationMessage::TYPE_SYSTEM) {
            return;
        }

        $message->loadMissing(['conversation.participants', 'conversation.supportThread']);
        $conversation = $message->conversation;
        $guestThreadPublicId = $this->guestThreadPublicId($conversation);
        $messagePayload = $this->messagePayload($message);

        ConversationMessageCreated::dispatch(
            (int) $conversation->id,
            $this->basePayload('message.created:'.$message->id, $conversation) + [
                'message' => $messagePayload,
            ],
            $guestThreadPublicId,
        );

        foreach ($conversation->participants as $participant) {
            $userId = (int) $participant->user_id;
            ConversationUpdated::dispatch(
                $this->basePayload('conversation.updated:'.$message->id.':'.$userId, $conversation) + [
                    'reason' => 'message_created',
                    'unread_count' => $this->unreadCount($conversation, $userId),
                    'message' => $messagePayload,
                ],
                $userId,
            );
        }

        if ($guestThreadPublicId !== null) {
            ConversationUpdated::dispatch(
                $this->basePayload('guest-conversation.updated:'.$message->id, $conversation) + [
                    'reason' => 'message_created',
                    'message' => $messagePayload,
                ],
                null,
                $guestThreadPublicId,
            );
        }
    }

    public function publishConversationCreated(Conversation $conversation): void
    {
        if (! $this->enabled()) {
            return;
        }

        $conversation->loadMissing(['participants', 'supportThread']);

        foreach ($conversation->participants as $participant) {
            $userId = (int) $participant->user_id;
            ConversationUpdated::dispatch(
                $this->basePayload('conversation.created:'.$conversation->id.':'.$userId, $conversation) + [
                    'reason' => 'conversation_created',
                    'unread_count' => $this->unreadCount($conversation, $userId),
                ],
                $userId,
            );
        }
    }

    public function publishConversationRead(Conversation $conversation, User $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $latestMessageId = (int) ($conversation->latestMessage()->value('id') ?? 0);

        ConversationUpdated::dispatch(
            $this->basePayload(
                'conversation.read:'.$conversation->id.':'.$user->id.':'.$latestMessageId,
                $conversation,
            ) + [
                'reason' => 'conversation_read',
                'unread_count' => 0,
            ],
            (int) $user->id,
        );
    }

    private function enabled(): bool
    {
        return (bool) config('messaging.realtime_broadcast_enabled', false);
    }

    private function basePayload(string $eventId, Conversation $conversation): array
    {
        return [
            'version' => self::PAYLOAD_VERSION,
            'event_id' => $eventId,
            'conversation_id' => (int) $conversation->id,
            'occurred_at' => now()->toIso8601String(),
            'conversation' => [
                'id' => (int) $conversation->id,
                'type' => $conversation->type,
                'kind' => match ($conversation->type) {
                    Conversation::TYPE_DIRECT => Conversation::KIND_PERSONAL,
                    Conversation::TYPE_GROUP => Conversation::KIND_GROUP,
                    Conversation::TYPE_SUPPORT => Conversation::KIND_SUPPORT,
                    default => $conversation->type,
                },
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function messagePayload(ConversationMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'author_id' => $message->author_id === null ? null : (int) $message->author_id,
            'client_message_id' => $message->client_message_id,
            'type' => $message->type,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function guestThreadPublicId(Conversation $conversation): ?string
    {
        return $conversation->supportThread?->guest_session_id
            ? $conversation->supportThread->public_id
            : null;
    }

    private function unreadCount(Conversation $conversation, int $userId): int
    {
        $lastReadMessageId = (int) ($conversation->participants
            ->firstWhere('user_id', $userId)
            ?->last_read_message_id ?? 0);

        return $conversation->messages()
            ->where('id', '>', $lastReadMessageId)
            ->where(fn ($query) => $query
                ->whereNull('author_id')
                ->orWhere('author_id', '!=', $userId))
            ->count();
    }
}
