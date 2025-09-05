<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class PropertyController extends Controller
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Property::with(['type','status','location','repairType','photos','creator']);

        $hasStatusFilter = $request->filled('moderation_status');

        // --- Ролевые ограничения ---
        if ($user && $user->hasRole('admin')) {
            // без ограничений
        } elseif (!$user) {
            $query->where('moderation_status', 'approved');
        } elseif ($user->hasRole('agent')) {
            $query->where('created_by', $user->id);
            if (!$hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        } elseif ($user->hasRole('client')) {
            if (!$hasStatusFilter) {
                $query->where('moderation_status', '!=', 'deleted');
            }
        }

        // helper: нормализует вход в массив (поддерживает a[]=1&a[]=2 и "1,2")
        $toArray = function ($value) {
            if ($value === null || $value === '') return [];
            if (is_array($value)) return array_values(array_filter($value, fn($v) => $v !== '' && $v !== null));
            return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
        };

        // --- Статусы (мульти) ---
        if ($hasStatusFilter) {
            $available = ['pending','approved','rejected','draft','deleted','sold','rented'];
            $statuses = array_values(array_intersect($toArray($request->input('moderation_status')), $available));
            if (!empty($statuses)) {
                $query->whereIn('moderation_status', $statuses);
            }
        }

        // --- Поиск по тексту (поддержим массив значений: ИЛИ между терминами) ---
        foreach (['title','description','district','address','landmark','condition','apartment_type','owner_phone'] as $field) {
            if ($request->has($field)) {
                $terms = $toArray($request->input($field));
                if (empty($terms)) {
                    // одиночное значение-строка
                    $val = $request->input($field);
                    if ($val !== null && $val !== '') {
                        $query->where($field, 'like', '%'.$val.'%');
                    }
                } else {
                    $query->where(function($q) use ($field, $terms) {
                        foreach ($terms as $t) {
                            $q->orWhere($field, 'like', '%'.$t.'%');
                        }
                    });
                }
            }
        }

        // --- Точные поля (поддержка мультиселекта через whereIn) ---
        $exactFields = [
            'type_id', 'status_id', 'location_id', 'repair_type_id',
            'currency', 'offer_type',
            'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
            'latitude', 'longitude', 'agent_id', 'listing_type', 'created_by', 'contract_type_id'
        ];
        foreach ($exactFields as $field) {
            if ($request->has($field)) {
                $vals = $toArray($request->input($field));
                if (empty($vals)) {
                    $val = $request->input($field);
                    if ($val !== null && $val !== '') {
                        $query->where($field, $val);
                    }
                } else {
                    $query->whereIn($field, $vals);
                }
            }
        }

        // --- Диапазоны ---
        foreach ([
                     'price' => 'price',
                     'rooms' => 'rooms',
                     'total_area' => 'total_area',
                     'living_area' => 'living_area',
                     'floor' => 'floor',
                     'total_floors' => 'total_floors',
                     'year_built' => 'year_built',
                 ] as $param => $column) {
            $from = $request->input($param.'From');
            $to   = $request->input($param.'To');
            if ($from !== null && $from !== '') $query->where($column, '>=', $from);
            if ($to   !== null && $to   !== '') $query->where($column, '<=', $to);
        }

        $perPage = (int) $request->input('per_page', 20);
        return response()->json($query->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $this->validateProperty($request);

        $validated['created_by'] = auth()->id();
        $validated['moderation_status'] = auth()->user()->hasRole('client') ? 'pending' : 'approved';
        $validated['listing_type'] = $request->input('listing_type', 'regular');

        $property = Property::create($validated);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $image = $this->imageManager->read($photo)
                    ->scaleDown(1600, null);

                // читаем PNG-лого
                $watermark = $this->imageManager->read(public_path('watermark/logo.png'))
                    ->scale((int) round($image->width() * 0.14)); // ~14% ширины фото

                // накладываем справа снизу
                $image->place($watermark, 'bottom-right', 36, 28);

                // сохраняем
                $jpeg = new JpegEncoder(50);
                $binary = $image->encode($jpeg);

                $filename = 'properties/' . uniqid('', true) . '.jpg';
                \Storage::disk('public')->put($filename, $binary);
                $property->photos()->create(['file_path' => $filename]);
            }
        }

        return response()->json($property->load(['photos']));
    }

    public function update(Request $request, Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $this->validateProperty($request);

        $property->update($validated);

        if ($request->hasFile('photos')) {
            // удалить старые фото
            foreach ($property->photos as $oldPhoto) {
                \Storage::disk('public')->delete($oldPhoto->file_path);
                $oldPhoto->delete();
            }

            // добавить новые фото
            foreach ($request->file('photos') as $photo) {
                $image = $this->imageManager->read($photo)
                    ->scaleDown(1600, null);

                // читаем PNG-лого
                $watermark = $this->imageManager->read(public_path('watermark/logo.png'))
                    ->scale((int) round($image->width() * 0.14)); // ~14% ширины фото

                // накладываем справа снизу
                $image->place($watermark, 'bottom-right', 36, 28);

                // сохраняем
                $jpeg = new JpegEncoder(50);
                $binary = $image->encode($jpeg);

                $filename = 'properties/' . uniqid('', true) . '.jpg';
                \Storage::disk('public')->put($filename, $binary);
                $property->photos()->create(['file_path' => $filename]);
            }
        }


        return response()->json($property->load(['photos']));
    }

    public function show(Property $property)
    {
        $user = auth()->user();

        if (!$user && $property->moderation_status !== 'approved') {
            return response()->json(['message' => 'Объект недоступен'], 403);
        }

        if ($user && $user->hasRole('client') && $property->created_by !== $user->id) {
            return response()->json(['message' => 'Объект недоступен'], 403);
        }

        return response()->json($property->load(['type', 'status', 'location', 'repairType', 'photos', 'creator', 'contractType']));
    }



    public function destroy(Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $property->update(['moderation_status' => 'deleted']);
        return response()->json(['message' => 'Объект помечен как удалён']);
    }

    public function updateModerationAndListingType(Request $request, Property $property)
    {
        $user = auth()->user();

        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('agent'))) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $request->validate([
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted',
            'listing_type' => 'sometimes|in:regular,vip,urgent',
        ]);

        $property->update($validated);

        return response()->json([
            'message' => 'Обновлено успешно',
            'data' => $property->only(['id', 'moderation_status', 'listing_type']),
        ]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function validateProperty(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'created_by' => 'nullable|string',
            'district' => 'nullable|string',
            'address' => 'nullable|string',
            'moderation_status' => 'nullable|string',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'type_id' => 'required|exists:property_types,id',
            'status_id' => 'required|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'price' => 'required|numeric',
            'currency' => 'required|in:TJS,USD',
            'offer_type' => 'required|in:rent,sale',
            'rooms' => 'nullable|integer|min:1|max:10',
            'youtube_link' => 'nullable|url',
            'total_area' => 'nullable|numeric',
            'living_area' => 'nullable|numeric',
            'floor' => 'nullable|integer',
            'total_floors' => 'nullable|integer',
            'year_built' => 'nullable|integer',
            'condition' => 'nullable|string',
            'apartment_type' => 'nullable|string',
            'has_garden' => 'boolean',
            'has_parking' => 'boolean',
            'is_mortgage_available' => 'boolean',
            'is_from_developer' => 'boolean',
            'landmark' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'photos.*' => 'nullable|image|max:10240',
            'agent_id' => 'nullable|exists:users,id',
            'owner_phone' => 'nullable|string|max:30',
            'listing_type' => 'sometimes|in:regular,vip,urgent',
        ]);
        return $validated;
    }
}
