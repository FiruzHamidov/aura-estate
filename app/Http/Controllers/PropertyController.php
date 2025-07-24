<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

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
        $query = Property::with(['type', 'status', 'location', 'repairType', 'photos', 'creator']);

        if (!$user) {
            $query->where('moderation_status', 'approved');
        } elseif ($user->hasRole('client')) {
            $query->where('created_by', $user->id)->where('moderation_status', '!=', 'deleted');
        } elseif ($user->hasRole('agent')) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id);
            });
        }

        // LIKE-поиск
        foreach (['title', 'description', 'district', 'address', 'landmark', 'condition', 'apartment_type', 'owner_phone'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, 'like', '%' . $request->input($field) . '%');
            }
        }

        // Равенство
        foreach ([
                     'type_id', 'status_id', 'location_id', 'repair_type_id',
                     'currency', 'offer_type',
                     'has_garden', 'has_parking', 'is_mortgage_available', 'is_from_developer',
                     'latitude', 'longitude', 'agent_id'
                 ] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        // Диапазоны
        $rangeFilters = [
            'price' => 'price',
            'rooms' => 'rooms',
            'total_area' => 'total_area',
            'living_area' => 'living_area',
            'floor' => 'floor',
            'total_floors' => 'total_floors',
            'year_built' => 'year_built',
        ];

        foreach ($rangeFilters as $param => $column) {
            $from = $request->input($param . 'From');
            $to = $request->input($param . 'To');

            if ($from !== null) {
                $query->where($column, '>=', $from);
            }
            if ($to !== null) {
                $query->where($column, '<=', $to);
            }
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'district' => 'nullable|string',
            'address' => 'nullable|string',
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
        ]);

        $validated['created_by'] = auth()->id();
        $validated['moderation_status'] = auth()->user()->hasRole('client') ? 'pending' : 'approved';

        $property = Property::create($validated);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $image = $this->imageManager->read($photo)
                    ->resize(1600, 1200, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });

                $jpeg = new JpegEncoder(80);
                $binary = $image->encode($jpeg);

                $filename = 'properties/' . uniqid() . '.jpg';
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

        return response()->json($property->load(['type', 'status', 'location', 'repairType', 'photos', 'creator']));
    }

    public function update(Request $request, Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'type_id' => 'sometimes|exists:property_types,id',
            'status_id' => 'sometimes|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'price' => 'required|numeric',
            'currency' => 'sometimes|in:TJS,USD',
            'offer_type' => 'sometimes|in:rent,sale',
            'rooms' => 'nullable|integer|min:1|max:10',
            'youtube_link' => 'nullable|url',
            'total_area' => 'required|numeric',
            'living_area' => 'nullable|numeric',
            'floor' => 'required|integer',
            'total_floors' => 'required|integer',
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
            'photos.*' => 'required|image|max:10240',
            'agent_id' => 'nullable|exists:users,id',
            'owner_phone' => 'required|string|max:30',
        ]);

        $property->update($validated);

        if ($request->hasFile('photos')) {
            foreach ($property->photos as $oldPhoto) {
                \Storage::disk('public')->delete($oldPhoto->path);
                $oldPhoto->delete();
            }

            foreach ($request->file('photos') as $photo) {
                $image = $this->imageManager->read($photo)
                    ->resize(1600, 1200, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });

                $jpeg = new JpegEncoder(80);
                $binary = $image->encode($jpeg);

                $filename = 'properties/' . uniqid() . '.jpg';
                \Storage::disk('public')->put($filename, $binary);
                $property->photos()->create(['file_path' => $filename]);
            }
        }

        return response()->json($property->load(['photos']));
    }

    public function destroy(Property $property)
    {
        if (auth()->user()->hasRole('client') && $property->created_by !== auth()->id()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $property->update(['moderation_status' => 'deleted']);
        return response()->json(['message' => 'Объект помечен как удалён']);
    }
}
