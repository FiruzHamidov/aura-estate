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
                'latestMessage.guestSession',
                'supportThread.requester.role',
                'supportThread.guestSession',
            ]);
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'type' => ['nullable', Rule::in(Conversation::types())],
            'kind' => ['nullable', Rule::in(['all', Conversation::KIND_PERSONAL, Conversation::KIND_SUPPORT])],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->baseQuery($authUser);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (($validated['kind'] ?? 'all') === Conversation::KIND_PERSONAL) {
            $query->where('type', Conversation::TYPE_DIRECT);
        } elseif (($validated['kind'] ?? 'all') === Conversation::KIND_SUPPORT) {
            $query->where('type', Conversation::TYPE_SUPPORT);
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
            'participant_ids.*' => 'integer|distinct',
            'meta' => 'nullable|array',
        ]);

        if (! $this->access->isInternalUser($authUser)) {
            abort_unless(count($validated['participant_ids']) === 1, 422, 'Direct conversations require exactly one participant.');

            $target = $this->access->eligibleDirectUsers($authUser)
                ->whereKey($validated['participant_ids'][0])
                ->firstOrFail();

            $conversation = $this->conversations->createOrGetDirectConversation($authUser, $target);

            return response()->json($this->serializeConversation($conversation, $authUser));
        }

        $request->validate([
            'participant_ids.*' => 'exists:users,id',
        ]);
        $participants = User::query()->whereIn('id', $validated['participant_ids'])->get()->all();

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
            'latestMessage.guestSession',
            'supportThread.requester.role',
            'supportThread.guestSession',
        ]);

        $this->conversations->markConversationRead($conversation, $authUser);

        return response()->json($this->serializeConversation($conversation, $authUser));
    }

    public function storeDirect(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'target_user_id' => 'required|integer',
        ]);

        $target = $this->access->eligibleDirectUsers($authUser)
            ->whereKey($validated['target_user_id'])
            ->firstOrFail();

        $conversation = $this->conversations->createOrGetDirectConversation($authUser, $target);

        return response()->json($this->serializeConversation($conversation, $authUser));
    }

    public function availableUsers(Request $request)
    {
        $authUser = $this->authUser();
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $this->access->eligibleDirectUsers($authUser, directoryOnly: true);

        if (filled($validated['search'] ?? null)) {
            $query->where('name', 'like', '%'.trim($validated['search']).'%');
        }

        $users = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (User $user) => $this->serializeDirectoryUser($user));

        return response()->json($users);
    }
}
