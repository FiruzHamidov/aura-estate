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

        $aliases = [
            'area' => 'total_area', // area* => total_area*
        ];

        // --- Диапазоны ---
        foreach ([
                     'price' => 'price',
                     'rooms' => 'rooms',
                     'total_area' => 'total_area',
                     'living_area' => 'living_area',
                     'floor' => 'floor',
                     'total_floors' => 'total_floors',
                     'year_built' => 'year_built',
                     'area' => $aliases['area'],
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

        // Accept initial photos with explicit order coming from the client
        // photos[] => files, photo_positions[] => integers aligned by index
        $this->storePhotosFromRequest($request, $property);

        return response()->json($property->load('photos'));
    }

    public function update(Request $request, Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $this->validateProperty($request, isUpdate: true);
        $property->update($validated);

        // Optional: allow adding more photos on update
        $this->storePhotosFromRequest($request, $property, append: true);

        // Optional: reorder via `photo_order` = [photoId1, photoId2, ...]
        if ($request->filled('photo_order') && is_array($request->photo_order)) {
            $this->applyOrder($property, $request->photo_order);
        }

        return response()->json($property->load('photos'));
    }

    private function storePhotosFromRequest(Request $request, Property $property, bool $append = false): void
    {
        // Delete selected photos if requested
        if ($request->filled('delete_photo_ids')) {
            foreach ($property->photos()->whereIn('id', $request->delete_photo_ids)->get() as $old) {
                \Storage::disk('public')->delete($old->file_path);
                $old->delete();
            }
        }

        if (!$request->hasFile('photos')) {
            return;
        }

        // Determine base position (append to the end)
        $basePos = $append ? (int) ($property->photos()->max('position') ?? -1) + 1 : 0;

        $files = $request->file('photos');
        $positions = $request->input('photo_positions', []); // optional parallel array

        foreach (array_values($files) as $i => $photo) {
            $image = $this->imageManager->read($photo)->scaleDown(1600, null);
            $watermark = $this->imageManager->read(public_path('watermark/logo.png'))
                ->scale((int) round($image->width() * 0.14));
            $image->place($watermark, 'bottom-right', 36, 28);

            $binary = $image->encode(new JpegEncoder(50));
            $filename = 'properties/' . uniqid('', true) . '.jpg';
            \Storage::disk('public')->put($filename, $binary);

            $position = $positions[$i] ?? ($basePos + $i);

            $property->photos()->create([
                'file_path' => $filename,
                'position' => $position,
            ]);
        }

        // Normalize positions to be 0..N-1 with no gaps
        $this->normalizePositions($property);
    }

    private function applyOrder(Property $property, array $orderedIds): void
    {
        foreach ($orderedIds as $pos => $id) {
            $property->photos()->whereKey($id)->update(['position' => $pos]);
        }
        $this->normalizePositions($property);
    }

    private function normalizePositions(Property $property): void
    {
        $photos = $property->photos()->orderBy('position')->orderBy('id')->get();
        foreach ($photos as $idx => $p) {
            if ((int)$p->position !== $idx) {
                $p->update(['position' => $idx]);
            }
        }
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
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented',
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
    public function validateProperty(Request $request,  bool $isUpdate = false)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'created_by' => 'nullable|string',
            'district' => 'nullable|string',
            'address' => 'nullable|string',
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted,sold,rented',
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
            'year_built' => 'nullable|integer|min:1900|max:' . date('Y'),
            'condition' => 'nullable|string',
            'apartment_type' => 'nullable|string',
            'has_garden' => 'sometimes|boolean',
            'has_parking' => 'sometimes|boolean',
            'is_mortgage_available' => 'sometimes|boolean',
            'is_from_developer' => 'sometimes|boolean',
            'landmark' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'agent_id' => 'nullable|exists:users,id',
            'owner_phone' => 'nullable|string|max:30',
            'listing_type' => 'sometimes|in:regular,vip,urgent',

            // Photos (optional on update)
            'photos' => [$isUpdate ? 'sometimes' : 'nullable', 'array', 'max:40'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['integer', 'min:0'],

            // Reorder existing
            'photo_order' => ['sometimes', 'array'],
            'photo_order.*' => ['integer', 'exists:property_photos,id'],

            // Delete list
            'delete_photo_ids' => ['sometimes', 'array'],
            'delete_photo_ids.*' => ['integer', 'exists:property_photos,id'],
        ]);
        return $validated;
    }
}
