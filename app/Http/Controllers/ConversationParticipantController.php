<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ConversationParticipantController extends Controller
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

    public function index(Conversation $conversation)
    {
        $authUser = $this->authUser();
        $this->access->ensureAccessible($authUser, $conversation);

        $conversation->load('participants.user.role');

        return response()->json(
            $conversation->participants->map(fn ($participant) => [
                'user_id' => $participant->user_id,
                'role' => $participant->role,
                'joined_at' => $participant->joined_at?->toIso8601String(),
                'user' => $participant->user ? [
                    'id' => $participant->user->id,
                    'name' => $participant->user->name,
                    'phone' => $participant->user->phone,
                    'role_slug' => $participant->user->role?->slug,
                ] : null,
            ])->values()
        );
    }

    public function store(Request $request, Conversation $conversation)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => ['nullable', Rule::in(ConversationParticipant::roles())],
        ]);

        $target = User::query()->findOrFail($validated['user_id']);
        $this->access->ensureCanAddParticipant($authUser, $conversation, $target);

        $participant = $this->conversations->addParticipant(
            $conversation,
            $target,
            $validated['role'] ?? ConversationParticipant::ROLE_MEMBER
        );

        return response()->json([
            'user_id' => $participant->user_id,
            'role' => $participant->role,
            'joined_at' => $participant->joined_at?->toIso8601String(),
        ], 201);
    }

    public function destroy(Conversation $conversation, User $user)
    {
        $authUser = $this->authUser();
        $this->access->ensureCanRemoveParticipant($authUser, $conversation, $user);

        $this->conversations->removeParticipant($conversation, $user);

        return response()->json(['message' => 'Participant removed.']);
    }
}
