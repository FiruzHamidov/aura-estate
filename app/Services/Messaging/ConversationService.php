<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(
        private readonly MessageAccessService $access
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

    public function createMessage(Conversation $conversation, ?User $author, string $body, string $type = ConversationMessage::TYPE_TEXT, ?array $meta = null): ConversationMessage
    {
        $message = $conversation->messages()->create([
            'author_id' => $author?->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
        ]);

        $conversation->touch();

        if ($author) {
            $this->access->touchParticipantReadState($conversation, $author, $message);
        }

        return $message->load('author.role');
    }

    public function markConversationRead(Conversation $conversation, User $user): void
    {
        $latestMessage = $conversation->latestMessage()->first();
        $this->access->touchParticipantReadState($conversation, $user, $latestMessage);
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
}
