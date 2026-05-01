<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Reel;
use App\Models\Story;
use App\Models\StoryAttachment;
use App\Models\StoryItem;
use App\Models\StoryView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoryController extends Controller
{
    private const MANAGER_ROLE_SLUGS = ['mop', 'rop', 'branch_director'];

    public function feed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $query = Story::query()
            ->publicFeed()
            ->with([
                'user:id,name,photo,role_id,branch_id,branch_group_id',
                'user.role:id,name,slug',
                'items',
                'attachments',
            ])
            ->whereHas('user', fn (Builder $q) => $q->where('status', User::STATUS_ACTIVE))
            ->orderByDesc('activated_at')
            ->orderByDesc('id');

        if (!empty($validated['author_id'])) {
            $query->where('user_id', (int) $validated['author_id']);
        }

        $stories = $query->limit($limit)->get();

        $grouped = $stories->groupBy('user_id')->values()->map(function ($group) {
            $first = $group->first();

            return [
                'author' => $this->serializeAuthor($first->user),
                'stories' => $group->map(fn (Story $story) => $this->serializeStory($story))->values(),
            ];
        });

        return response()->json(['data' => $grouped]);
    }

    public function show(Story $story): JsonResponse
    {
        abort_unless($this->isStoryPubliclyVisible($story), 404, 'Story not found');

        $story->loadMissing([
            'user:id,name,photo,role_id,branch_id,branch_group_id',
            'user.role:id,name,slug',
            'items',
            'attachments',
        ]);

        return response()->json($this->serializeStory($story));
    }

    public function trackView(Request $request, Story $story): JsonResponse
    {
        abort_unless($this->isStoryPubliclyVisible($story), 404, 'Story not found');

        $actor = $this->resolveViewActor($request);
        abort_if(empty($actor['viewer_user_id']) && empty($actor['guest_token']), 422, 'Guest token is required.');

        $payload = [
            'story_id' => $story->id,
            'viewer_user_id' => $actor['viewer_user_id'],
            'guest_token' => $actor['guest_token'],
            'viewed_at' => now(),
        ];

        if ($actor['viewer_user_id']) {
            StoryView::query()->updateOrCreate(
                ['story_id' => $story->id, 'viewer_user_id' => $actor['viewer_user_id']],
                ['guest_token' => null, 'viewed_at' => now()]
            );
        } else {
            StoryView::query()->updateOrCreate(
                ['story_id' => $story->id, 'guest_token' => $actor['guest_token']],
                ['viewer_user_id' => null, 'viewed_at' => now()]
            );
        }

        Story::query()->whereKey($story->id)->increment('views_count');

        return response()->json(['message' => 'View tracked', 'payload' => $payload]);
    }

    public function myStories(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Story::STATUS_DRAFT,
                Story::STATUS_ACTIVE,
                Story::STATUS_ARCHIVED,
                Story::STATUS_HIDDEN,
                Story::STATUS_DELETED,
                'history',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Story::query()
            ->with(['items', 'attachments', 'user:id,name,photo,role_id', 'user.role:id,name,slug'])
            ->where('user_id', $user->id)
            ->orderByDesc('id');

        $status = $validated['status'] ?? null;

        if ($status === 'history') {
            $query->whereIn('status', [Story::STATUS_ARCHIVED, Story::STATUS_HIDDEN, Story::STATUS_DELETED]);
        } elseif ($status) {
            $query->where('status', $status);
        }

        $stories = $query->paginate((int) ($validated['per_page'] ?? 20))
            ->through(fn (Story $story) => $this->serializeStory($story));

        return response()->json($stories);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        $this->ensureCanCreateStory($user);

        $validated = $request->validate([
            'type' => ['required', Rule::in([Story::TYPE_MEDIA, Story::TYPE_PROPERTY, Story::TYPE_REEL])],
            'caption' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in([Story::STATUS_DRAFT, Story::STATUS_ACTIVE])],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.media_type' => ['required_with:items', Rule::in(['image', 'video'])],
            'items.*.media_url' => ['required_with:items', 'string', 'max:2048'],
            'items.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'items.*.duration_sec' => ['nullable', 'integer', 'min:1', 'max:60'],
            'items.*.meta' => ['nullable', 'array'],
        ]);

        $story = DB::transaction(function () use ($validated, $user) {
            $status = $validated['status'] ?? Story::STATUS_DRAFT;
            $story = Story::query()->create([
                'user_id' => $user->id,
                'type' => $validated['type'],
                'caption' => $validated['caption'] ?? null,
                'status' => $status,
                'starts_at' => !empty($validated['starts_at']) ? $validated['starts_at'] : null,
            ]);

            $this->syncItems($story, $validated['items'] ?? []);

            if ($status === Story::STATUS_ACTIVE) {
                $this->activateStory($story);
            }

            return $story->fresh(['items', 'attachments', 'user.role']);
        });

        return response()->json($this->serializeStory($story), 201);
    }

    public function storeFromProperty(Request $request, Property $property): JsonResponse
    {
        $user = $this->authUser($request);
        $this->ensureCanCreateStory($user);
        $this->ensurePropertyAttachable($property);

        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in([Story::STATUS_DRAFT, Story::STATUS_ACTIVE])],
        ]);

        $story = DB::transaction(function () use ($validated, $user, $property) {
            $status = $validated['status'] ?? Story::STATUS_ACTIVE;

            $story = Story::query()->create([
                'user_id' => $user->id,
                'type' => Story::TYPE_PROPERTY,
                'caption' => $validated['caption'] ?? null,
                'status' => $status,
                'starts_at' => now(),
            ]);

            StoryAttachment::query()->create([
                'story_id' => $story->id,
                'attachable_type' => Property::class,
                'attachable_id' => $property->id,
                'snapshot_json' => [
                    'id' => $property->id,
                    'title' => $property->title,
                    'price' => $property->price,
                    'currency' => $property->currency,
                    'district' => $property->district,
                    'address' => $property->address,
                    'photo' => $property->photos()->select('file_path')->value('file_path'),
                ],
            ]);

            if ($status === Story::STATUS_ACTIVE) {
                $this->activateStory($story);
            }

            return $story->fresh(['items', 'attachments', 'user.role']);
        });

        return response()->json($this->serializeStory($story), 201);
    }

    public function storeFromReel(Request $request, Reel $reel): JsonResponse
    {
        $user = $this->authUser($request);
        $this->ensureCanCreateStory($user);
        $this->ensureReelAttachable($reel);

        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in([Story::STATUS_DRAFT, Story::STATUS_ACTIVE])],
        ]);

        $story = DB::transaction(function () use ($validated, $user, $reel) {
            $status = $validated['status'] ?? Story::STATUS_ACTIVE;

            $story = Story::query()->create([
                'user_id' => $user->id,
                'type' => Story::TYPE_REEL,
                'caption' => $validated['caption'] ?? null,
                'status' => $status,
                'starts_at' => now(),
            ]);

            StoryAttachment::query()->create([
                'story_id' => $story->id,
                'attachable_type' => Reel::class,
                'attachable_id' => $reel->id,
                'snapshot_json' => [
                    'id' => $reel->id,
                    'title' => $reel->title,
                    'description' => $reel->description,
                    'preview_image' => $reel->preview_image,
                    'thumbnail_url' => $reel->thumbnail_url,
                    'hls_url' => $reel->hls_url,
                    'mp4_url' => $reel->mp4_url,
                    'video_url' => $reel->video_url,
                ],
            ]);

            if ($status === Story::STATUS_ACTIVE) {
                $this->activateStory($story);
            }

            return $story->fresh(['items', 'attachments', 'user.role']);
        });

        return response()->json($this->serializeStory($story), 201);
    }

    public function update(Request $request, Story $story): JsonResponse
    {
        $user = $this->authUser($request);
        abort_unless((int) $story->user_id === (int) $user->id || $this->isAdmin($user), 403, 'Forbidden');

        if ($user->hasRole('client') && !$this->isAdmin($user)) {
            abort(403, 'Clients cannot modify stories.');
        }

        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.media_type' => ['required_with:items', Rule::in(['image', 'video'])],
            'items.*.media_url' => ['required_with:items', 'string', 'max:2048'],
            'items.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'items.*.duration_sec' => ['nullable', 'integer', 'min:1', 'max:60'],
            'items.*.meta' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($story, $validated) {
            $story->fill([
                'caption' => $validated['caption'] ?? $story->caption,
                'starts_at' => array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $story->starts_at,
            ])->save();

            if (array_key_exists('items', $validated)) {
                $this->syncItems($story, $validated['items']);
            }
        });

        $story->refresh()->load(['items', 'attachments', 'user.role']);

        return response()->json($this->serializeStory($story));
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        $user = $this->authUser($request);
        abort_unless((int) $story->user_id === (int) $user->id || $this->isAdmin($user), 403, 'Forbidden');

        if ($user->hasRole('client') && !$this->isAdmin($user)) {
            abort(403, 'Clients cannot delete stories.');
        }

        $story->update(['status' => Story::STATUS_DELETED]);

        return response()->json(['message' => 'Story deleted']);
    }

    public function changeStatus(Request $request, Story $story): JsonResponse
    {
        $user = $this->authUser($request);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['archive', 'hide', 'republish'])],
        ]);

        $action = $validated['action'];
        $isOwner = (int) $story->user_id === (int) $user->id;
        $isManager = $this->isManager($user);
        $isAdmin = $this->isAdmin($user);

        abort_if($user->hasRole('client') && !$isAdmin, 403, 'Clients cannot manage stories.');

        if ($action === 'republish') {
            $canRepublish = $isOwner || $isAdmin || ($isManager && $this->canManagerControlStory($user, $story));
            abort_unless($canRepublish, 403, 'Forbidden');

            $this->activateStory($story, true);
        }

        if (in_array($action, ['archive', 'hide'], true)) {
            $canManage = $isOwner || $isAdmin || ($isManager && $this->canManagerControlStory($user, $story));
            abort_unless($canManage, 403, 'Forbidden');

            $status = $action === 'archive' ? Story::STATUS_ARCHIVED : Story::STATUS_HIDDEN;
            $story->update(['status' => $status]);
        }

        $story->refresh()->load(['items', 'attachments', 'user.role']);

        return response()->json($this->serializeStory($story));
    }

    private function syncItems(Story $story, array $items): void
    {
        StoryItem::query()->where('story_id', $story->id)->delete();

        foreach (array_values($items) as $index => $item) {
            StoryItem::query()->create([
                'story_id' => $story->id,
                'position' => $index + 1,
                'media_type' => $item['media_type'],
                'media_url' => $item['media_url'],
                'thumbnail_url' => $item['thumbnail_url'] ?? null,
                'duration_sec' => (int) ($item['duration_sec'] ?? 5),
                'meta' => $item['meta'] ?? null,
            ]);
        }
    }

    private function activateStory(Story $story, bool $force = false): void
    {
        if ($story->type === Story::TYPE_REEL) {
            $activeReelStories = Story::query()
                ->where('user_id', $story->user_id)
                ->where('type', Story::TYPE_REEL)
                ->where('status', Story::STATUS_ACTIVE)
                ->when($force, fn (Builder $q) => $q->where('id', '!=', $story->id))
                ->count();

            abort_if($activeReelStories >= 10, 422, 'You cannot have more than 10 active reel stories.');
        }

        $startsAt = $story->starts_at ?: now();
        $expiresAt = $startsAt->copy()->addDay();

        $story->update([
            'status' => Story::STATUS_ACTIVE,
            'activated_at' => $startsAt,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
        ]);
    }

    private function ensureCanCreateStory(User $user): void
    {
        abort_if($user->role?->slug === 'client', 403, 'Clients cannot create stories.');
        abort_if($user->status !== User::STATUS_ACTIVE, 403, 'Only active users can create stories.');
    }

    private function ensurePropertyAttachable(Property $property): void
    {
        abort_if($property->moderation_status === 'deleted', 422, 'Property is not available for stories.');
    }

    private function ensureReelAttachable(Reel $reel): void
    {
        $isPublished = Reel::query()->published()->whereKey($reel->id)->exists();
        abort_if(!$isPublished, 422, 'Reel is not available for stories.');
    }

    private function authUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function resolveViewActor(Request $request): array
    {
        $user = $request->user();
        if ($user) {
            return ['viewer_user_id' => $user->id, 'guest_token' => null];
        }

        $guestToken = trim((string) $request->header('X-Guest-Token', $request->input('guest_token', '')));

        return ['viewer_user_id' => null, 'guest_token' => $guestToken !== '' ? $guestToken : null];
    }

    private function isStoryPubliclyVisible(Story $story): bool
    {
        return Story::query()
            ->publicFeed()
            ->whereKey($story->id)
            ->exists();
    }

    private function isManager(User $user): bool
    {
        return in_array($user->role?->slug, self::MANAGER_ROLE_SLUGS, true);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->role?->slug, ['admin', 'superadmin'], true);
    }

    private function canManagerControlStory(User $manager, Story $story): bool
    {
        $manager->loadMissing('role');
        $story->loadMissing('user.role');
        $owner = $story->user;

        if (!$owner) {
            return false;
        }

        if ($manager->role?->slug === 'mop') {
            return !empty($manager->branch_group_id)
                && !empty($owner->branch_group_id)
                && (int) $manager->branch_group_id === (int) $owner->branch_group_id;
        }

        if (in_array($manager->role?->slug, ['rop', 'branch_director'], true)) {
            return !empty($manager->branch_id)
                && !empty($owner->branch_id)
                && (int) $manager->branch_id === (int) $owner->branch_id;
        }

        return false;
    }

    private function serializeStory(Story $story): array
    {
        return [
            'id' => $story->id,
            'user_id' => $story->user_id,
            'author' => $story->relationLoaded('user') ? $this->serializeAuthor($story->user) : null,
            'type' => $story->type,
            'status' => $story->status,
            'caption' => $story->caption,
            'starts_at' => optional($story->starts_at)->toISOString(),
            'activated_at' => optional($story->activated_at)->toISOString(),
            'expires_at' => optional($story->expires_at)->toISOString(),
            'views_count' => (int) $story->views_count,
            'items' => $story->relationLoaded('items') ? $story->items->map(fn (StoryItem $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'media_type' => $item->media_type,
                'media_url' => $item->media_url,
                'thumbnail_url' => $item->thumbnail_url,
                'duration_sec' => $item->duration_sec,
                'meta' => $item->meta,
            ])->values() : [],
            'attachments' => $story->relationLoaded('attachments') ? $story->attachments->map(fn (StoryAttachment $attachment) => [
                'id' => $attachment->id,
                'attachable_type' => $attachment->attachable_type,
                'attachable_id' => $attachment->attachable_id,
                'snapshot' => $attachment->snapshot_json,
            ])->values() : [],
            'created_at' => optional($story->created_at)->toISOString(),
            'updated_at' => optional($story->updated_at)->toISOString(),
        ];
    }

    private function serializeAuthor(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'photo' => $user->photo,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
            ] : null,
            'branch_id' => $user->branch_id,
            'branch_group_id' => $user->branch_group_id,
        ];
    }
}
