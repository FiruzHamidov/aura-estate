<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\GuestSupportSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(
        private readonly MessageAccessService $access,
        private readonly MessagingRealtimePublisher $realtime,
    ) {}

    public function directKeyForUsers(User $first, User $second): string
    {
        $ids = [(int) $first->id, (int) $second->id];
        sort($ids);

        return implode(':', $ids);
    }

    public function findDirectConversation(User $first, User $second): ?Conversation
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_DIRECT)
            ->where('direct_key', $this->directKeyForUsers($first, $second))
            ->first();
    }

    public function createOrGetDirectConversation(User $actor, User $target): Conversation
    {
        $this->access->ensureCanCreateDirect($actor, $target);

        $existing = $this->findDirectConversation($actor, $target);

        if ($existing) {
            return $existing->load(['participants.user.role', 'latestMessage.author', 'supportThread']);
        }

        return DB::transaction(function () use ($actor, $target) {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_DIRECT,
                'direct_key' => $this->directKeyForUsers($actor, $target),
                'created_by' => $actor->id,
            ]);

            $this->attachParticipant($conversation, $actor, ConversationParticipant::ROLE_OWNER);
            $this->attachParticipant($conversation, $target, ConversationParticipant::ROLE_MEMBER);

            $this->createSystemMessage($conversation, sprintf(
                'Direct conversation created between user #%d and user #%d.',
                $actor->id,
                $target->id
            ));
            $this->realtime->publishConversationCreated($conversation);

            return $conversation->load(['participants.user.role', 'latestMessage.author', 'supportThread']);
        });
    }

    public function createGroupConversation(User $actor, string $name, array $participants, ?array $meta = null): Conversation
    {
        $this->access->ensureCanCreateGroup($actor, $participants);

        return DB::transaction(function () use ($actor, $name, $participants, $meta) {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_GROUP,
                'name' => trim($name),
                'created_by' => $actor->id,
                'meta' => $meta,
            ]);

            $this->attachParticipant($conversation, $actor, ConversationParticipant::ROLE_OWNER);

            foreach ($participants as $participant) {
                if ((int) $participant->id === (int) $actor->id) {
                    continue;
                }

                $this->attachParticipant($conversation, $participant, ConversationParticipant::ROLE_MEMBER);
            }

            $this->createSystemMessage($conversation, 'Group conversation created.');
            $this->realtime->publishConversationCreated($conversation);

            return $conversation->load(['participants.user.role', 'latestMessage.author', 'supportThread']);
        });
    }

    public function addParticipant(Conversation $conversation, User $user, string $role = ConversationParticipant::ROLE_MEMBER): ConversationParticipant
    {
        return $this->attachParticipant($conversation, $user, $role);
    }

    public function removeParticipant(Conversation $conversation, User $user): void
    {
        $conversation->participants()->where('user_id', $user->id)->delete();

        $this->createSystemMessage($conversation, sprintf('User #%d removed from conversation.', $user->id));
    }

    public function createMessage(
        Conversation $conversation,
        ?User $author,
        string $body,
        string $type = ConversationMessage::TYPE_TEXT,
        ?array $meta = null,
        ?string $clientMessageId = null,
    ): ConversationMessage {
        if ($author && $clientMessageId) {
            $existing = $this->findAuthoredMessage($conversation, $author, $clientMessageId);

            if ($existing) {
                return $this->ensureIdempotentMessageMatches($existing, $body, $type);
            }
        }

        try {
            $message = $conversation->messages()->create([
                'author_id' => $author?->id,
                'client_message_id' => $clientMessageId,
                'type' => $type,
                'body' => $body,
                'meta' => $meta,
            ]);
        } catch (QueryException $exception) {
            $existing = $author && $clientMessageId
                ? $this->findAuthoredMessage($conversation, $author, $clientMessageId)
                : null;

            if (! $existing) {
                throw $exception;
            }

            return $this->ensureIdempotentMessageMatches($existing, $body, $type);
        }

        $conversation->touch();

        if ($author) {
            $this->access->touchParticipantReadState($conversation, $author, $message);
        }

        $message->load('author.role');
        $this->realtime->publishMessageCreated($message);

        return $message;
    }

    public function createGuestMessage(
        Conversation $conversation,
        GuestSupportSession $guestSession,
        string $body,
        ?array $meta = null,
        ?string $clientMessageId = null,
    ): ConversationMessage {
        $ownsConversation = $conversation->supportThread()
            ->where('guest_session_id', $guestSession->id)
            ->exists();

        abort_unless($conversation->type === Conversation::TYPE_SUPPORT && $ownsConversation, 404);

        if ($clientMessageId) {
            $existing = $this->findGuestMessage($conversation, $guestSession, $clientMessageId);

            if ($existing) {
                return $this->ensureIdempotentMessageMatches(
                    $existing,
                    $body,
                    ConversationMessage::TYPE_TEXT,
                );
            }
        }

        try {
            $message = $conversation->messages()->create([
                'author_id' => null,
                'guest_session_id' => $guestSession->id,
                'client_message_id' => $clientMessageId,
                'type' => ConversationMessage::TYPE_TEXT,
                'body' => $body,
                'meta' => $meta,
            ]);
        } catch (QueryException $exception) {
            $existing = $clientMessageId
                ? $this->findGuestMessage($conversation, $guestSession, $clientMessageId)
                : null;

            if (! $existing) {
                throw $exception;
            }

            return $this->ensureIdempotentMessageMatches(
                $existing,
                $body,
                ConversationMessage::TYPE_TEXT,
            );
        }

        $conversation->touch();

        $message->load(['author.role', 'guestSession']);
        $this->realtime->publishMessageCreated($message);

        return $message;
    }

    public function markConversationRead(Conversation $conversation, User $user): void
    {
        $latestMessage = $conversation->latestMessage()->first();
        $this->access->touchParticipantReadState($conversation, $user, $latestMessage);
        $this->realtime->publishConversationRead($conversation, $user);
    }

    public function announceConversationCreated(Conversation $conversation): void
    {
        $this->realtime->publishConversationCreated($conversation);
    }

    private function attachParticipant(Conversation $conversation, User $user, string $role): ConversationParticipant
    {
        return ConversationParticipant::query()->updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'joined_at' => now(),
            ]
        );
    }

    private function createSystemMessage(Conversation $conversation, string $body, ?array $meta = null): ConversationMessage
    {
        return $this->createMessage($conversation, null, $body, ConversationMessage::TYPE_SYSTEM, $meta);
    }

    private function findAuthoredMessage(
        Conversation $conversation,
        User $author,
        string $clientMessageId,
    ): ?ConversationMessage {
        return $conversation->messages()
            ->where('author_id', $author->id)
            ->where('client_message_id', $clientMessageId)
            ->with('author.role')
            ->first();
    }

    private function findGuestMessage(
        Conversation $conversation,
        GuestSupportSession $guestSession,
        string $clientMessageId,
    ): ?ConversationMessage {
        return $conversation->messages()
            ->where('guest_session_id', $guestSession->id)
            ->where('client_message_id', $clientMessageId)
            ->with(['author.role', 'guestSession'])
            ->first();
    }

    private function ensureIdempotentMessageMatches(
        ConversationMessage $existing,
        string $body,
        string $type,
    ): ConversationMessage {
        abort_if(
            $existing->body !== $body || $existing->type !== $type,
            409,
            'client_message_id is already used for a different message.',
        );

        return $existing;
    }
}
