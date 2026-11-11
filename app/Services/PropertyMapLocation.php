<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Validation\ValidationException;

final class PropertyMapLocation
{
    public static function validate(array $data, ?Property $current = null): void
    {
        if (! array_key_exists('latitude', $data) && ! array_key_exists('longitude', $data)) {
            return;
        }

        $latitude = array_key_exists('latitude', $data) ? $data['latitude'] : $current?->latitude;
        $longitude = array_key_exists('longitude', $data) ? $data['longitude'] : $current?->longitude;

        // Some clients send the full form even when only the listing text changed.
        if ($current && self::sameCoordinate($latitude, $current->latitude) && self::sameCoordinate($longitude, $current->longitude)) {
            return;
        }
        if ($latitude === null && $longitude === null) {
            return;
        }
        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages([
                $latitude === null ? 'latitude' : 'longitude' => 'Для точки на карте укажите и широту, и долготу.',
            ]);
        }
        if (! self::contains((float) $latitude, (float) $longitude)) {
            $message = 'Выберите точку на территории Таджикистана.';
            throw ValidationException::withMessages(['latitude' => $message, 'longitude' => $message]);
        }
    }

    private static function sameCoordinate(float|string|null $new, float|string|null $stored): bool
    {
        return $new === $stored || ($new !== null && $stored !== null && (float) $new === (float) $stored);
    }

    public static function contains(float $latitude, float $longitude): bool
    {
        static $boundary;
        $boundary ??= json_decode(file_get_contents(resource_path('geo/tajikistan.json')), true, flags: JSON_THROW_ON_ERROR);
        [$west, $south, $east, $north] = $boundary['bbox'];
        if ($longitude < $west || $longitude > $east || $latitude < $south || $latitude > $north) {
            return false;
        }

        foreach ($boundary['coordinates'] as $polygon) {
            // Count crossings across all rings, so a point in an interior hole is excluded.
            $inside = false;
            foreach ($polygon as $ring) {
                for ($i = 1, $count = count($ring); $i < $count; $i++) {
                    [$x1, $y1] = $ring[$i - 1];
                    [$x2, $y2] = $ring[$i];
                    if ($latitude < min($y1, $y2) || $latitude > max($y1, $y2)) {
                        continue;
                    }
                    $cross = ($longitude - $x1) * ($y2 - $y1) - ($latitude - $y1) * ($x2 - $x1);
                    if ($longitude >= min($x1, $x2) && $longitude <= max($x1, $x2)
                        && abs($cross) <= 1e-10 * hypot($x2 - $x1, $y2 - $y1)) {
                        return true;
                    }
                    if (($y1 > $latitude) !== ($y2 > $latitude) && ($y2 > $y1 ? $cross < 0 : $cross > 0)) {
                        $inside = ! $inside;
                    }
                }
            }
            if ($inside) {
                return true;
            }
        }

        return false;
    }
}
