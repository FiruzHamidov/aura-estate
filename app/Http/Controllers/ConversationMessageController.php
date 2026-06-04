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
            ->with('author.role')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(fn (ConversationMessage $message) => $this->serializeMessage($message, $authUser, $conversation));

        $this->conversations->markConversationRead($conversation, $authUser);

        return response()->json($messages);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureCanSend($authUser, $conversation);

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        $message = $this->conversations->createMessage(
            $conversation,
            $authUser,
            $validated['body']
        );

        Log::info('Conversation message created, starting notification pipeline.', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'author_id' => $authUser->id,
            'body_preview' => mb_strimwidth((string) $validated['body'], 0, 80, '...'),
        ]);

        $this->notifications->handleConversationMessageCreated($message);

        return response()->json($this->serializeMessage($message, $authUser, $conversation), 201);
    }
}
