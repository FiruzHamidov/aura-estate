<?php

namespace App\Http\Controllers;

use App\Models\Developer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class DeveloperController extends Controller
{
    /**
     * GET /developers
     * Параметры: search, status, sort (name|created_at), dir (asc|desc), per_page
     */
    public function index(Request $request)
    {
        $q = Developer::query()
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = trim($request->string('search'));
                $qq->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%")
                        ->orWhere('website', 'like', "%{$s}%");
                });
            })
            ->when($request->filled('status'), function ($qq) use ($request) {
                $qq->where('moderation_status', $request->string('status'));
            });

        $sort = $request->get('sort', 'created_at');
        $dir  = $request->get('dir', 'desc');
        if (! in_array($sort, ['name', 'created_at'])) $sort = 'created_at';
        if (! in_array(strtolower($dir), ['asc', 'desc'])) $dir = 'desc';

        $q->orderBy($sort, $dir);

        $perPage = (int) $request->get('per_page', 15);

        return response()->json($q->paginate($perPage));
    }

    /**
     * POST /developers
     * Поддерживает загрузку файла 'logo' (multipart/form-data)
     */
    public function store(Request $request)
    {
        $nowYear = (int) date('Y');

        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:255'],
            'phone'                    => ['nullable', 'string', 'max:50'],
            'under_construction_count' => ['nullable', 'integer', 'min:0'],
            'built_count'              => ['nullable', 'integer', 'min:0'],
            'founded_year'             => ['nullable', 'integer', 'min:1800', "max:{$nowYear}"],
            'total_projects'           => ['nullable', 'integer', 'min:0'],
            'moderation_status'        => ['nullable', Rule::in(['pending','approved','rejected','draft','deleted'])],
            'website'                  => ['nullable', 'string', 'max:255'],
            'facebook'                 => ['nullable', 'string', 'max:255'],
            'instagram'                => ['nullable', 'string', 'max:255'],
            'telegram'                 => ['nullable', 'string', 'max:255'],
            // логотип как строковый путь, но можно принять файл:
            'logo_path'                => ['nullable', 'string', 'max:255'],
            'logo'                     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:5120'],
        ]);

        // загрузка файла логотипа, если пришел
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('developers', 'public');
            $data['logo_path'] = $path;
        }

        // дефолты счётчиков
        $data['under_construction_count'] = $data['under_construction_count'] ?? 0;
        $data['built_count']              = $data['built_count'] ?? 0;
        $data['total_projects']           = $data['total_projects'] ?? 0;
        $data['moderation_status']        = $data['moderation_status'] ?? 'pending';

        $dev = Developer::create($data);

        return response()->json($dev, 201);
    }

    /**
     * GET /developers/{developer}
     */
    public function show(Developer $developer)
    {
        return response()->json($developer);
    }

    /**
     * PUT/PATCH /developers/{developer}
     * Поддерживает замену логотипа через поле 'logo'
     */
    public function update(Request $request, Developer $developer)
    {
        $nowYear = (int) date('Y');

        $data = $request->validate([
            'name'                     => ['sometimes', 'required', 'string', 'max:255'],
            'phone'                    => ['nullable', 'string', 'max:50'],
            'under_construction_count' => ['nullable', 'integer', 'min:0'],
            'built_count'              => ['nullable', 'integer', 'min:0'],
            'founded_year'             => ['nullable', 'integer', 'min:1800', "max:{$nowYear}"],
            'total_projects'           => ['nullable', 'integer', 'min:0'],
            'moderation_status'        => ['nullable', Rule::in(['pending','approved','rejected','draft','deleted'])],
            'website'                  => ['nullable', 'string', 'max:255'],
            'facebook'                 => ['nullable', 'string', 'max:255'],
            'instagram'                => ['nullable', 'string', 'max:255'],
            'telegram'                 => ['nullable', 'string', 'max:255'],
            'logo_path'                => ['nullable', 'string', 'max:255'],
            'logo'                     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:5120'],
        ]);

        // если грузим новый логотип — удалим старый (по желанию)
        if ($request->hasFile('logo')) {
            if ($developer->logo_path && Storage::disk('public')->exists($developer->logo_path)) {
                Storage::disk('public')->delete($developer->logo_path);
            }
            $path = $request->file('logo')->store('developers', 'public');
            $data['logo_path'] = $path;
        }

        $developer->update($data);

        return response()->json($developer->refresh());
    }

    /**
     * DELETE /developers/{developer}
     */
    public function destroy(Developer $developer)
    {
        // опционально удалять файл логотипа
        if ($developer->logo_path && Storage::disk('public')->exists($developer->logo_path)) {
            Storage::disk('public')->delete($developer->logo_path);
        }

        $developer->delete();

        return response()->noContent();
    }
}
