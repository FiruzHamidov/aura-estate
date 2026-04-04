<?php

namespace App\Services\Crm\Matching;

use App\Models\ClientNeed;
use App\Models\Property;

class MatchScoreCalculator
{
    public function score(ClientNeed $need, Property $property): array
    {
        $typeSlug = (string) ($need->type?->slug ?? '');

        if (!$this->offerTypeMatches($typeSlug, (string) $property->offer_type)) {
            return [
                'score' => 0,
                'reason_codes' => [],
            ];
        }

        $score = 20;
        $reasonCodes = ['offer_type_match'];

        $districtScore = $this->districtScore((string) $need->district, (string) $property->district);
        if (!empty($need->location_id) && (int) $need->location_id === (int) $property->location_id) {
            $score += 20;
            $reasonCodes[] = 'location_match';
        } elseif ($districtScore >= 1.0) {
            $score += 15;
            $reasonCodes[] = 'district_match';
        } elseif ($districtScore >= 0.6) {
            $score += 8;
            $reasonCodes[] = 'district_partial';
        }

        $propertyTypeIds = $need->property_type_ids;
        if ($propertyTypeIds !== [] && in_array((int) $property->type_id, $propertyTypeIds, true)) {
            $score += 15;
            $reasonCodes[] = 'property_type_match';
        }

        $budgetScore = $this->budgetScore($need, $property);
        $score += $budgetScore['score'];
        if ($budgetScore['reason_code']) {
            $reasonCodes[] = $budgetScore['reason_code'];
        }

        $roomsScore = $this->rangeScore(
            $need->rooms_from,
            $need->rooms_to,
            $property->rooms,
            10,
            5,
            1
        );
        $score += $roomsScore['score'];
        if ($roomsScore['reason_code']) {
            $reasonCodes[] = $roomsScore['reason_code'] === 'match'
                ? 'rooms_match'
                : 'rooms_near';
        }

        $areaScore = $this->areaScore($need, $property);
        $score += $areaScore['score'];
        if ($areaScore['reason_code']) {
            $reasonCodes[] = $areaScore['reason_code'];
        }

        if (
            !empty($need->repair_type_id)
            && !empty($property->repair_type_id)
            && (int) $need->repair_type_id === (int) $property->repair_type_id
        ) {
            $score += 5;
            $reasonCodes[] = 'repair_match';
        }

        if ($need->wants_mortgage && $property->is_mortgage_available) {
            $score += 5;
            $reasonCodes[] = 'mortgage_match';
        }

        if ($typeSlug === 'invest' && $property->is_from_developer) {
            $score += 5;
            $reasonCodes[] = 'invest_developer_bonus';
        }

        if ($property->created_at && $property->created_at->greaterThan(now()->subDays(14))) {
            $score += 3;
            $reasonCodes[] = 'fresh_property';
        }

        if ($need->created_at && $need->created_at->greaterThan(now()->subDays(14))) {
            $score += 2;
            $reasonCodes[] = 'fresh_need';
        }

        return [
            'score' => min(100, max(0, $score)),
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }

    public function offerTypeMatches(string $needTypeSlug, string $propertyOfferType): bool
    {
        return match ($needTypeSlug) {
            'buy', 'invest' => $propertyOfferType === 'sale',
            'rent' => $propertyOfferType === 'rent',
            default => false,
        };
    }

    private function budgetScore(ClientNeed $need, Property $property): array
    {
        if ($property->price === null) {
            return ['score' => 0, 'reason_code' => null];
        }

        if (!empty($need->currency) && !empty($property->currency) && $need->currency !== $property->currency) {
            return ['score' => 0, 'reason_code' => null];
        }

        $price = (float) $property->price;
        $budgetFrom = $need->budget_from !== null ? (float) $need->budget_from : null;
        $budgetTo = $need->budget_total !== null
            ? (float) $need->budget_total
            : ($need->budget_to !== null ? (float) $need->budget_to : null);

        if ($budgetFrom === null && $budgetTo === null) {
            return ['score' => 0, 'reason_code' => null];
        }

        if (
            ($budgetFrom === null || $price >= $budgetFrom)
            && ($budgetTo === null || $price <= $budgetTo)
        ) {
            return ['score' => 20, 'reason_code' => 'budget_in_range'];
        }

        if ($budgetTo !== null && $price <= $budgetTo * 1.15) {
            return ['score' => 10, 'reason_code' => 'budget_near_range'];
        }

        if ($budgetFrom !== null && $price >= $budgetFrom * 0.85 && $price < $budgetFrom) {
            return ['score' => 8, 'reason_code' => 'budget_near_range'];
        }

        return ['score' => 0, 'reason_code' => null];
    }

    private function areaScore(ClientNeed $need, Property $property): array
    {
        if ($property->total_area === null) {
            return ['score' => 0, 'reason_code' => null];
        }

        $value = (float) $property->total_area;
        $from = $need->area_from !== null ? (float) $need->area_from : null;
        $to = $need->area_to !== null ? (float) $need->area_to : null;

        if ($from === null && $to === null) {
            return ['score' => 0, 'reason_code' => null];
        }

        if (($from === null || $value >= $from) && ($to === null || $value <= $to)) {
            return ['score' => 10, 'reason_code' => 'area_match'];
        }

        $tolerance = max(5.0, $value * 0.1);

        if ($from !== null && $value >= ($from - $tolerance) && $value < $from) {
            return ['score' => 5, 'reason_code' => 'area_near'];
        }

        if ($to !== null && $value > $to && $value <= ($to + $tolerance)) {
            return ['score' => 5, 'reason_code' => 'area_near'];
        }

        return ['score' => 0, 'reason_code' => null];
    }

    private function rangeScore(
        mixed $from,
        mixed $to,
        mixed $value,
        int $matchScore,
        int $nearScore,
        int|float $nearTolerance
    ): array {
        if ($value === null || ($from === null && $to === null)) {
            return ['score' => 0, 'reason_code' => null];
        }

        $numericValue = (float) $value;
        $numericFrom = $from !== null ? (float) $from : null;
        $numericTo = $to !== null ? (float) $to : null;

        if (
            ($numericFrom === null || $numericValue >= $numericFrom)
            && ($numericTo === null || $numericValue <= $numericTo)
        ) {
            return ['score' => $matchScore, 'reason_code' => 'match'];
        }

        if ($numericFrom !== null && abs($numericValue - $numericFrom) <= $nearTolerance) {
            return ['score' => $nearScore, 'reason_code' => 'near'];
        }

        if ($numericTo !== null && abs($numericValue - $numericTo) <= $nearTolerance) {
            return ['score' => $nearScore, 'reason_code' => 'near'];
        }

        return ['score' => 0, 'reason_code' => null];
    }

    private function districtScore(string $needDistrict, string $propertyDistrict): float
    {
        $left = $this->normalizeText($needDistrict);
        $right = $this->normalizeText($propertyDistrict);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 0.7;
        }

        similar_text($left, $right, $percent);

        return $percent / 100;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['ё'], ['е'], $value);

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
