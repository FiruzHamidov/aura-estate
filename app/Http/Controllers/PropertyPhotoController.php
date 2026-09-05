<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\PropertyModeration\PropertyModerationAccess;
use App\Services\PropertyModeration\PropertyModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Encoders\JpegEncoder;

class PropertyPhotoController extends Controller
{
    public function __construct(
        private readonly PropertyModerationAccess $access,
        private readonly PropertyModerationService $moderation,
    ) {}

    private function crmAuthUser(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function authorizePropertyMutation(Property $property): User
    {
        $user = $this->crmAuthUser();

        if (! $this->access->canEdit($user, $property)) {
            abort(403, 'Доступ запрещён');
        }

        return $user;
    }

    public function store(Request $request, Property $property)
    {
        $actor = $this->authorizePropertyMutation($property);
        $this->moderation->assertNoProtectedFields($request, [], $actor, $property);

        $request->validate([
            'photos' => ['required', 'array', 'max:40'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $property, $actor): void {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $this->moderation->assertMutationVersion($request, $locked);
            abort_unless($this->access->canEdit($actor, $locked), 403);
            $beforePhotos = $this->moderation->photoSnapshot($locked);
            $basePos = (int) ($locked->photos()->max('position') ?? -1) + 1;

            foreach (array_values($request->file('photos')) as $i => $photo) {
                $image = app('image')->read($photo)->scaleDown(1600, null);
                $wm = app('image')->read(public_path('watermark/logo.png'))
                    ->scale((int) round($image->width() * 0.14));
                $image->place($wm, 'bottom-right', 36, 28);

                $binary = $image->encode(new JpegEncoder(50));
                $filename = 'properties/'.uniqid('', true).'.jpg';
                \Storage::disk('public')->put($filename, $binary);

                $position = $request->input("photo_positions.$i", $basePos + $i);

                $locked->photos()->create(['file_path' => $filename, 'position' => $position]);
            }

            $locked->markListingUpdated();
            $this->moderation->handleMediaMutation($locked, $actor, ['action' => 'photos_added', 'before_photos' => $beforePhotos]);
        });

        return response()->json($property->fresh('photos'));
    }

    public function destroy(Request $request, Property $property, PropertyPhoto $photo)
    {
        $actor = $this->authorizePropertyMutation($property);
        $this->moderation->assertNoProtectedFields($request, [], $actor, $property);
        abort_unless($photo->property_id === $property->id, 404);

        DB::transaction(function () use ($request, $property, $photo, $actor): void {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $this->moderation->assertMutationVersion($request, $locked);
            abort_unless($this->access->canEdit($actor, $locked), 403);
            $beforePhotos = $this->moderation->photoSnapshot($locked);
            $lockedPhoto = PropertyPhoto::query()
                ->where('property_id', $locked->id)
                ->lockForUpdate()
                ->findOrFail($photo->id);
            $preserveFileForRollback = (array) $locked->approved_content_snapshot !== [];
            if (! $preserveFileForRollback) {
                \Storage::disk('public')->delete($lockedPhoto->file_path);
            }
            $lockedPhoto->delete();

            // Re-pack positions
            $photos = $locked->photos()->orderBy('position')->get();
            foreach ($photos as $idx => $p) {
                $p->update(['position' => $idx]);
            }

            $locked->markListingUpdated();
            $this->moderation->handleMediaMutation($locked, $actor, ['action' => 'photo_deleted', 'before_photos' => $beforePhotos, 'photo_id' => $lockedPhoto->id]);
        });

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Property $property)
    {
        $actor = $this->authorizePropertyMutation($property);
        $this->moderation->assertNoProtectedFields($request, [], $actor, $property);

        $data = $request->validate([
            'photo_order' => ['required', 'array'],
            'photo_order.*' => ['integer', 'exists:property_photos,id'],
        ]);

        DB::transaction(function () use ($request, $data, $property, $actor): void {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $this->moderation->assertMutationVersion($request, $locked);
            abort_unless($this->access->canEdit($actor, $locked), 403);
            $beforePhotos = $this->moderation->photoSnapshot($locked);
            $changed = false;
            foreach ($data['photo_order'] as $pos => $id) {
                $photo = $locked->photos()->whereKey($id)->first();

                if ($photo && (int) $photo->position !== $pos) {
                    $photo->update(['position' => $pos]);
                    $changed = true;
                }
            }

            if ($changed) {
                $locked->markListingUpdated();
                $this->moderation->handleMediaMutation($locked, $actor, ['action' => 'photos_reordered', 'before_photos' => $beforePhotos]);
            }
        });

        return response()->json($property->fresh('photos'));
    }
}
