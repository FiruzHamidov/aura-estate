<?php

namespace App\Http\Controllers;

use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class NewBuildingPhotoController extends Controller
{
    public function index(NewBuilding $new_building)
    {
        return $new_building->photos()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Загрузка фото:
     * - form-data: file=<файл>, is_cover=1, sort_order=10 (необязательно)
     * - JSON: { "path": "storage/new-buildings/5/pic.jpg", "is_cover": true, "sort_order": 3 }
     */
    public function store(Request $r, NewBuilding $new_building)
    {
        $data = $r->validate([
            'file'       => ['nullable','file','mimes:jpg,jpeg,png,webp,avif','max:10240'], // до ~10MB
            'path'       => ['nullable','string'],
            'is_cover'   => ['nullable','boolean'],
            'sort_order' => ['nullable','integer','min:0'],
        ]);

        if (empty($data['file']) && empty($data['path'])) {
            return response()->json([
                'message' => 'Передайте либо file (multipart/form-data), либо path (string).'
            ], 422);
        }

        // Если не пришёл sort_order — ставим следующий
        if (!array_key_exists('sort_order', $data) || is_null($data['sort_order'])) {
            $max = (int) $new_building->photos()->max('sort_order');
            $data['sort_order'] = $max + 1;
        }

        // Если пришёл файл — кладём на диск 'public/new-buildings/{id}'
        if (!empty($data['file'])) {
            $storedPath = $data['file']->store(
                'new-buildings/'.$new_building->id,
                'public'
            );
            // В БД храним путь БЕЗ 'storage/', но ниже для фронта удобно вернуть с 'storage/'
            $data['path'] = $storedPath; // например: new-buildings/5/filename.jpg
            unset($data['file']);
        } else {
            // Если прислан уже готовый path как 'storage/...', нормализуем до относительного
            $data['path'] = ltrim(preg_replace('#^storage/#', '', $data['path']), '/');
        }

        // Если фото делаем обложкой — сбрасываем прежние
        if (!empty($data['is_cover'])) {
            $new_building->photos()->update(['is_cover' => false]);
        }

        /** @var NewBuildingPhoto $photo */
        $photo = $new_building->photos()->create([
            'path'       => $data['path'],
            'is_cover'   => (bool) ($data['is_cover'] ?? false),
            'sort_order' => (int) $data['sort_order'],
        ]);

        // Вернём пригодный для фронта url
        $photo->setAttribute('url', asset('storage/'.$photo->path));

        return response()->json($photo, 201);
    }

    /**
     * Удаление фото и файла (если он лежит на диске 'public').
     */
    public function destroy(NewBuildingPhoto $photo)
    {
        // Попробуем удалить файл из public, если он там
        if ($photo->path && Storage::disk('public')->exists($photo->path)) {
            Storage::disk('public')->delete($photo->path);
        }

        $wasCover = $photo->is_cover;
        $building = $photo->newBuilding;

        $photo->delete();

        // Если удалили cover — назначим обложкой первое по сортировке
        if ($wasCover && $building) {
            $next = $building->photos()->orderBy('sort_order')->first();
            if ($next) {
                $next->update(['is_cover' => true]);
            }
        }

        return response()->noContent();
    }

    /**
     * Явная установка обложки для фото.
     */
    public function setCover(NewBuildingPhoto $photo)
    {
        $building = $photo->newBuilding;
        if ($building) {
            $building->photos()->update(['is_cover' => false]);
        }
        $photo->update(['is_cover' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Массовая переупорядочиваниe:
     * body JSON: { "orders": [ { "id": 12, "sort_order": 1 }, { "id": 15, "sort_order": 2 } ] }
     */
    public function reorder(Request $r, NewBuilding $new_building)
    {
        $validated = $r->validate([
            'orders'   => ['required','array','min:1'],
            'orders.*.id' => ['required','integer', Rule::exists('new_building_photos', 'id')->where('new_building_id', $new_building->id)],
            'orders.*.sort_order' => ['required','integer','min:0'],
        ]);

        foreach ($validated['orders'] as $row) {
            $new_building->photos()->whereKey($row['id'])->update([
                'sort_order' => $row['sort_order'],
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
