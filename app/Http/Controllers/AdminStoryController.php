<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminStoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authUser($request);
        abort_unless($this->canAccessModerationList($actor), 403, 'Forbidden');

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

        $this->applyActorScope($query, $actor);

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
        abort_unless($this->canAccessModerationList($actor), 403, 'Forbidden');

        $validated = $request->validate([
            'action' => ['required', Rule::in(['archive', 'hide', 'republish'])],
        ]);

        $this->assertStoryInScope($actor, $story);

        if ($validated['action'] === 'archive') {
            $story->update(['status' => Story::STATUS_ARCHIVED]);
        }

        if ($validated['action'] === 'hide') {
            $story->update(['status' => Story::STATUS_HIDDEN]);
        }

        if ($validated['action'] === 'republish') {
            if ($story->type === Story::TYPE_REEL) {
                $activeReels = Story::query()
                    ->where('user_id', $story->user_id)
                    ->where('type', Story::TYPE_REEL)
                    ->where('status', Story::STATUS_ACTIVE)
                    ->where('id', '!=', $story->id)
                    ->count();

                abort_if($activeReels >= 10, 422, 'You cannot have more than 10 active reel stories.');
            }

            $startsAt = now();

            $story->update([
                'status' => Story::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'activated_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addDay(),
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

    private function canAccessModerationList(User $user): bool
    {
        return in_array($user->role?->slug, ['admin', 'superadmin', 'mop', 'rop', 'branch_director'], true);
    }

    private function applyActorScope(Builder $query, User $actor): void
    {
        $slug = $actor->role?->slug;

        if (in_array($slug, ['admin', 'superadmin'], true)) {
            return;
        }

        if ($slug === 'mop') {
            $query->whereHas('user', function (Builder $q) use ($actor) {
                $q->where('branch_group_id', $actor->branch_group_id);
            });

            return;
        }

        if (in_array($slug, ['rop', 'branch_director'], true)) {
            $query->whereHas('user', function (Builder $q) use ($actor) {
                $q->where('branch_id', $actor->branch_id);
            });
        }
    }

    private function assertStoryInScope(User $actor, Story $story): void
    {
        $slug = $actor->role?->slug;
        $story->loadMissing('user');

        if (in_array($slug, ['admin', 'superadmin'], true)) {
            return;
        }

        if ($slug === 'mop') {
            abort_unless(
                !empty($actor->branch_group_id)
                && !empty($story->user?->branch_group_id)
                && (int) $actor->branch_group_id === (int) $story->user->branch_group_id,
                403,
                'Forbidden'
            );

            return;
        }

        if (in_array($slug, ['rop', 'branch_director'], true)) {
            abort_unless(
                !empty($actor->branch_id)
                && !empty($story->user?->branch_id)
                && (int) $actor->branch_id === (int) $story->user->branch_id,
                403,
                'Forbidden'
            );
        }
    }
}
