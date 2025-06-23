<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Property::with(['type', 'status', 'location', 'photos', 'creator']);

        // Ролевая логика фильтрации
        if (!$user) {
            $query->where('moderation_status', 'approved');
        } elseif ($user->hasRole('client')) {
            $query->where('created_by', $user->id)
                ->where('moderation_status', '!=', 'deleted');
        } elseif ($user->hasRole('agent')) {
            $query->where(function ($q) use ($user) {
                $q->where('moderation_status', 'pending')
                    ->orWhere('created_by', $user->id);
            });
        }
        // admin видит всё без ограничений

        // Фильтры из фронта:
        if ($request->filled('propertyType')) {
            $query->whereHas('type', function ($q) use ($request) {
                $q->where('name', $request->propertyType);
            });
        }

        if ($request->filled('apartmentType')) {
            $query->where('apartment_type', $request->apartmentType);
        }

        if ($request->filled('city')) {
            $query->whereHas('location', function ($q) use ($request) {
                $q->where('city', $request->city);
            });
        }

        if ($request->filled('district')) {
            $query->whereHas('location', function ($q) use ($request) {
                $q->where('district', $request->district);
            });
        }

        if ($request->filled('priceFrom')) {
            $query->where('price', '>=', (float)$request->priceFrom);
        }

        if ($request->filled('priceTo') && $request->priceTo != 0) {
            $query->where('price', '<=', (float)$request->priceTo);
        }

        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->filled('areaFrom')) {
            $query->where('total_area', '>=', (float)$request->areaFrom);
        }

        if ($request->filled('areaTo') && $request->areaTo != 0) {
            $query->where('total_area', '<=', (float)$request->areaTo);
        }

        if ($request->filled('floorFrom')) {
            $query->where('floor', '>=', (int)$request->floorFrom);
        }

        if ($request->filled('floorTo') && $request->floorTo != '-') {
            $query->where('floor', '<=', (int)$request->floorTo);
        }

        if ($request->filled('repairType')) {
            $query->where('repair_type', $request->repairType);
        }

        if ($request->filled('mortgageOption')) {
            if ($request->mortgageOption === 'mortgage') {
                $query->where('is_mortgage_available', true);
            }
            if ($request->mortgageOption === 'developer') {
                $query->where('is_from_developer', true);
            }
        }

        if ($request->filled('landmark')) {
            $query->where('landmark', 'LIKE', '%' . $request->landmark . '%');
        }

        $properties = $query->paginate(20);
        return response()->json($properties);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'type_id' => 'required|exists:property_types,id',
            'status_id' => 'required|exists:property_statuses,id',
            'location_id' => 'nullable|exists:locations,id',
            'price' => 'required|numeric',
            'currency' => 'required|in:TJS,USD',
            'total_area' => 'nullable|numeric',
            'living_area' => 'nullable|numeric',
            'floor' => 'nullable|integer',
            'total_floors' => 'nullable|integer',
            'year_built' => 'nullable|integer',
            'condition' => 'nullable|string',
            'apartment_type' => 'nullable|string',
            'repair_type' => 'nullable|string',
            'has_garden' => 'boolean',
            'has_parking' => 'boolean',
            'is_mortgage_available' => 'boolean',
            'is_from_developer' => 'boolean',
            'landmark' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();

        if (auth()->user()->hasRole('client')) {
            $validated['moderation_status'] = 'pending';
        } else {
            $validated['moderation_status'] = 'approved';
        }

        $property = Property::create($validated);
        return response()->json($property, 201);
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

        return response()->json($property->load(['type', 'status', 'location', 'photos', 'creator']));
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
            'price' => 'sometimes|numeric',
            'currency' => 'sometimes|in:TJS,USD',
            'total_area' => 'nullable|numeric',
            'living_area' => 'nullable|numeric',
            'floor' => 'nullable|integer',
            'total_floors' => 'nullable|integer',
            'year_built' => 'nullable|integer',
            'condition' => 'nullable|string',
            'apartment_type' => 'nullable|string',
            'repair_type' => 'nullable|string',
            'has_garden' => 'boolean',
            'has_parking' => 'boolean',
            'is_mortgage_available' => 'boolean',
            'is_from_developer' => 'boolean',
            'landmark' => 'nullable|string',
        ]);

        $property->update($validated);
        return response()->json($property);
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
