<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SerializesConversationPayloads;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\GuestSupportSession;
use App\Models\SupportThread;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\GuestSupportSessionService;
use App\Services\Messaging\SupportConversationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuestSupportConversationController extends Controller
{
    use SerializesConversationPayloads;

    public function __construct(
        private readonly GuestSupportSessionService $sessions,
        private readonly SupportConversationService $support,
        private readonly ConversationService $conversations,
        private readonly NotificationService $notifications
    ) {}

    public function index(Request $request)
    {
        $session = $this->requiredSession($request);
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $threads = SupportThread::query()
            ->where('guest_session_id', $session->id)
            ->with($this->threadRelations())
            ->orderByDesc('updated_at')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (SupportThread $thread) => $this->serializeGuestThread($thread, $session));

        return response()->json($threads);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'initial_message' => 'required|string|min:2|max:5000',
            'source' => ['nullable', 'string', Rule::in(['guest_support', 'website', 'ai_chat', 'property_page'])],
            'context' => 'nullable|array:page_path,property_id|max:2',
            'context.page_path' => 'nullable|string|max:500',
            'context.property_id' => 'nullable|integer|min:1',
            'user_id' => 'prohibited',
            'author_id' => 'prohibited',
            'role' => 'prohibited',
            'sender_identity' => 'prohibited',
            'guest_session_id' => 'prohibited',
        ]);

        $session = $this->sessions->resolve($request);
        $token = null;

        if (! $session) {
            [$session, $token] = $this->sessions->issue($request);
        }

        $meta = array_filter([
            'source' => $validated['source'] ?? 'guest_support',
            'title' => $validated['title'] ?? null,
            'context' => $validated['context'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        [$thread, $message] = $this->support->createGuestSupportConversation(
            $session,
            $validated['initial_message'],
            $meta
        );
        $this->notifications->handleConversationMessageCreated($message);

        $response = response()->json($this->serializeGuestThread($thread, $session), 201);

        return $token ? $response->withCookie($this->sessions->cookie($token)) : $response;
    }

    public function show(Request $request, Conversation $conversation)
    {
        $session = $this->requiredSession($request);

        return response()->json($this->serializeGuestThread(
            $this->ownedThread($session, $conversation),
            $session
        ));
    }

    public function messages(Request $request, Conversation $conversation)
    {
        $session = $this->requiredSession($request);
        $thread = $this->ownedThread($session, $conversation);
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $messages = $thread->conversation->messages()
            ->with(['author.role', 'guestSession'])
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(fn (ConversationMessage $message) => $this->serializeMessage(
                $message,
                null,
                $thread->conversation,
                $session
            ));

        return response()->json($messages);
    }

    public function storeMessage(Request $request, Conversation $conversation)
    {
        $session = $this->requiredSession($request);
        $thread = $this->ownedThread($session, $conversation);
        abort_unless($thread->status === SupportThread::STATUS_OPEN, 409, 'Support conversation is closed.');

        $validated = $request->validate([
            'body' => 'required|string|min:1|max:5000',
            'user_id' => 'prohibited',
            'author_id' => 'prohibited',
            'role' => 'prohibited',
            'sender_identity' => 'prohibited',
            'guest_session_id' => 'prohibited',
        ]);

        $message = $this->conversations->createGuestMessage(
            $thread->conversation,
            $session,
            $validated['body'],
            ['source' => $thread->meta['source'] ?? 'guest_support']
        );
        $this->notifications->handleConversationMessageCreated($message);

        return response()->json(
            $this->serializeMessage($message, null, $thread->conversation, $session),
            201
        );
    }

    private function requiredSession(Request $request): GuestSupportSession
    {
        $session = $this->sessions->resolve($request);
        abort_unless($session, 401, 'Guest support session is required.');

        return $session;
    }

    private function ownedThread(GuestSupportSession $session, Conversation $conversation): SupportThread
    {
        abort_unless($conversation->type === Conversation::TYPE_SUPPORT, 404);

        return SupportThread::query()
            ->where('conversation_id', $conversation->id)
            ->where('guest_session_id', $session->id)
            ->with($this->threadRelations())
            ->firstOrFail();
    }

    private function serializeGuestThread(SupportThread $thread, GuestSupportSession $session): array
    {
        $conversation = $thread->conversation;

        return [
            'id' => $thread->id,
            'status' => $thread->status,
            'source' => $thread->meta['source'] ?? 'guest_support',
            'summary' => $thread->summary,
            'requester' => $this->serializeGuestIdentity($session),
            'responsibility' => $this->serializeResponsibility($conversation),
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'is_support' => true,
                'name' => $conversation->name,
                'latest_message' => $conversation->latestMessage
                    ? $this->serializeMessage($conversation->latestMessage, null, $conversation, $session)
                    : null,
            ],
        ];
    }

    private function threadRelations(): array
    {
        return [
            'guestSession',
            'conversation.participants.user.role',
            'conversation.latestMessage.author.role',
            'conversation.latestMessage.guestSession',
            'conversation.supportThread.guestSession',
        ];
    }
}
