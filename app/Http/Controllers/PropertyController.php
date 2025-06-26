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
                $q->here('created_by', $user->id);
            });
        }

        // Пример фильтрации (можно дополнить)
        if ($request->filled('priceFrom')) {
            $query->where('price', '>=', (float)$request->priceFrom);
        }
        if ($request->filled('priceTo')) {
            $query->where('price', '<=', (float)$request->priceTo);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
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
            'title' => 'sometimes|string',
            'description' => 'nullable|string',
            'type_id' => 'sometimes|exists:property_types,id',
            'status_id' => 'sometimes|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'repair_type_id' => 'nullable|exists:repair_types,id',
            'price' => 'sometimes|numeric',
            'currency' => 'sometimes|in:TJS,USD',
            'offer_type' => 'sometimes|in:rent,sale',
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
