<?php

namespace App\Services\PropertyLiquidity;

use App\Models\Property;

final class PropertyAmenities
{
    public static function parking(Property $property): ?bool
    {
        // An explicit type takes precedence over legacy flags and feature selections.
        if ($property->parking_type_id) {
            $name = mb_strtolower(trim((string) $property->parking?->name));
            if ($name === 'отсутствует') return false;
            if ($name !== '') return true;
        }
        if ($property->has_parking) return true;
        if ($property->features->contains(fn ($feature) => in_array($feature->slug, [
            'underground-parking', 'surface-parking', 'svobodnay parkovka',
        ], true))) return true;

        return null;
    }

    public static function mortgage(Property $property): ?bool
    {
        return match ($property->mortgage_availability_status) {
            'available' => true,
            'unavailable' => false,
            default => $property->is_mortgage_available ? true : null,
        };
    }
}
