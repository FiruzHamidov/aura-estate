<?php
namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Intervention\Image\Encoders\JpegEncoder;

class PropertyPhotoController extends Controller
{
    private function crmAuthUser(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function canMutateProperty(User $user, Property $property): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('superadmin')) {
            return true;
        }

        if ($property->created_by === $user->id || $property->agent_id === $user->id) {
            return true;
        }

        if ($user->hasRole('client') || $user->hasRole('intern')) {
            return false;
        }

        if ($user->hasRole('mop')) {
            if (empty($user->branch_group_id)) {
                return false;
            }

            $propertyBranchGroupId = $property->branch_group_id;

            if (empty($propertyBranchGroupId) && Schema::hasColumn('users', 'branch_group_id')) {
                $property->loadMissing(['agent', 'creator']);
                $propertyBranchGroupId = $property->agent?->branch_group_id ?: $property->creator?->branch_group_id;
            }

            return !empty($propertyBranchGroupId)
                && (int) $propertyBranchGroupId === (int) $user->branch_group_id;
        }

        if (!$user->hasRole('branch_director') && !$user->hasRole('rop')) {
            return false;
        }

        if (empty($user->branch_id)) {
            return false;
        }

        $property->loadMissing(['agent', 'creator']);
        $propertyBranchId = $property->agent?->branch_id ?: $property->creator?->branch_id;

        return !empty($propertyBranchId) && (int) $propertyBranchId === (int) $user->branch_id;
    }

    private function authorizePropertyMutation(Property $property): void
    {
        $user = $this->crmAuthUser();

        if (!$this->canMutateProperty($user, $property)) {
            abort(403, 'Доступ запрещён');
        }
    }

    public function store(Request $request, Property $property)
    {
        $this->authorizePropertyMutation($property);

        $request->validate([
            'photos' => ['required','array','max:40'],
            'photos.*' => ['file','mimes:jpg,jpeg,png,webp','max:8192'],
            'photo_positions' => ['nullable','array'],
            'photo_positions.*' => ['integer','min:0'],
        ]);

        $basePos = (int) ($property->photos()->max('position') ?? -1) + 1;

        foreach (array_values($request->file('photos')) as $i => $photo) {
            $image = app('image')->read($photo)->scaleDown(1600, null);
            $wm = app('image')->read(public_path('watermark/logo.png'))
                ->scale((int) round($image->width() * 0.14));
            $image->place($wm, 'bottom-right', 36, 28);

            $binary = $image->encode(new JpegEncoder(50));
            $filename = 'properties/' . uniqid('', true) . '.jpg';
            \Storage::disk('public')->put($filename, $binary);

            $position = $request->input("photo_positions.$i", $basePos + $i);

            $property->photos()->create(['file_path' => $filename, 'position' => $position]);
        }

        return response()->json($property->fresh('photos'));
    }

    public function destroy(Property $property, PropertyPhoto $photo)
    {
        $this->authorizePropertyMutation($property);

        abort_unless($photo->property_id === $property->id, 404);
        \Storage::disk('public')->delete($photo->file_path);
        $photo->delete();

        // Re-pack positions
        $photos = $property->photos()->orderBy('position')->get();
        foreach ($photos as $idx => $p) {
            $p->update(['position' => $idx]);
        }

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Property $property)
    {
        $this->authorizePropertyMutation($property);

        $data = $request->validate([
            'photo_order' => ['required','array'],
            'photo_order.*' => ['integer','exists:property_photos,id'],
        ]);

        foreach ($data['photo_order'] as $pos => $id) {
            $property->photos()->whereKey($id)->update(['position' => $pos]);
        }

        return response()->json($property->fresh('photos'));
    }
}
