<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use App\Services\Messaging\SupportConversationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportConversationController extends Controller
{
    public function __construct(
        private readonly MessageAccessService $access,
        private readonly SupportConversationService $support,
        private readonly ConversationService $conversations,
        private readonly NotificationService $notifications
    ) {}

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function queryForUser(User $authUser)
    {
        return SupportThread::query()
            ->whereHas('conversation.participants', fn ($query) => $query->where('user_id', $authUser->id))
            ->with([
                'requester.role',
                'guestSession',
                'chatSession',
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
                'conversation.latestMessage.guestSession',
            ]);
    }

    private function serializeThread(SupportThread $thread): array
    {
        $requesterKind = match ($thread->requester?->role?->slug) {
            'client' => 'client',
            'agent' => 'agent',
            'manager', 'operator', 'admin', 'superadmin' => 'support_staff',
            default => 'internal_user',
        };
        $latestAuthorId = $thread->conversation->latestMessage?->author_id;
        $latestGuestSessionId = $thread->conversation->latestMessage?->guest_session_id;
        $eligibleResponderIds = $thread->conversation->participants
            ->filter(fn ($participant) => in_array(
                $participant->user?->role?->slug,
                ['manager', 'operator', 'admin', 'superadmin'],
                true
            ))
            ->pluck('user_id')
            ->values()
            ->all();

        return [
            'id' => $thread->id,
            'kind' => Conversation::KIND_SUPPORT,
            'kind_label' => 'Поддержка',
            'status' => $thread->status,
            'summary' => $thread->summary,
            'requester_user_id' => $thread->requester_user_id,
            'guest_session_id' => $thread->guestSession?->public_id,
            'chat_session_id' => $thread->chatSession?->session_uuid,
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'requester' => $thread->requester ? [
                'id' => $thread->requester->id,
                'name' => $thread->requester->name,
                'role_slug' => $thread->requester->role?->slug,
                'kind' => $requesterKind,
            ] : ($thread->guestSession ? [
                'id' => $thread->guestSession->public_id,
                'name' => 'Гость / потенциальный клиент без аккаунта',
                'role_slug' => null,
                'kind' => 'guest',
            ] : null),
            'source' => $thread->meta['source'] ?? ($thread->chat_session_id ? 'chat' : 'support_form'),
            'context' => $thread->meta['context'] ?? null,
            'responsibility' => [
                'queue' => 'support',
                'assigned_to_user_id' => null,
                'eligible_responder_user_ids' => $eligibleResponderIds,
                'response_required_from' => ! $latestAuthorId && ! $latestGuestSessionId
                    ? 'none'
                    : (($thread->requester_user_id
                        && (int) $latestAuthorId === (int) $thread->requester_user_id)
                        || ($thread->guest_session_id
                            && (int) $latestGuestSessionId === (int) $thread->guest_session_id)
                            ? 'support_staff'
                            : 'requester'),
            ],
            'conversation' => [
                'id' => $thread->conversation->id,
                'type' => $thread->conversation->type,
                'kind' => Conversation::KIND_SUPPORT,
                'kind_label' => 'Поддержка',
                'name' => $thread->conversation->name,
                'counterpart' => null,
                'source' => $thread->meta['source'] ?? ($thread->chat_session_id ? 'chat' : 'support_form'),
                'context' => $thread->meta['context'] ?? null,
                'latest_message' => $thread->conversation->latestMessage ? [
                    'id' => $thread->conversation->latestMessage->id,
                    'author_id' => $thread->conversation->latestMessage->author_id,
                    'type' => $thread->conversation->latestMessage->type,
                    'body' => $thread->conversation->latestMessage->body,
                    'sender_identity' => $thread->conversation->latestMessage->guestSession ? [
                        'kind' => 'guest',
                        'id' => $thread->conversation->latestMessage->guestSession->public_id,
                        'name' => 'Гость / потенциальный клиент без аккаунта',
                        'role_slug' => null,
                    ] : ($thread->conversation->latestMessage->author ? [
                        'kind' => match ($thread->conversation->latestMessage->author->role?->slug) {
                            'client' => 'client',
                            'agent' => 'agent',
                            'manager', 'operator', 'admin', 'superadmin' => 'support_staff',
                            default => 'internal_user',
                        },
                        'id' => $thread->conversation->latestMessage->author->id,
                        'name' => $thread->conversation->latestMessage->author->name,
                        'role_slug' => $thread->conversation->latestMessage->author->role?->slug,
                    ] : [
                        'kind' => 'system',
                        'id' => null,
                        'name' => 'System',
                        'role_slug' => null,
                    ]),
                    'created_at' => $thread->conversation->latestMessage->created_at?->toIso8601String(),
                ] : null,
            ],
        ];
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'status' => ['nullable', \Illuminate\Validation\Rule::in(SupportThread::statuses())],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->queryForUser($authUser);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $threads = $query
            ->orderByDesc('updated_at')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (SupportThread $thread) => $this->serializeThread($thread));

        return response()->json($threads);
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'chat_session_id' => 'nullable|string|max:100',
            'session_id' => 'nullable|string|max:100',
            'title' => 'nullable|string|max:255',
            'initial_message' => 'nullable|string|max:10000',
            'message' => 'nullable|string|max:10000',
            'source' => 'nullable|string|max:100',
            'summary' => 'nullable|string|max:5000',
            'meta' => 'nullable|array',
        ]);

        $sessionId = $validated['chat_session_id'] ?? $validated['session_id'] ?? null;
        $chatSession = $this->support->resolveChatSession($sessionId);
        $initialMessage = $validated['initial_message'] ?? $validated['message'] ?? null;
        $meta = array_filter([
            ...($validated['meta'] ?? []),
            'source' => $validated['source'] ?? ($chatSession ? 'chat' : 'support_form'),
            'title' => $validated['title'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $thread = $this->support->createOrGetSupportConversation(
            $authUser,
            $chatSession,
            $authUser,
            $validated['summary'] ?? $initialMessage,
            $meta
        );

        if (filled($initialMessage)) {
            $message = $this->conversations->createMessage(
                $thread->conversation,
                $authUser,
                (string) $initialMessage,
                meta: ['source' => $meta['source']]
            );
            $this->notifications->handleConversationMessageCreated($message);
            $thread->load('conversation.latestMessage.author.role');
        }

        return response()->json($this->serializeThread($thread), 201);
    }

    public function show(Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureAccessible($authUser, $conversation);

        abort_unless($conversation->type === Conversation::TYPE_SUPPORT, 404, 'Support conversation not found.');

        $thread = SupportThread::query()
            ->where('conversation_id', $conversation->id)
            ->with([
                'requester.role',
                'guestSession',
                'chatSession',
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
                'conversation.latestMessage.guestSession',
            ])
            ->firstOrFail();

        return response()->json($this->serializeThread($thread));
    }
}
