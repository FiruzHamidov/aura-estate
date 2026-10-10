<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\User;
use App\Support\StoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminStoryController extends Controller
{
    public function __construct(private readonly StoryAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authUser($request);
        abort_unless($this->access->canViewModeration($actor), 403, 'Forbidden');

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Story::STATUS_DRAFT,
                Story::STATUS_ACTIVE,
                Story::STATUS_ARCHIVED,
                Story::STATUS_HIDDEN,
                Story::STATUS_DELETED,
            ])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Story::query()
            ->with(['user:id,name,role_id,branch_id,branch_group_id', 'user.role:id,name,slug', 'items', 'attachments'])
            ->orderByDesc('id');

        $this->access->scopeModeration($query, $actor);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 20)));
    }

    public function updateStatus(Request $request, Story $story): JsonResponse
    {
        $actor = $this->authUser($request);
        abort_unless($this->access->canViewModeration($actor), 403, 'Forbidden');

        $validated = $request->validate([
            'action' => ['required', Rule::in(['archive', 'hide', 'republish'])],
        ]);

        abort_unless($actor->hasPermission('stories.moderation.status') && $this->access->inModerationScope($actor, $story), 403, 'Forbidden');

        if ($validated['action'] === 'archive') {
            $story->update(['status' => Story::STATUS_ARCHIVED]);
        }

        if ($validated['action'] === 'hide') {
            $story->update(['status' => Story::STATUS_HIDDEN]);
        }

        if ($validated['action'] === 'republish') {
            $activeStories = Story::query()
                ->where('user_id', $story->user_id)
                ->where('status', Story::STATUS_ACTIVE)
                ->where('id', '!=', $story->id)
                ->count();

            abort_if(
                $activeStories >= (int) config('stories.active_per_user_limit', 30),
                422,
                'You cannot have more active stories.'
            );

            if ($story->type === Story::TYPE_REEL) {
                $activeReels = Story::query()
                    ->where('user_id', $story->user_id)
                    ->where('type', Story::TYPE_REEL)
                    ->where('status', Story::STATUS_ACTIVE)
                    ->where('id', '!=', $story->id)
                    ->count();

                abort_if(
                    $activeReels >= (int) config('stories.active_reel_per_user_limit', 10),
                    422,
                    'You cannot have more active reel stories.'
                );
            }

            $startsAt = now();

            $story->update([
                'status' => Story::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'activated_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addHours((int) config('stories.ttl_hours', 24)),
            ]);
        }

        $story->loadMissing(['user:id,name,role_id,branch_id,branch_group_id', 'user.role:id,name,slug', 'items', 'attachments']);

        return response()->json($story);
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }
}
