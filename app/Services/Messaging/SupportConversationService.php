<?php

namespace App\Services\Messaging;

use App\Models\ChatSession;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\GuestSupportSession;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupportConversationService
{
    public function __construct(
        private readonly MessageAccessService $access,
        private readonly ConversationService $conversations
    ) {}

    public function createOrGetSupportConversation(
        User $requester,
        ?ChatSession $chatSession = null,
        ?User $escalatedBy = null,
        ?string $summary = null,
        ?array $meta = null
    ): SupportThread {
        $this->access->ensureCanCreateSupportConversation($requester);

        $existing = SupportThread::query()
            ->when($chatSession, fn ($query) => $query->where('chat_session_id', $chatSession->id))
            ->where('requester_user_id', $requester->id)
            ->where('status', SupportThread::STATUS_OPEN)
            ->first();

        if ($existing) {
            return $existing->load([
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
                'requester.role',
                'chatSession',
            ]);
        }

        return DB::transaction(function () use ($requester, $chatSession, $escalatedBy, $summary, $meta) {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_SUPPORT,
                'name' => filled($meta['title'] ?? null)
                    ? (string) $meta['title']
                    : 'Support #'.now()->format('YmdHis'),
                'created_by' => $escalatedBy?->id ?: $requester->id,
                'meta' => $meta,
            ]);

            $conversation->participants()->create([
                'user_id' => $requester->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            $this->attachSupportUsers($conversation);

            $thread = SupportThread::query()->create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => $requester->id,
                'chat_session_id' => $chatSession?->id,
                'escalated_by_user_id' => $escalatedBy?->id,
                'status' => SupportThread::STATUS_OPEN,
                'summary' => $summary,
                'meta' => $meta,
            ]);

            $this->conversations->createMessage(
                $conversation,
                null,
                'Support conversation created.',
                \App\Models\ConversationMessage::TYPE_SYSTEM,
                array_filter([
                    'chat_session_id' => $chatSession?->id,
                    'summary' => $summary,
                ], fn ($value) => $value !== null && $value !== '')
            );

            return $thread->load([
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
                'requester.role',
                'chatSession',
            ]);
        });
    }

    /** @return array{0: SupportThread, 1: \App\Models\ConversationMessage} */
    public function createGuestSupportConversation(
        GuestSupportSession $guestSession,
        string $initialMessage,
        ?array $meta = null
    ): array {
        return DB::transaction(function () use ($guestSession, $initialMessage, $meta) {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_SUPPORT,
                'name' => filled($meta['title'] ?? null)
                    ? (string) $meta['title']
                    : 'Гость / потенциальный клиент',
                'created_by' => null,
                'meta' => $meta,
            ]);

            $this->attachSupportUsers($conversation);

            $thread = SupportThread::query()->create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => null,
                'guest_session_id' => $guestSession->id,
                'status' => SupportThread::STATUS_OPEN,
                'summary' => $initialMessage,
                'meta' => $meta,
            ]);

            $this->conversations->createMessage(
                $conversation,
                null,
                'Guest support conversation created.',
                \App\Models\ConversationMessage::TYPE_SYSTEM,
                ['source' => $meta['source'] ?? 'guest_support']
            );

            $message = $this->conversations->createGuestMessage(
                $conversation,
                $guestSession,
                $initialMessage,
                ['source' => $meta['source'] ?? 'guest_support']
            );

            $thread->load([
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
                'conversation.latestMessage.guestSession',
                'guestSession',
            ]);

            return [$thread, $message];
        });
    }

    public function resolveChatSession(?string $sessionUuid): ?ChatSession
    {
        if (! $sessionUuid) {
            return null;
        }

        return ChatSession::query()->where('session_uuid', $sessionUuid)->first();
    }

    private function attachSupportUsers(Conversation $conversation): void
    {
        foreach ($this->access->supportAssignableUsers()->get() as $supportUser) {
            $conversation->participants()->updateOrCreate(
                ['user_id' => $supportUser->id],
                ['role' => ConversationParticipant::ROLE_ADMIN, 'joined_at' => now()]
            );
        }
    }
}
