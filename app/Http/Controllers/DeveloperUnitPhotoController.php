<?php

namespace App\Http\Controllers;

use App\Models\DeveloperUnit;
use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuilding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class DeveloperUnitPhotoController extends Controller
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    // GET /api/new-buildings/{new_building}/units/{unit}/photos
    public function index(NewBuilding $new_building, DeveloperUnit $unit)
    {
        // thanks to scopeBindings(), {unit} уже принадлежит {new_building} или 404
        return $unit->photos()->orderBy('sort_order')->get();
    }

    // POST /api/new-buildings/{new_building}/units/{unit}/photos
    // Поддерживает множественную загрузку: photos[]
    // Поддерживает удаление по delete_photo_ids[]
    // Поддерживает reorder через photo_positions[] (параллельный массив)
    public function store(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        $files = $request->file('photo');

        // Если пришёл один файл — оборачиваем в массив
        $files = is_array($files) ? $files : [$files];

        $savedPhotos = [];

        foreach ($files as $photo) {
            if (!$photo) continue;

            // 1) Масштабирование и водяной знак
            $img = $this->imageManager->read($photo)->scaleDown(1600, null);

            $wmPath = public_path('watermark/logo.png');
            if (is_file($wmPath)) {
                $wm = $this->imageManager->read($wmPath)
                    ->scale((int)round($img->width() * 0.14));
                $img->place($wm, 'bottom-right', 36, 28);
            }

            // 2) Перекодируем в JPEG с качеством 50
            $binary   = $img->encode(new JpegEncoder(50));
            $filename = 'units/' . uniqid('', true) . '.jpg';
            Storage::disk('public')->put($filename, $binary);

            // 3) Создаём запись в БД
            $unit->photos()->create([
                'path' => $filename,
            ]);
        }

        return response()->json($savedPhotos, 201);
    }

    // DELETE /api/new-buildings/{new_building}/units/{unit}/photos/{photo}
    public function destroy(NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        // защитим принадлежность
        if ($photo->developer_unit_id !== $unit->id) {
            abort(404);
        }
        if (!empty($photo->path)) {
            Storage::disk('public')->delete($photo->path);
        }
        $photo->delete();
        $this->normalizePositions($unit);
        return response()->noContent();
    }

    // PUT /api/new-buildings/{new_building}/units/{unit}/photos/reorder
    public function reorder(Request $request, NewBuilding $new_building, DeveloperUnit $unit)
    {
        $positions = (array)$request->input('photo_positions', []);
        $this->applyPositionsFromRequest($unit, $positions);
        return $unit->photos()->orderBy('sort_order')->get();
    }

    // POST /api/new-buildings/{new_building}/units/{unit}/photos/{photo}/cover
    public function setCover(NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        if ($photo->developer_unit_id !== $unit->id) {
            abort(404);
        }
        $unit->photos()->update(['is_cover' => false]);
        $photo->is_cover = true;
        $photo->save();
        return $unit->photos()->orderBy('sort_order')->get();
    }

    // ===== Helpers =====

    private function normalizePositions(DeveloperUnit $unit): void
    {
        $photos = $unit->photos()->orderBy('sort_order')->get(['id','sort_order']);
        foreach ($photos as $i => $p) {
            if ((int)$p->sort_order !== $i) {
                $p->sort_order = $i;
                $p->save();
            }
        }
    }

    private function applyPositionsFromRequest(DeveloperUnit $unit, array $photoPositions): void
    {
        // ожидается массив вида [['id'=>X,'position'=>Y], ...] или ассоц [id => position]
        if (isset($photoPositions[0]) && is_array($photoPositions[0]) && array_key_exists('id', $photoPositions[0])) {
            foreach ($photoPositions as $row) {
                $unit->photos()->where('id', $row['id'] ?? null)->update(['sort_order' => (int)($row['position'] ?? 0)]);
            }
        } else {
            foreach ($photoPositions as $id => $pos) {
                $unit->photos()->where('id', $id)->update(['sort_order' => (int)$pos]);
            }
        }
        $this->normalizePositions($unit);
    }
}
