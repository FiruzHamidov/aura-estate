<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\Messaging\MessageAccessService;
use App\Services\Messaging\SupportConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportConversationController extends Controller
{
    public function __construct(
        private readonly MessageAccessService $access,
        private readonly SupportConversationService $support
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
                'chatSession',
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
            ]);
    }

    private function serializeThread(SupportThread $thread): array
    {
        return [
            'id' => $thread->id,
            'status' => $thread->status,
            'summary' => $thread->summary,
            'requester_user_id' => $thread->requester_user_id,
            'chat_session_id' => $thread->chatSession?->session_uuid,
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'requester' => $thread->requester ? [
                'id' => $thread->requester->id,
                'name' => $thread->requester->name,
                'role_slug' => $thread->requester->role?->slug,
            ] : null,
            'conversation' => [
                'id' => $thread->conversation->id,
                'type' => $thread->conversation->type,
                'name' => $thread->conversation->name,
                'latest_message' => $thread->conversation->latestMessage ? [
                    'id' => $thread->conversation->latestMessage->id,
                    'author_id' => $thread->conversation->latestMessage->author_id,
                    'type' => $thread->conversation->latestMessage->type,
                    'body' => $thread->conversation->latestMessage->body,
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
            'summary' => 'nullable|string|max:5000',
            'meta' => 'nullable|array',
        ]);

        $chatSession = $this->support->resolveChatSession($validated['chat_session_id'] ?? null);

        $thread = $this->support->createOrGetSupportConversation(
            $authUser,
            $chatSession,
            $authUser,
            $validated['summary'] ?? null,
            $validated['meta'] ?? null
        );

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
                'chatSession',
                'conversation.participants.user.role',
                'conversation.latestMessage.author.role',
            ])
            ->firstOrFail();

        return response()->json($this->serializeThread($thread));
    }
}
