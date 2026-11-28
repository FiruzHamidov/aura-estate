<?php

namespace App\Services\Chat;

class PropertySearchFilters
{
    public function normalize(array $args): array
    {
        foreach (['priceFrom', 'priceTo', 'roomsFrom', 'roomsTo', 'areaFrom', 'areaTo', 'price_min', 'price_max', 'rooms_min', 'rooms_max', 'rooms', 'limit'] as $key) {
            if (array_key_exists($key, $args) && (! is_numeric($args[$key]) || (float) $args[$key] <= 0)) {
                unset($args[$key]);
            }
        }
        foreach (['city', 'property_type', 'offer_type', 'currency', 'sort_by', 'repair_condition', 'renovation_permission_status'] as $key) {
            if (array_key_exists($key, $args) && ($args[$key] === null || $args[$key] === '')) {
                unset($args[$key]);
            }
        }
        if (array_key_exists('districts', $args) && ($args['districts'] === null || $args['districts'] === [])) {
            unset($args['districts']);
        }

        return $args;
    }

    public function forMessage(array $args, string $message, array $previous = []): array
    {
        $args = $this->normalize($args);
        $previous = $this->normalize($previous);
        $text = trim(mb_strtolower($message), " \t\n\r\0\x0B.!?");
        $offer = match ($text) {
            'prodaju', 'prodazhu', 'prodazha', 'продажа', 'на продажу', 'купить', 'sale' => 'sale',
            'arenda', 'аренда', 'в аренду', 'снять', 'rent' => 'rent',
            default => null,
        };
        $confirmation = in_array($text, ['davay', 'davai', 'давай', 'да', 'давайте', 'покажи', 'покажи еще', 'покажи ещё'], true);
        if ($previous !== [] && ($offer !== null || $confirmation)) {
            // A short confirmation does not cancel the last search's type, area,
            // room count, or repair requirements, even if the model forgets them.
            return $offer === null ? $previous : array_replace($previous, ['offer_type' => $offer]);
        }

        return $args;
    }
}
