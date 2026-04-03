<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::query()
            ->orderBy('city')
            ->orderBy('district')
            ->get();

        return response()->json(
            $locations->map(fn (Location $location) => $this->serializeLocation($location, $locations))
        );
    }

    public function districts(Location $location)
    {
        $locations = Location::query()
            ->where('city', $location->city)
            ->orderBy('district')
            ->get();

        return response()->json(
            $this->buildDistricts($locations)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'city' => 'required|string',
            'district' => 'required|string',
        ]);

        $location = Location::create($validated);
        return response()->json($location, 201);
    }

    public function show(Location $location)
    {
        $locations = Location::query()
            ->where('city', $location->city)
            ->orderBy('district')
            ->get();

        return response()->json($this->serializeLocation($location, $locations));
    }

    public function update(Request $request, Location $location)
    {
        $validated = $request->validate([
            'city' => 'sometimes|string',
            'district' => 'sometimes|string',
        ]);

        $location->update($validated);
        return response()->json($location);
    }

    public function destroy(Location $location)
    {
        $location->delete();
        return response()->json(['message' => 'Удалено']);
    }

    private function serializeLocation(Location $location, Collection $locations): array
    {
        $siblings = $locations
            ->where('city', $location->city)
            ->values();

        return [
            'id' => $location->id,
            'name' => $location->city,
            'city' => $location->city,
            'district' => $location->district,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'districts' => $this->buildDistricts($siblings),
        ];
    }

    private function buildDistricts(Collection $locations): array
    {
        return $locations
            ->filter(fn (Location $location) => filled($location->district))
            ->unique(fn (Location $location) => mb_strtolower(trim((string) $location->district)))
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->district,
                'district' => $location->district,
                'location_id' => $location->id,
                'city' => $location->city,
            ])
            ->values()
            ->all();
    }
}
