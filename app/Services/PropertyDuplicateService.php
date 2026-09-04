<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PropertyDuplicateService
{
    private const ACTIVE_STATUSES_EXCLUDED = [
        'deleted', 'rejected', 'denied', 'draft', 'sold', 'rented', 'sold_by_owner',
    ];

    private const SCORE_THRESHOLD = 58.0;

    /** @return Collection<int, array<string, mixed>> */
    public function find(array $data, ?int $excludePropertyId = null): Collection
    {
        $query = Property::query()
            ->with(['photos' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->whereNotIn('moderation_status', self::ACTIVE_STATUSES_EXCLUDED)
            ->where('offer_type', $data['offer_type'] ?? 'sale');

        if ($excludePropertyId !== null) {
            $query->whereKeyNot($excludePropertyId);
        }

        $this->applyCandidateFilter($query, $data);

        return $query
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Property $candidate) => $this->score($data, $candidate))
            ->filter()
            ->sortByDesc('score')
            ->take(10)
            ->values();
    }

    private function applyCandidateFilter(Builder $query, array $data): void
    {
        $phone = $this->normalizePhone($data['owner_phone'] ?? null);
        $area = $this->number($data, 'total_area');
        $lat = $this->number($data, 'latitude');
        $lng = $this->number($data, 'longitude');
        $tokens = array_slice($this->distinctiveTokens($this->searchableText($data)), 0, 6);

        $query->where(function (Builder $candidates) use ($data, $phone, $area, $lat, $lng, $tokens) {
            if ($phone !== '') {
                $lastNine = substr($phone, -9);
                $normalizedPhoneSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(owner_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')";
                $candidates->orWhereRaw($normalizedPhoneSql.' LIKE ?', ['%'.$lastNine]);
            }

            if (
                isset($data['type_id'], $data['rooms'], $data['floor'])
                && $area !== null
            ) {
                $candidates->orWhere(function (Builder $passport) use ($data, $area) {
                    $passport
                        ->where('type_id', $data['type_id'])
                        ->where('rooms', $data['rooms'])
                        ->where('floor', $data['floor'])
                        ->whereBetween('total_area', [$area - 2.0, $area + 2.0]);

                    if (! empty($data['location_id'])) {
                        $passport->where('location_id', $data['location_id']);
                    }
                });
            }

            if ($lat !== null && $lng !== null) {
                $deltaLat = 0.0018;
                $deltaLng = 0.0018 * max(0.2, cos(deg2rad(max(1e-6, $lat))));
                $candidates->orWhere(function (Builder $geo) use ($lat, $lng, $deltaLat, $deltaLng) {
                    $geo->whereBetween('latitude', [$lat - $deltaLat, $lat + $deltaLat])
                        ->whereBetween('longitude', [$lng - $deltaLng, $lng + $deltaLng]);
                });
            }

            foreach ($tokens as $token) {
                $candidates->orWhere(function (Builder $text) use ($token, $data, $area) {
                    $text->where(function (Builder $fields) use ($token) {
                        $like = '%'.$token.'%';
                        $fields->where('address', 'like', $like)
                            ->orWhere('landmark', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    });

                    if (isset($data['rooms'])) {
                        $text->where('rooms', $data['rooms']);
                    }
                    if ($area !== null) {
                        $text->whereBetween('total_area', [$area - 2.0, $area + 2.0]);
                    }
                });
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function score(array $source, Property $candidate): ?array
    {
        $signals = [];
        $score = 0.0;
        $passportMatches = 0;
        $physicalMatches = 0;

        $phoneMatch = $this->samePhone($source['owner_phone'] ?? null, $candidate->owner_phone);
        $ownerClientMatch = $this->sameNullableId($source['owner_client_id'] ?? null, $candidate->owner_client_id);
        if ($phoneMatch) {
            $score += 40;
            $signals[] = $this->signal('phone', 'Телефон владельца', true, 'совпадает', 40);
        }
        if ($ownerClientMatch) {
            $score += 45;
            $signals[] = $this->signal('owner_client', 'Карточка владельца', true, 'совпадает', 45);
        }

        $passportRules = [
            ['type_id', 'Тип недвижимости', 7, 0.0],
            ['location_id', 'Город/локация', 4, 0.0],
            ['district_id', 'Район', 4, 0.0],
            ['district', 'Район', 4, 0.0],
            ['rooms', 'Комнаты', 10, 0.0],
            ['total_area', 'Площадь', 10, 2.0],
            ['floor', 'Этаж', 8, 0.0],
            ['total_floors', 'Этажность', 7, 0.0],
            ['repair_type_id', 'Ремонт', 5, 0.0],
            ['developer_id', 'Застройщик', 4, 0.0],
            ['year_built', 'Год постройки', 3, 1.0],
        ];

        $seenLabels = [];
        foreach ($passportRules as [$field, $label, $weight, $tolerance]) {
            if (isset($seenLabels[$label])) {
                continue;
            }
            $match = $this->fieldMatches($source[$field] ?? null, $candidate->{$field}, (float) $tolerance);
            if ($match === null) {
                continue;
            }
            if ($match) {
                $score += $weight;
                $passportMatches++;
                if (in_array($field, ['rooms', 'total_area', 'floor', 'total_floors', 'repair_type_id', 'developer_id', 'year_built'], true)) {
                    $physicalMatches++;
                }
                $signals[] = $this->signal($field, $label, true, $this->fieldDetail($field, $source[$field]), $weight);
                $seenLabels[$label] = true;
            }
        }

        $sourceTokens = $this->distinctiveTokens($this->searchableText($source));
        $candidateTokens = $this->distinctiveTokens($this->searchableText($candidate->getAttributes()));
        $sharedTokens = array_values(array_intersect($sourceTokens, $candidateTokens));
        $textSimilarity = $this->tokenSimilarity($sourceTokens, $candidateTokens);
        if ($sharedTokens !== []) {
            $textWeight = min(25.0, 10.0 + count($sharedTokens) * 3.0 + $textSimilarity * 0.12);
            $score += $textWeight;
            $signals[] = $this->signal(
                'text',
                'Адрес, ориентир и описание',
                true,
                'общие слова: '.implode(', ', array_slice($sharedTokens, 0, 5)),
                round($textWeight, 1)
            );
        }

        $distanceKm = $this->distanceKm($source, $candidate->getAttributes());
        $geoNear = $distanceKm !== null && $distanceKm <= 0.2;
        $geoConflict = $distanceKm !== null && $distanceKm >= 2.0 && $passportMatches >= 4;
        if ($geoNear) {
            $score += 20;
            $signals[] = $this->signal('geo', 'Точка на карте', true, round($distanceKm * 1000).' м', 20);
        } elseif ($geoConflict) {
            $signals[] = $this->signal('geo', 'Точка на карте', false, round($distanceKm, 1).' км между отметками', 0);
        }

        $priceDelta = $this->priceDeltaPercent($source, $candidate->getAttributes());
        $priceNear = $priceDelta !== null && $priceDelta <= 15.0;
        if ($priceNear) {
            $score += 8;
            $signals[] = $this->signal('price', 'Цена', true, 'разница '.round($priceDelta, 1).'%', 8);
        }

        $unitConflict = $this->explicitUnitConflict($this->searchableText($source), $this->searchableText($candidate->getAttributes()));
        if ($unitConflict && ! $phoneMatch && ! $ownerClientMatch) {
            return null;
        }

        $supportingMatch = $physicalMatches >= 1 || $sharedTokens !== [] || $geoNear;
        $qualified = (($phoneMatch || $ownerClientMatch) && $supportingMatch)
            || $score >= self::SCORE_THRESHOLD
            || ($passportMatches >= 6 && count($sharedTokens) >= 1);

        if (! $qualified) {
            return null;
        }

        $score = min(100.0, $score);

        return [
            'id' => (int) $candidate->id,
            'title' => $candidate->title,
            'address' => $candidate->address,
            'landmark' => $candidate->landmark,
            'description' => $candidate->description,
            'owner_name' => $candidate->owner_name,
            'owner_phone' => $candidate->owner_phone,
            'type_id' => $candidate->type_id,
            'location_id' => $candidate->location_id,
            'district' => $candidate->district,
            'rooms' => $candidate->rooms,
            'total_area' => $candidate->total_area,
            'floor' => $candidate->floor,
            'total_floors' => $candidate->total_floors,
            'repair_type_id' => $candidate->repair_type_id,
            'developer_id' => $candidate->developer_id,
            'year_built' => $candidate->year_built,
            'price' => $candidate->price,
            'currency' => $candidate->currency,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
            'moderation_status' => $candidate->moderation_status,
            'created_at' => $candidate->created_at,
            'photos' => $candidate->photos->map(fn ($photo) => [
                'id' => (int) $photo->id,
                'file_path' => $photo->file_path,
                'position' => (int) $photo->position,
            ])->values(),
            'score' => round($score, 1),
            'risk' => $score >= 80 ? 'high' : ($score >= 65 ? 'medium' : 'low'),
            'links' => ['view' => 'https://aura.tj/apartment/'.$candidate->id],
            'signals' => $signals,
            'summary' => [
                'passport_matches' => $passportMatches,
                'shared_text_tokens' => array_slice($sharedTokens, 0, 8),
                'geo_distance_km' => $distanceKm !== null ? round($distanceKm, 3) : null,
                'coordinates_conflict' => $geoConflict,
                'price_delta_percent' => $priceDelta !== null ? round($priceDelta, 1) : null,
            ],
        ];
    }

    /** @return array{code:string,label:string,matched:bool,detail:string,weight:float|int} */
    private function signal(string $code, string $label, bool $matched, string $detail, float|int $weight): array
    {
        return compact('code', 'label', 'matched', 'detail', 'weight');
    }

    private function searchableText(array $data): string
    {
        return implode(' ', array_filter([
            $data['address'] ?? null,
            $data['landmark'] ?? null,
            $data['description'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== ''));
    }

    /** @return list<string> */
    private function distinctiveTokens(string $text): array
    {
        $text = mb_strtolower(strtr($text, ['ё' => 'е']), 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = array_flip([
            'аура', 'estate', 'душанбе', 'продается', 'продаётся', 'квартира', 'комната', 'комнатная',
            'недвижимость', 'агентство', 'агент', 'цена', 'район', 'адрес', 'этаж', 'этажность',
            'площадь', 'ремонт', 'документ', 'сомони', 'телефон', 'владелец', 'собственник', 'полная',
            'полноценная', 'сделка', 'безопасность', 'покупка', 'надежный', 'надёжный', 'партнер', 'партнёр',
        ]);

        return array_values(array_unique(array_filter($parts, fn ($token) => mb_strlen($token, 'UTF-8') >= 4 && ! isset($stopWords[$token]) && ! ctype_digit($token)
        )));
    }

    /** @param list<string> $first @param list<string> $second */
    private function tokenSimilarity(array $first, array $second): float
    {
        if ($first === [] || $second === []) {
            return 0.0;
        }

        return count(array_intersect($first, $second)) / max(1, min(count($first), count($second))) * 100;
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 9) {
            return '992'.$digits;
        }

        return $digits;
    }

    private function samePhone(?string $first, ?string $second): bool
    {
        $first = $this->normalizePhone($first);
        $second = $this->normalizePhone($second);

        return $first !== '' && $second !== '' && $first === $second;
    }

    private function sameNullableId(mixed $first, mixed $second): bool
    {
        return $first !== null && $first !== '' && $second !== null && (int) $first === (int) $second;
    }

    private function fieldMatches(mixed $first, mixed $second, float $tolerance): ?bool
    {
        if ($first === null || $first === '' || $second === null || $second === '') {
            return null;
        }
        if ($tolerance > 0) {
            return abs((float) $first - (float) $second) <= $tolerance;
        }

        return mb_strtolower(trim((string) $first), 'UTF-8') === mb_strtolower(trim((string) $second), 'UTF-8');
    }

    private function fieldDetail(string $field, mixed $value): string
    {
        return match ($field) {
            'total_area' => $value.' м²',
            'floor' => $value.' этаж',
            'total_floors' => $value.' этажей',
            'rooms' => $value.' комн.',
            default => 'совпадает',
        };
    }

    private function number(array $data, string $field): ?float
    {
        return isset($data[$field]) && is_numeric($data[$field]) ? (float) $data[$field] : null;
    }

    private function distanceKm(array $first, array $second): ?float
    {
        $lat1 = $this->number($first, 'latitude');
        $lng1 = $this->number($first, 'longitude');
        $lat2 = $this->number($second, 'latitude');
        $lng2 = $this->number($second, 'longitude');
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }

    private function priceDeltaPercent(array $first, array $second): ?float
    {
        if (($first['currency'] ?? null) !== ($second['currency'] ?? null)) {
            return null;
        }
        $firstPrice = $this->effectivePrice($first);
        $secondPrice = $this->effectivePrice($second);
        if ($firstPrice <= 0 || $secondPrice <= 0) {
            return null;
        }

        return abs($firstPrice - $secondPrice) / max($firstPrice, $secondPrice) * 100;
    }

    private function effectivePrice(array $data): float
    {
        $discount = isset($data['discount_price']) ? (float) $data['discount_price'] : 0.0;

        return $discount > 0 ? $discount : (float) ($data['price'] ?? 0);
    }

    private function explicitUnitConflict(string $first, string $second): bool
    {
        $firstUnit = $this->extractUnitNumber($first);
        $secondUnit = $this->extractUnitNumber($second);

        return $firstUnit !== null && $secondUnit !== null && $firstUnit !== $secondUnit;
    }

    private function extractUnitNumber(string $text): ?string
    {
        if (preg_match('/(?:кв(?:артира)?\.?|апартамент(?:ы)?|unit)\s*[№#-]?\s*(\d{1,5})/iu', $text, $match)) {
            return ltrim($match[1], '0') ?: '0';
        }

        return null;
    }
}
