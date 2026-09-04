<?php

namespace App\Services;

use App\Models\Location;

final class PropertyQualityService
{
    /** @return list<array{code:string,severity:string,message:string}> */
    public function inspect(array $data): array
    {
        $warnings = [];
        $discountPrice = (float) ($data['discount_price'] ?? 0);
        $price = $discountPrice > 0 ? $discountPrice : (float) ($data['price'] ?? 0);
        $currency = $data['currency'] ?? 'TJS';
        $offerType = $data['offer_type'] ?? 'sale';
        $minimum = $offerType === 'sale'
            ? ($currency === 'USD' ? 5_000 : 50_000)
            : ($currency === 'USD' ? 50 : 500);

        if ($price > 0 && $price < $minimum) {
            $warnings[] = $this->warning(
                'suspicious_price',
                "Цена {$price} {$currency} выглядит слишком низкой для типа сделки. Проверьте, не пропущены ли нули."
            );
        }

        $phone = preg_replace('/\D+/', '', (string) ($data['owner_phone'] ?? '')) ?? '';
        if ($phone !== '' && ! in_array(strlen($phone), [9, 12], true)) {
            $warnings[] = $this->warning(
                'suspicious_owner_phone',
                'Телефон владельца выглядит неполным или содержит лишние цифры.'
            );
        }

        $latProvided = isset($data['latitude']) && $data['latitude'] !== '';
        $lngProvided = isset($data['longitude']) && $data['longitude'] !== '';
        if ($latProvided xor $lngProvided) {
            $warnings[] = $this->warning(
                'incomplete_coordinates',
                'Для точки на карте должны быть указаны и широта, и долгота.'
            );
        }

        if ($latProvided && $lngProvided && ! empty($data['location_id'])) {
            $location = Location::query()->find($data['location_id']);
            if ($location?->latitude !== null && $location?->longitude !== null) {
                $distance = $this->distanceKm(
                    (float) $data['latitude'],
                    (float) $data['longitude'],
                    (float) $location->latitude,
                    (float) $location->longitude
                );
                if ($distance > 100) {
                    $warnings[] = $this->warning(
                        'coordinates_outside_location',
                        'Точка на карте находится далеко от выбранного города/локации. Проверьте адрес и координаты.'
                    );
                }
            }
        }

        return $warnings;
    }

    /** @return array{code:string,severity:string,message:string} */
    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'severity' => 'warning', 'message' => $message];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }
}
