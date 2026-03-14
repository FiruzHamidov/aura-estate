<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessReelVideo;
use App\Models\Property;
use App\Models\Reel;
use App\Models\ReelLike;
use App\Models\User;
use App\Services\Reels\ReelUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class ReelController extends Controller
{
    public function __construct(
        private readonly ReelUploadService $uploadService
    ) {
    }

    private function authUser(): ?User
    {
        $user = request()->user() ?? request()->user('sanctum') ?? auth('sanctum')->user();
        $user?->loadMissing('role');

        return $user;
    }

    private function canManageProperty(?User $user, Property $property): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('admin') || $user->hasRole('superadmin')) {
            return true;
        }

        if ($user->hasRole('rop') || $user->hasRole('branch_director')) {
            $property->loadMissing(['agent.role', 'creator.role']);

            $propertyBranchId = $property->agent?->branch_id ?: $property->creator?->branch_id;

            return !empty($user->branch_id)
                && !empty($propertyBranchId)
                && (int) $propertyBranchId === (int) $user->branch_id;
        }

        if ($user->hasRole('agent') || $user->hasRole('client')) {
            return (int) $property->created_by === (int) $user->id
                || (int) $property->agent_id === (int) $user->id;
        }

        return false;
    }

    private function canManageStandaloneReel(?User $user, ?Reel $reel = null): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('admin') || $user->hasRole('superadmin')) {
            return true;
        }

        if ($user->hasRole('rop') || $user->hasRole('branch_director')) {
            if (!$reel) {
                return !empty($user->branch_id);
            }

            $reel->loadMissing('creator.role');

            return !empty($user->branch_id)
                && !empty($reel->creator?->branch_id)
                && (int) $reel->creator->branch_id === (int) $user->branch_id;
        }

        if (!$user->hasRole('agent') && !$user->hasRole('client')) {
            return false;
        }

        if (!$reel) {
            return true;
        }

        return (int) $reel->created_by === (int) $user->id;
    }

    private function ensureCanManageProperty(Property $property): User
    {
        $user = $this->authUser();

        abort_unless($this->canManageProperty($user, $property), 403, 'Forbidden');

        return $user;
    }

    private function ensureCanManageReel(Reel $reel): User
    {
        $reel->loadMissing('property');

        $user = $this->authUser();

        if ($reel->property) {
            abort_unless($this->canManageProperty($user, $reel->property), 403, 'Forbidden');

            return $user;
        }

        abort_unless($this->canManageStandaloneReel($user, $reel), 403, 'Forbidden');

        return $user;
    }

    private function publicQuery()
    {
        return Reel::query()
            ->with([
                'property' => fn ($query) => $query->select([
                    'id',
                    'title',
                    'price',
                    'currency',
                    'district',
                    'address',
                    'rooms',
                    'total_area',
                    'offer_type',
                    'moderation_status',
                ]),
            ])
            ->where(function ($query) {
                $query->whereNull('property_id')
                    ->orWhereHas('property', function ($propertyQuery) {
                        $propertyQuery->where('moderation_status', '!=', 'deleted');
                    });
            })
            ->published();
    }

    private function serializeReel(Reel $reel): array
    {
        $payload = $reel->toArray();
        $payload['can_publish'] = $reel->canBePublished();
        $payload['is_liked'] = $this->isLikedByAuthUser($reel);
        $payload['playback'] = [
            'hls_url' => $reel->hls_url,
            'mp4_url' => $reel->mp4_url,
            'video_url' => $reel->video_url,
            'video_public_url' => $this->uploadService->publicUrl($reel->video_url),
            'preview_image' => $reel->preview_image,
            'preview_image_url' => $this->uploadService->publicUrl($reel->preview_image),
            'thumbnail_url' => $reel->thumbnail_url,
            'thumbnail_public_url' => $this->uploadService->publicUrl($reel->thumbnail_url),
        ];

        return $payload;
    }

    private function isLikedByAuthUser(Reel $reel): bool
    {
        $user = $this->authUser();
        $guestToken = $this->guestToken();

        $query = ReelLike::query()->where('reel_id', $reel->id);

        if ($user) {
            return (clone $query)
                ->where('user_id', $user->id)
                ->exists();
        }

        if (!$guestToken) {
            return false;
        }

        return (clone $query)
            ->where('guest_token', $guestToken)
            ->exists();
    }

    private function ensureLikeableReel(Reel $reel): void
    {
        abort_unless(
            Reel::query()->published()->whereKey($reel->id)->exists(),
            404
        );
    }

    private function guestToken(): ?string
    {
        $token = request()->header('X-Guest-Token', request()->input('guest_token'));
        $token = is_string($token) ? trim($token) : '';

        return $token !== '' ? $token : null;
    }

    private function likeActor(Request $request): array
    {
        $user = $this->authUser();

        if ($user) {
            return [
                'user_id' => $user->id,
                'guest_token' => null,
            ];
        }

        $validated = $request->validate([
            'guest_token' => 'nullable|string|max:100',
        ]);

        $guestToken = $request->header('X-Guest-Token', $validated['guest_token'] ?? null);
        $guestToken = is_string($guestToken) ? trim($guestToken) : '';

        abort_if($guestToken === '', 422, 'Guest token is required for unauthenticated likes.');

        return [
            'user_id' => null,
            'guest_token' => $guestToken,
        ];
    }

    private function storeMediaFile($file, string $directory): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = $directory.'/'.Str::uuid().($extension ? '.'.$extension : '');

        Storage::disk('public')->putFileAs($directory, $file, basename($filename));

        return $filename;
    }

    private function deleteMediaFiles(array $paths): void
    {
        $paths = collect($paths)
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return;
        }

        Storage::disk('public')->delete($paths->all());
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => 'nullable|integer|exists:properties,id',
            'per_page' => 'nullable|integer|min:1|max:50',
            'featured' => 'nullable|boolean',
        ]);

        $query = $this->publicQuery();

        if (!empty($validated['property_id'])) {
            $query->where('property_id', $validated['property_id']);
        }

        if (array_key_exists('featured', $validated) && $validated['featured'] !== null) {
            $query->where('is_featured', filter_var($validated['featured'], FILTER_VALIDATE_BOOL));
        }

        $reels = $query->ordered()
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->through(fn (Reel $reel) => $this->serializeReel($reel));

        return response()->json($reels);
    }

    public function propertyIndex(Request $request, Property $property): JsonResponse
    {
        $validated = $request->validate([
            'include_unpublished' => 'nullable|boolean',
        ]);

        $query = Reel::query()
            ->with([
                'property' => fn ($builder) => $builder->select([
                    'id',
                    'title',
                    'price',
                    'currency',
                    'district',
                    'address',
                    'rooms',
                    'total_area',
                    'offer_type',
                    'moderation_status',
                ]),
            ])
            ->where('property_id', $property->id);

        $includeUnpublished = $request->boolean('include_unpublished');

        if (!$includeUnpublished || !$this->canManageProperty($this->authUser(), $property)) {
            $query->whereHas('property', function ($builder) {
                $builder->where('moderation_status', '!=', 'deleted');
            })->published();
        }

        $reels = $query->ordered()
            ->get()
            ->map(fn (Reel $reel) => $this->serializeReel($reel));

        return response()->json($reels);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser();

        $query = Reel::query()->with('property');

        if (!$user) {
            $query->published();
        }

        /** @var Reel $reel */
        $reel = $query->findOrFail($id);

        if (
            $user
            && $reel->status !== Reel::STATUS_PUBLISHED
            && !(
                ($reel->property && $this->canManageProperty($user, $reel->property))
                || (!$reel->property && $this->canManageStandaloneReel($user, $reel))
            )
        ) {
            abort(403, 'Forbidden');
        }

        return response()->json($this->serializeReel($reel));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => 'nullable|integer|exists:properties,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_featured' => 'nullable|boolean',
            'poster_second' => 'nullable|integer|min:0|max:300',
            'duration' => 'nullable|integer|min:0|max:300',
            'aspect_ratio' => 'nullable|string|max:16',
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime|max:102400',
            'preview_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $property = !empty($validated['property_id'])
            ? Property::query()->findOrFail($validated['property_id'])
            : null;
        $user = $property
            ? $this->ensureCanManageProperty($property)
            : tap($this->authUser(), fn ($authUser) => abort_unless($this->canManageStandaloneReel($authUser), 403, 'Forbidden'));

        $videoPath = $this->storeMediaFile($request->file('video'), 'reels/originals');
        $previewPath = $request->hasFile('preview_image')
            ? $this->storeMediaFile($request->file('preview_image'), 'reels/previews')
            : null;
        $thumbnailPath = $request->hasFile('thumbnail')
            ? $this->storeMediaFile($request->file('thumbnail'), 'reels/thumbnails')
            : null;

        $reel = Reel::query()->create([
            'property_id' => $property?->id,
            'created_by' => $user->id,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'video_url' => $videoPath,
            'preview_image' => $previewPath,
            'thumbnail_url' => $thumbnailPath,
            'duration' => $validated['duration'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'] ?? '9:16',
            'status' => Reel::STATUS_PROCESSING,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'video_size' => $request->file('video')->getSize(),
            'mime_type' => $request->file('video')->getMimeType(),
            'transcode_status' => Reel::TRANSCODE_QUEUED,
            'poster_second' => $validated['poster_second'] ?? null,
            'processing_meta' => [
                'original_name' => $request->file('video')->getClientOriginalName(),
                'queued_at' => now()->toIso8601String(),
            ],
        ]);

        ProcessReelVideo::dispatch($reel->id);

        return response()->json(
            $this->serializeReel($reel->load('property')),
            201
        );
    }

    public function initDirectUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => 'nullable|integer|exists:properties,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_featured' => 'nullable|boolean',
            'poster_second' => 'nullable|integer|min:0|max:300',
            'duration' => 'nullable|integer|min:0|max:300',
            'aspect_ratio' => 'nullable|string|max:16',
            'mime_type' => 'required|string|in:video/mp4,video/quicktime',
            'extension' => 'nullable|string|max:8',
            'file_size' => 'nullable|integer|min:1|max:104857600',
            'original_name' => 'nullable|string|max:255',
        ]);

        $property = !empty($validated['property_id'])
            ? Property::query()->findOrFail($validated['property_id'])
            : null;
        $user = $property
            ? $this->ensureCanManageProperty($property)
            : tap($this->authUser(), fn ($authUser) => abort_unless($this->canManageStandaloneReel($authUser), 403, 'Forbidden'));

        $path = $this->uploadService->directUploadPath($validated['extension'] ?? 'mp4');

        $reel = Reel::query()->create([
            'property_id' => $property?->id,
            'created_by' => $user->id,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'video_url' => $path,
            'duration' => $validated['duration'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'] ?? '9:16',
            'status' => Reel::STATUS_UPLOADING,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'video_size' => $validated['file_size'] ?? null,
            'mime_type' => $validated['mime_type'],
            'transcode_status' => Reel::TRANSCODE_PENDING,
            'poster_second' => $validated['poster_second'] ?? null,
            'processing_meta' => [
                'upload' => [
                    'mode' => 'direct',
                    'disk' => $this->uploadService->diskName(),
                    'status' => 'initialized',
                    'original_name' => $validated['original_name'] ?? null,
                    'requested_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        try {
            $upload = $this->uploadService->createTemporaryUpload(
                $reel,
                $path,
                $validated['mime_type']
            );
        } catch (RuntimeException $exception) {
            $reel->delete();
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'reel' => $this->serializeReel($reel->load('property')),
            'upload' => $upload,
        ], 201);
    }

    public function completeDirectUpload(Request $request, Reel $reel): JsonResponse
    {
        $this->ensureCanManageReel($reel);

        $request->validate([
            'video_size' => 'nullable|integer|min:1|max:104857600',
        ]);

        abort_unless($reel->status === Reel::STATUS_UPLOADING, 422, 'Reel is not awaiting upload completion.');
        abort_unless($this->uploadService->fileExists($reel->video_url), 422, 'Uploaded file was not found in storage.');

        $meta = $reel->processing_meta ?? [];
        $meta['upload'] = array_merge($meta['upload'] ?? [], [
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
        ]);

        $reel->update([
            'status' => Reel::STATUS_PROCESSING,
            'transcode_status' => Reel::TRANSCODE_QUEUED,
            'video_size' => $request->integer('video_size') ?: ($this->uploadService->fileSize($reel->video_url) ?? $reel->video_size),
            'processing_meta' => $meta,
        ]);

        ProcessReelVideo::dispatch($reel->id);

        return response()->json($this->serializeReel($reel->fresh('property')));
    }

    public function update(Request $request, Reel $reel): JsonResponse
    {
        $this->ensureCanManageReel($reel);

        $validated = $request->validate([
            'property_id' => 'sometimes|nullable|integer|exists:properties,id',
            'title' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'sort_order' => 'sometimes|integer|min:0',
            'is_featured' => 'sometimes|boolean',
            'poster_second' => 'sometimes|nullable|integer|min:0|max:300',
            'duration' => 'sometimes|nullable|integer|min:0|max:300',
            'aspect_ratio' => 'sometimes|nullable|string|max:16',
            'status' => ['sometimes', Rule::in([Reel::STATUS_DRAFT, Reel::STATUS_ARCHIVED, Reel::STATUS_PROCESSING, Reel::STATUS_PUBLISHED])],
            'video' => 'sometimes|file|mimetypes:video/mp4,video/quicktime|max:102400',
            'preview_image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
            'thumbnail' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if (array_key_exists('property_id', $validated)) {
            if ($validated['property_id']) {
                $targetProperty = Property::query()->findOrFail($validated['property_id']);
                $this->ensureCanManageProperty($targetProperty);
            } else {
                abort_unless(
                    $this->canManageStandaloneReel($this->authUser(), $reel),
                    403,
                    'Forbidden'
                );
            }
        }

        $filesToDelete = [];
        $data = collect($validated)
            ->except(['video', 'preview_image', 'thumbnail'])
            ->all();

        if ($request->hasFile('video')) {
            /** @var UploadedFile $video */
            $video = $request->file('video');
            $data['video_url'] = $this->storeMediaFile($video, 'reels/originals');
            $data['mp4_url'] = null;
            $data['hls_url'] = null;
            $data['preview_image'] = null;
            $data['thumbnail_url'] = null;
            $data['video_size'] = $video->getSize();
            $data['mime_type'] = $video->getMimeType();
            $data['status'] = Reel::STATUS_PROCESSING;
            $data['transcode_status'] = Reel::TRANSCODE_QUEUED;
            $data['published_at'] = null;

            $processingMeta = $reel->processing_meta ?? [];
            $processingMeta['original_name'] = $video->getClientOriginalName();
            $processingMeta['queued_at'] = now()->toIso8601String();
            unset($processingMeta['processed_at'], $processingMeta['preview_generation']);
            $data['processing_meta'] = $processingMeta;

            $filesToDelete = array_merge($filesToDelete, [
                $reel->video_url,
                $reel->mp4_url,
                $reel->hls_url,
                $reel->preview_image,
                $reel->thumbnail_url,
            ]);
        }

        if ($request->hasFile('preview_image')) {
            $data['preview_image'] = $this->storeMediaFile($request->file('preview_image'), 'reels/previews');
            $filesToDelete[] = $reel->preview_image;
        }

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_url'] = $this->storeMediaFile($request->file('thumbnail'), 'reels/thumbnails');
            $filesToDelete[] = $reel->thumbnail_url;
        }

        if (($data['status'] ?? null) !== Reel::STATUS_PUBLISHED && array_key_exists('status', $data)) {
            $data['published_at'] = null;
        }

        $reel->update($data);

        $this->deleteMediaFiles($filesToDelete);

        if ($request->hasFile('video')) {
            ProcessReelVideo::dispatch($reel->id);
        }

        return response()->json($this->serializeReel($reel->fresh('property')));
    }

    public function publish(Request $request, Reel $reel): JsonResponse
    {
        $this->ensureCanManageReel($reel);

        $request->validate([
            'status' => ['required', Rule::in([Reel::STATUS_PUBLISHED, Reel::STATUS_ARCHIVED, Reel::STATUS_DRAFT])],
        ]);

        $status = $request->string('status')->toString();

        if ($status === Reel::STATUS_PUBLISHED && !$reel->fresh()->canBePublished()) {
            abort(422, 'Reel is not ready for publication.');
        }

        $reel->update([
            'status' => $status,
            'published_at' => $status === Reel::STATUS_PUBLISHED ? ($reel->published_at ?? now()) : null,
        ]);

        return response()->json($this->serializeReel($reel->fresh('property')));
    }

    public function destroy(Reel $reel): JsonResponse
    {
        $this->ensureCanManageReel($reel);

        $filesToDelete = [
            $reel->video_url,
            $reel->mp4_url,
            $reel->hls_url,
            $reel->preview_image,
            $reel->thumbnail_url,
        ];

        $reel->delete();
        $this->deleteMediaFiles($filesToDelete);

        return response()->json(['message' => 'Reel deleted.']);
    }

    public function trackView(Reel $reel): JsonResponse
    {
        $this->ensureLikeableReel($reel);

        $reel->increment('views_count');

        return response()->json([
            'id' => $reel->id,
            'views_count' => $reel->fresh()->views_count,
        ]);
    }

    public function like(Request $request, Reel $reel): JsonResponse
    {
        $this->ensureLikeableReel($reel);
        $actor = $this->likeActor($request);

        ReelLike::firstOrCreate([
            'reel_id' => $reel->id,
            'user_id' => $actor['user_id'],
            'guest_token' => $actor['guest_token'],
        ]);

        $likesCount = ReelLike::query()->where('reel_id', $reel->id)->count();
        $reel->update(['likes_count' => $likesCount]);

        return response()->json([
            'id' => $reel->id,
            'likes_count' => $likesCount,
            'is_liked' => true,
        ], 201);
    }

    public function unlike(Request $request, Reel $reel): JsonResponse
    {
        $this->ensureLikeableReel($reel);
        $actor = $this->likeActor($request);

        ReelLike::query()
            ->where('reel_id', $reel->id)
            ->when(
                $actor['user_id'],
                fn ($query, $userId) => $query->where('user_id', $userId),
                fn ($query) => $query->where('guest_token', $actor['guest_token'])
            )
            ->delete();

        $likesCount = ReelLike::query()->where('reel_id', $reel->id)->count();
        $reel->update(['likes_count' => $likesCount]);

        return response()->json([
            'id' => $reel->id,
            'likes_count' => $likesCount,
            'is_liked' => false,
        ]);
    }

    public function likeStatus(Reel $reel): JsonResponse
    {
        $this->ensureLikeableReel($reel);

        return response()->json([
            'id' => $reel->id,
            'likes_count' => $reel->fresh()->likes_count,
            'is_liked' => $this->isLikedByAuthUser($reel),
        ]);
    }
}
