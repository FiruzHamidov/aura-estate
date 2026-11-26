<?php

namespace App\Support;

use Illuminate\Http\Request;

/** A presentation preference, never a replacement for authorization. */
final class ClientPresentation
{
    public static function enabled(?Request $request = null): bool
    {
        return ($request ?? request())->header('X-Aura-Client-Presentation') === '1';
    }

    public static function property(array $payload): array
    {
        if (! self::enabled()) {
            return $payload;
        }

        foreach (['address', 'landmark', 'contract_type', 'contract_type_id', 'is_co_owned',
            'views_count', 'publication_expires_at', 'offer_source', 'internal_notes'] as $field) {
            unset($payload[$field]);
        }
        foreach (['latitude', 'longitude'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = self::coordinate($payload[$field]);
            }
        }
        $payload['public_view_only'] = true;

        return $payload;
    }

    public static function coordinate(float|string $value): float
    {
        return self::enabled() ? round((float) $value, 2) : (float) $value;
    }
}
