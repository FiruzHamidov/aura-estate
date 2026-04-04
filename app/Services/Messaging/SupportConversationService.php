<?php

namespace App\Services\Messaging;

use App\Models\ChatSession;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
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
                'name' => 'Support #'.now()->format('YmdHis'),
                'created_by' => $escalatedBy?->id ?: $requester->id,
                'meta' => $meta,
            ]);

            $conversation->participants()->create([
                'user_id' => $requester->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            foreach ($this->access->supportAssignableUsers()->get() as $supportUser) {
                $conversation->participants()->updateOrCreate(
                    ['user_id' => $supportUser->id],
                    ['role' => ConversationParticipant::ROLE_ADMIN, 'joined_at' => now()]
                );
            }

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

    public function resolveChatSession(?string $sessionUuid): ?ChatSession
    {
        if (! $sessionUuid) {
            return null;
        }

        return ChatSession::query()->where('session_uuid', $sessionUuid)->first();
    }
}
