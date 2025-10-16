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
        // 1) Удаление выбранных фото
        if ($request->filled('delete_photo_ids')) {
            $ids = (array)$request->input('delete_photo_ids');
            $oldPhotos = $unit->photos()->whereIn('id', $ids)->get();
            foreach ($oldPhotos as $old) {
                if (!empty($old->file_path)) {
                    Storage::disk('public')->delete($old->file_path);
                }
                $old->delete();
            }
        }

        // 2) Если нет файлов — только нормализация/реордер
        if (!$request->hasFile('photos')) {
            if ($request->filled('photo_positions')) {
                $this->applyPositionsFromRequest($unit, $request->input('photo_positions', []));
            } else {
                $this->normalizePositions($unit);
            }
            return $unit->photos()->orderBy('position')->get();
        }

        // 3) Базовая позиция (добавляем в конец)
        $append = (bool)$request->boolean('append', true);
        $basePos = $append ? (int)($unit->photos()->max('sort_order') ?? -1) + 1 : 0;



        $files     = $request->file('photos');
        $positions = (array)$request->input('photo_positions', []); // опциональный параллельный массив
        $setCover  = $request->boolean('set_cover', false); // если true — первое загруженное станет cover (если ещё нет cover)

        foreach (array_values($files) as $i => $photo) {
            // 4) Обработка изображения: масштаб до ширины 1600, водяной знак, JPEG 50
            $image = $this->imageManager->read($photo)->scaleDown(1600, null);

            $wmPath = public_path('watermark/logo.png');
            if (is_file($wmPath)) {
                $watermark = $this->imageManager->read($wmPath)
                    ->scale((int)round($image->width() * 0.14));
                $image->place($watermark, 'bottom-right', 36, 28);
            }

            $binary   = $image->encode(new JpegEncoder(50));
            $filename = 'units/' . uniqid('', true) . '.jpg';
            Storage::disk('public')->put($filename, $binary);

            $position = $positions[$i] ?? ($basePos + $i);

            $unit->photos()->create([
                'file_path'  => $filename,
                'sort_order' => $position,
            ]);
        }

        // 5) Нормализуем позиции 0..N-1 без пропусков
        $this->normalizePositions($unit);

        // 6) Кавер: если явно попросили или если кавера ещё нет — ставим первым
        if ($setCover || !$unit->photos()->where('is_cover', true)->exists()) {
            $first = $unit->photos()->orderBy('position')->first();
            if ($first) {
                $unit->photos()->update(['is_cover' => false]);
                $first->is_cover = true;
                $first->save();
            }
        }

        return response()->json($unit->photos()->orderBy('position')->get(), 201);
    }

    // DELETE /api/new-buildings/{new_building}/units/{unit}/photos/{photo}
    public function destroy(NewBuilding $new_building, DeveloperUnit $unit, DeveloperUnitPhoto $photo)
    {
        // защитим принадлежность
        if ($photo->developer_unit_id !== $unit->id) {
            abort(404);
        }
        if (!empty($photo->file_path)) {
            Storage::disk('public')->delete($photo->file_path);
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
        return $unit->photos()->orderBy('position')->get();
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
        return $unit->photos()->orderBy('position')->get();
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
                $unit->photos()->where('id', $row['id'] ?? null)->update(['sort_order' => (int)($row['position'] ?? 0)]);
            }
        }
        $this->normalizePositions($unit);
    }
}
