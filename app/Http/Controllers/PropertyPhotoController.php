<?php
namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyPhoto;
use Illuminate\Http\Request;
use Intervention\Image\Encoders\JpegEncoder;

class PropertyPhotoController extends Controller
{
    public function store(Request $request, Property $property)
    {
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
