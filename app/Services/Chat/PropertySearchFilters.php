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

        // A district/price/repair refinement still refers to the same kind of
        // property. The provider can leave this optional field null on that turn.
        $changesType = preg_match('/\b(?:новый поиск|начнем заново|начнём заново|забудь|new search|novyi poisk|любой тип|все типы|любая недвижимость|любую недвижимость|тип неважен|тип не важен|any type|all types|квартир[а-я]*|kvartir[a-z]*|apartment[s]?|flat[s]?|дом[а-я]*|dom[a-z]*|house[s]?|дач[а-я]*|участ[а-я]*|земл[а-я]*|land|plot[s]?|коммерческ[а-я]*|офис[а-я]*|commercial|office[s]?|новостро[а-я]*|novostro[a-z]*|new.buildings|вторич[а-я]*|vtorich[a-z]*|resale)\b/u', $text);
        if (! isset($args['property_type']) && isset($previous['property_type']) && ! $changesType) {
            $args['property_type'] = $previous['property_type'];
        }

        return $args;
    }
}
