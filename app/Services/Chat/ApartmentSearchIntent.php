<?php

namespace App\Services\Chat;

/** Keep an explicitly requested apartment search scoped to apartments. */
class ApartmentSearchIntent
{
    public function apply(array $args, array $userMessages): array
    {
        $apartments = false;
        foreach ($userMessages as $message) {
            $text = str_replace('ё', 'е', mb_strtolower($message));
            $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            $apartment = '(?:квартир(?:а|ы|у|е|ой|ами|ах)?|kvartir(?:a|y|i|u|e|oy|ami|ah)?|apartments?|flats?)';

            // A new search, a broader selection, or an explicit different kind
            // of property releases the old apartment constraint to the provider.
            if (preg_match('/\b(?:новый поиск|начнем заново|забудь|new search|novyi poisk|любой тип|все типы|любая недвижимость|любую недвижимость|тип не ?важен|any type|all types)\b/u', $text)) {
                $apartments = false;
            }
            if (preg_match('/\b(?:дом(?:а|ов|ы)?|doma?|domov|houses?|дач[а-я]*|участ[а-я]*|земл[а-я]*|land|plots?|коммерческ[а-я]*|офис[а-я]*|commercial|offices?|новостро[а-я]*|novostro[a-z]*|new buildings|вторич[а-я]*|vtorich[a-z]*|resale|гараж[а-я]*|парков[а-я]*|garages?)\b/u', $text)
                || preg_match('/\b(?:не|без|ne|bez|not) '.$apartment.'\b/u', $text)) {
                $apartments = false;
            } elseif (preg_match('/\b'.$apartment.'\b/u', $text)) {
                $apartments = true;
            }
        }

        return $apartments ? array_replace($args, ['property_type' => 'apartment']) : $args;
    }
}
