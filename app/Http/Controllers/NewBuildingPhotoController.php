<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class NewBuildingPhotoController extends Controller
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    // GET /api/new-buildings/{new_building}/photos
    public function index(NewBuilding $new_building)
    {
        return $new_building->photos()->orderBy('sort_order')->get();
    }

    /**
     * POST /api/new-buildings/{new_building}/photos
     * form-data: file=<файл>, is_cover=1, sort_order=10 (необязательно)
     * или JSON: { "path": "storage/new-buildings/5/pic.jpg", "is_cover": true, "sort_order": 3 }
     */
    public function store(Request $r, NewBuilding $new_building)
    {
        $data = $r->validate([
            'file'       => ['nullable','file','mimes:jpg,jpeg,png,webp,avif','max:10240'],
            'path'       => ['nullable','string'],
            'is_cover'   => ['nullable','boolean'],
            'sort_order' => ['nullable','integer','min:0'],
        ]);

        if (!$r->hasFile('file') && empty($data['path'])) {
            return response()->json([
                'message' => 'Передайте либо file (multipart/form-data), либо path (string).'
            ], 422);
        }

        // Следующая позиция по умолчанию
        if (!array_key_exists('sort_order', $data) || is_null($data['sort_order'])) {
            $max = (int) $new_building->photos()->max('sort_order');
            $data['sort_order'] = $max + 1;
        }

        // Если пришёл файл — сжимаем и ставим водяной знак
        if ($r->hasFile('file')) {
            $img = $this->imageManager->read($r->file('file'))->scaleDown(1600, null);

            $wmPath = public_path('watermark/logo.png');
            if (is_file($wmPath)) {
                $wm = $this->imageManager->read($wmPath)
                    ->scale((int)round($img->width() * 0.14));
                $img->place($wm, 'bottom-right', 36, 28);
            }

            // перекодируем в JPEG с качеством 50
            $binary   = $img->encode(new JpegEncoder(50));
            $filename = 'new-buildings/'.$new_building->id.'/'.uniqid('', true).'.jpg';
            Storage::disk('public')->put($filename, $binary);

            $data['path'] = $filename;
        } else {
            // нормализуем присланный path типа 'storage/...'
            $data['path'] = ltrim(preg_replace('#^storage/#', '', $data['path']), '/');
        }

        if (!empty($data['is_cover'])) {
            $new_building->photos()->update(['is_cover' => false]);
        }

        /** @var NewBuildingPhoto $photo */
        $photo = $new_building->photos()->create([
            'path'       => $data['path'],
            'is_cover'   => (bool) ($data['is_cover'] ?? false),
            'sort_order' => (int) $data['sort_order'],
        ]);

        $photo->setAttribute('url', asset('storage/'.$photo->path));

        return response()->json($photo, 201);
    }

    // DELETE /api/new-buildings/{new_building}/photos/{photo}
    // ВАЖНО: принимаем и родителя, и фото — тогда scopeBindings гарантирует принадлежность
    public function destroy(NewBuilding $new_building, NewBuildingPhoto $photo)
    {
        if ($photo->path && Storage::disk('public')->exists($photo->path)) {
            Storage::disk('public')->delete($photo->path);
        }

        $wasCover = $photo->is_cover;
        $photo->delete();

        if ($wasCover) {
            $next = $new_building->photos()->orderBy('sort_order')->first();
            if ($next) {
                $next->update(['is_cover' => true]);
            }
        }

        return response()->noContent();
    }

    // POST /api/new-buildings/{new_building}/photos/{photo}/cover
    public function setCover(NewBuilding $new_building, NewBuildingPhoto $photo)
    {
        $new_building->photos()->update(['is_cover' => false]);
        $photo->update(['is_cover' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/new-buildings/{new_building}/photos/reorder
     * body: { "orders": [ { "id": 12, "sort_order": 1 }, ... ] }
     */
    public function reorder(Request $r, NewBuilding $new_building)
    {
        $validated = $r->validate([
            'orders'                 => ['required','array','min:1'],
            'orders.*.id'            => ['required','integer', Rule::exists('new_building_photos', 'id')->where('new_building_id', $new_building->id)],
            'orders.*.sort_order'    => ['required','integer','min:0'],
        ]);

        foreach ($validated['orders'] as $row) {
            $new_building->photos()->whereKey($row['id'])->update([
                'sort_order' => $row['sort_order'],
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
