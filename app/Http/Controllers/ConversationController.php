<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SerializesConversationPayloads;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    use SerializesConversationPayloads;

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
            ->through(fn (Conversation $conversation) => $this->serializeConversation($conversation, $authUser));

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'integer|distinct|exists:users,id',
            'meta' => 'nullable|array',
        ]);

        $participants = User::query()->whereIn('id', $validated['participant_ids'])->get()->all();

        if (! $this->access->isInternalUser($authUser)) {
            abort_unless(count($participants) === 1, 422, 'Direct conversations require exactly one participant.');

            $conversation = $this->conversations->createOrGetDirectConversation($authUser, $participants[0]);

            return response()->json($this->serializeConversation($conversation, $authUser));
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $conversation = $this->conversations->createGroupConversation(
            $authUser,
            $validated['name'],
            $participants,
            $validated['meta'] ?? null
        );

        return response()->json($this->serializeConversation($conversation, $authUser), 201);
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

        return response()->json($this->serializeConversation($conversation, $authUser));
    }

    public function storeDirect(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'target_user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::query()->findOrFail($validated['target_user_id']);

        $conversation = $this->conversations->createOrGetDirectConversation($authUser, $target);

        return response()->json($this->serializeConversation($conversation, $authUser));
    }
}
