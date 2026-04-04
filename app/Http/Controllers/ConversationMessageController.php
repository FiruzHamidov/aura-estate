<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConversationMessageController extends Controller
{
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

    private function serializeMessage(ConversationMessage $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'author_id' => $message->author_id,
            'type' => $message->type,
            'body' => $message->body,
            'meta' => $message->meta,
            'created_at' => $message->created_at?->toIso8601String(),
            'author' => $message->author ? [
                'id' => $message->author->id,
                'name' => $message->author->name,
                'role_slug' => $message->author->role?->slug,
            ] : null,
        ];
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
            ->through(fn (ConversationMessage $message) => $this->serializeMessage($message));

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

        $this->notifications->handleConversationMessageCreated($message);

        return response()->json($this->serializeMessage($message), 201);
    }
}
