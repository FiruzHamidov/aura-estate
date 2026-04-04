<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(
        private readonly MessageAccessService $access,
        private readonly ConversationService $conversations
    ) {}

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function baseQuery(User $authUser)
    {
        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $authUser->id))
            ->with([
                'creator.role',
                'participants.user.role',
                'latestMessage.author.role',
                'supportThread',
            ]);
    }

    private function serializeConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $conversation->name,
            'created_by' => $conversation->created_by,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'meta' => $conversation->meta,
            'latest_message' => $conversation->latestMessage ? [
                'id' => $conversation->latestMessage->id,
                'author_id' => $conversation->latestMessage->author_id,
                'type' => $conversation->latestMessage->type,
                'body' => $conversation->latestMessage->body,
                'created_at' => $conversation->latestMessage->created_at?->toIso8601String(),
            ] : null,
            'participants' => $conversation->participants->map(fn ($participant) => [
                'user_id' => $participant->user_id,
                'role' => $participant->role,
                'joined_at' => $participant->joined_at?->toIso8601String(),
                'last_read_message_id' => $participant->last_read_message_id,
                'last_read_at' => $participant->last_read_at?->toIso8601String(),
                'user' => $participant->user ? [
                    'id' => $participant->user->id,
                    'name' => $participant->user->name,
                    'phone' => $participant->user->phone,
                    'role_slug' => $participant->user->role?->slug,
                ] : null,
            ])->values()->all(),
            'support_thread' => $conversation->supportThread ? [
                'id' => $conversation->supportThread->id,
                'status' => $conversation->supportThread->status,
                'requester_user_id' => $conversation->supportThread->requester_user_id,
                'chat_session_id' => $conversation->supportThread->chat_session_id,
                'summary' => $conversation->supportThread->summary,
            ] : null,
        ];
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'type' => ['nullable', Rule::in(Conversation::types())],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->baseQuery($authUser);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $conversations = $query
            ->orderByDesc('updated_at')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (Conversation $conversation) => $this->serializeConversation($conversation));

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'integer|distinct|exists:users,id',
            'meta' => 'nullable|array',
        ]);

        $participants = User::query()->whereIn('id', $validated['participant_ids'])->get()->all();

        $conversation = $this->conversations->createGroupConversation(
            $authUser,
            $validated['name'],
            $participants,
            $validated['meta'] ?? null
        );

        return response()->json($this->serializeConversation($conversation), 201);
    }

    public function show(Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureAccessible($authUser, $conversation);

        $conversation->load([
            'creator.role',
            'participants.user.role',
            'latestMessage.author.role',
            'supportThread',
        ]);

        $this->conversations->markConversationRead($conversation, $authUser);

        return response()->json($this->serializeConversation($conversation));
    }

    public function storeDirect(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'target_user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::query()->findOrFail($validated['target_user_id']);

        $conversation = $this->conversations->createOrGetDirectConversation($authUser, $target);

        return response()->json($this->serializeConversation($conversation));
    }
}
