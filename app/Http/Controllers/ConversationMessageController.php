<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SerializesConversationPayloads;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ConversationMessageController extends Controller
{
    use SerializesConversationPayloads;

    public function __construct(
        private readonly MessageAccessService $access,
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

    public function index(Request $request, Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureAccessible($authUser, $conversation);

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $messages = $conversation->messages()
            ->with(['author.role', 'guestSession'])
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(fn (ConversationMessage $message) => $this->serializeMessage($message, $authUser, $conversation));

        return response()->json($messages);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureCanSend($authUser, $conversation);

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'client_message_id' => 'nullable|uuid',
        ]);

        $message = $this->conversations->createMessage(
            $conversation,
            $authUser,
            $validated['body'],
            clientMessageId: $validated['client_message_id'] ?? null,
        );

        if ($message->wasRecentlyCreated) {
            Log::info('Conversation message created, starting notification pipeline.', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'author_id' => $authUser->id,
                'body_preview' => mb_strimwidth((string) $validated['body'], 0, 80, '...'),
            ]);

            $this->notifications->handleConversationMessageCreated($message);
        }

        return response()->json(
            $this->serializeMessage($message, $authUser, $conversation),
            $message->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function markRead(Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureAccessible($authUser, $conversation);
        $this->conversations->markConversationRead($conversation, $authUser);

        $participant = $conversation->participants()
            ->where('user_id', $authUser->id)
            ->firstOrFail();

        return response()->json([
            'conversation_id' => (int) $conversation->id,
            'unread_count' => 0,
            'last_read_message_id' => $participant->last_read_message_id
                ? (int) $participant->last_read_message_id
                : null,
            'last_read_at' => $participant->last_read_at?->toIso8601String(),
        ]);
    }
}
