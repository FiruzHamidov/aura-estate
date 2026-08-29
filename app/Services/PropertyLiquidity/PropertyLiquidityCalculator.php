<?php

namespace App\Services\PropertyLiquidity;

use App\Models\ClientNeed;
use App\Models\Property;
use App\Models\PropertyLiquiditySnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PropertyLiquidityCalculator
{
    public function __construct(private readonly PropertyMarketDays $marketDays) {}

    public function calculate(Property $property): ?PropertyLiquiditySnapshot
    {
        $property->loadMissing(['type', 'photos']);

        if ($this->eligibility($property)['status'] !== 'eligible') {
            $this->clearScore($property);

            return null;
        }

        $strictActive = $this->comparableQuery($property, true, false)->get();
        $strictSold = $this->comparableQuery($property, true, true)->get();
        $usedCityFallback = $strictSold->count() < (int) config('property-liquidity.minimum_sold_for_prediction', 15);

        $active = $strictActive;
        $sold = $strictSold;
        if ($usedCityFallback) {
            $active = $this->comparableQuery($property, false, false, 0.30)->get();
            $sold = $this->comparableQuery($property, false, true, 0.30)->get();
        }

        $priceSamples = $active->merge($sold)
            ->map(fn (Property $item) => $this->pricePerSquareMeter($item, $item->sold_at !== null))
            ->filter(fn (?float $value) => $value !== null && $value > 0)
            ->values();

        if ($priceSamples->count() < 3) {
            $this->clearScore($property);

            return null;
        }

        $medianPriceSqm = (float) $priceSamples->median();
        $objectPriceSqm = $this->pricePerSquareMeter($property);
        if ($objectPriceSqm === null || $medianPriceSqm <= 0) {
            $this->clearScore($property);

            return null;
        }

        $priceDelta = round(($objectPriceSqm / $medianPriceSqm - 1) * 100, 2);
        $pricePosition = $this->pricePosition($priceDelta);
        $priceScore = $this->priceScore($priceDelta);
        $districtMarket = $this->districtMarketScore($property, $strictActive, $strictSold);
        [$demandScore, $matchingNeeds] = $this->demandScore($property, max(1, $strictActive->count()));
        $apartmentFit = $this->apartmentFitScore($property);
        $interest = $this->interest($property, $strictActive);

        $weights = config('property-liquidity.weights');
        $score = (int) round(
            $districtMarket['score'] * (float) $weights['district_market']
            + $priceScore * (float) $weights['price']
            + $demandScore * (float) $weights['demand']
            + $apartmentFit * (float) $weights['apartment_fit']
        );
        $score = $this->clamp($score);

        [$confidenceScore, $confidenceLevel] = $this->confidence(
            $property,
            $strictSold->count(),
            $strictActive->count(),
            $usedCityFallback
        );
        $medianDom = $this->medianDom($sold);
        [$predictedFrom, $predictedTo] = $this->predictedDays(
            $medianDom,
            $sold->count(),
            $priceScore,
            $demandScore,
            $apartmentFit
        );
        [$promotionPriority, $promotionEligibility] = $this->promotion($property, $score, $confidenceScore, $pricePosition, $interest);
        [$factors, $recommendations] = $this->explanation($priceDelta, $matchingNeeds, $apartmentFit, $interest);
        $now = now();
        $modelVersion = (string) config('property-liquidity.model_version');

        $snapshot = PropertyLiquiditySnapshot::create([
            'property_id' => $property->id,
            'score' => $score,
            'category' => $this->category($score),
            'confidence_score' => $confidenceScore,
            'confidence_level' => $confidenceLevel,
            'predicted_days_from' => $predictedFrom,
            'predicted_days_to' => $predictedTo,
            'district_market_score' => $districtMarket['score'],
            'price_score' => $priceScore,
            'demand_score' => $demandScore,
            'apartment_fit_score' => $apartmentFit,
            'interest_score' => $interest['score'],
            'price_position' => $pricePosition,
            'price_delta_pct' => $priceDelta,
            'cohort_definition' => [
                'location_id' => $property->location_id,
                'district_id' => $property->district_id,
                'district' => $property->district,
                'type_id' => $property->type_id,
                'rooms' => $property->rooms,
                'area_tolerance_pct' => $usedCityFallback ? 30 : 20,
                'currency' => $property->currency,
                'market' => $property->is_from_developer ? 'primary' : 'secondary',
                'fallback' => $usedCityFallback ? 'city' : null,
            ],
            'cohort_sold_count' => $sold->count(),
            'cohort_active_count' => $active->count(),
            'cohort_median_dom' => $medianDom,
            'cohort_median_price_sqm' => $medianPriceSqm,
            'factors' => $factors,
            'recommendations' => $recommendations,
            'market' => [
                'matching_active_needs_count' => $matchingNeeds,
                'strict_sold_count' => $strictSold->count(),
                'strict_active_count' => $strictActive->count(),
                'sell_through_rate' => $districtMarket['sell_through_rate'],
                'currency' => $property->currency,
            ],
            'interest' => $interest,
            'model_version' => $modelVersion,
            'calculated_at' => $now,
        ]);

        $property->updateQuietly([
            'liquidity_score' => $score,
            'liquidity_category' => $snapshot->category,
            'liquidity_confidence' => $confidenceScore,
            'price_position' => $pricePosition,
            'price_delta_pct' => $priceDelta,
            'promotion_priority_score' => $promotionPriority,
            'promotion_eligibility' => $promotionEligibility,
            'liquidity_calculated_at' => $now,
            'liquidity_model_version' => $modelVersion,
        ]);

        return $snapshot;
    }

    public function eligibility(Property $property): array
    {
        $property->loadMissing('type');
        $reasons = [];
        $state = 'not_calculated';

        if ($property->floor !== null && $property->total_floors !== null && (int) $property->floor > (int) $property->total_floors) {
            return ['status' => 'data_error', 'reasons' => ['Этаж квартиры не может быть выше этажности дома.']];
        }

        foreach ([
            'location_id' => 'Не указан город.',
            'district' => 'Не указан район.',
            'price' => 'Не указана цена.',
            'currency' => 'Не указана валюта.',
            'total_area' => 'Не указана общая площадь.',
            'rooms' => 'Не указано количество комнат.',
        ] as $field => $message) {
            if ($property->{$field} === null || $property->{$field} === '' || (in_array($field, ['price', 'total_area'], true) && (float) $property->{$field} <= 0)) {
                $reasons[] = $message;
            }
        }

        if ($property->offer_type !== 'sale') {
            $reasons[] = 'MVP рассчитывает только квартиры на продажу.';
        }
        if ($property->type?->slug !== 'apartment') {
            $reasons[] = 'MVP рассчитывает только квартиры.';
        }
        if ($property->moderation_status !== Property::PUBLIC_MODERATION_STATUS || $property->sold_at !== null) {
            $reasons[] = 'Расчет доступен только для активного опубликованного объекта.';
        }

        return ['status' => $reasons === [] ? 'eligible' : $state, 'reasons' => $reasons];
    }

    private function comparableQuery(Property $property, bool $districtOnly, bool $sold, float $areaTolerance = 0.20): Builder
    {
        $area = (float) $property->total_area;
        $query = Property::query()
            ->whereKeyNot($property->id)
            ->where('offer_type', 'sale')
            ->where('type_id', $property->type_id)
            ->where('location_id', $property->location_id)
            ->where('currency', $property->currency)
            ->where('rooms', $property->rooms)
            ->whereBetween('total_area', [$area * (1 - $areaTolerance), $area * (1 + $areaTolerance)])
            ->where('is_from_developer', (bool) $property->is_from_developer)
            ->where('created_at', '>=', now()->subMonths((int) config('property-liquidity.lookback_months', 12)));

        if ($districtOnly) {
            if ($property->district_id !== null) {
                $query->where('district_id', $property->district_id);
            } else {
                $query->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower(trim((string) $property->district))]);
            }
        }

        if ($sold) {
            $query->with('statusHistory')->where('moderation_status', 'sold')->whereNotNull('sold_at');
        } else {
            $query->where('moderation_status', Property::PUBLIC_MODERATION_STATUS)->whereNull('sold_at');
        }

        return $query;
    }

    private function effectivePrice(Property $property, bool $sold = false): ?float
    {
        if ($sold && (float) $property->actual_sale_price > 0) {
            return (float) $property->actual_sale_price;
        }

        if ((float) $property->discount_price > 0) {
            return (float) $property->discount_price;
        }

        return (float) $property->price > 0 ? (float) $property->price : null;
    }

    private function pricePerSquareMeter(Property $property, bool $sold = false): ?float
    {
        $price = $this->effectivePrice($property, $sold);
        $area = (float) $property->total_area;

        return $price !== null && $area > 0 ? $price / $area : null;
    }

    private function pricePosition(float $delta): string
    {
        if ($delta <= (float) config('property-liquidity.price_position.below_market_max_pct', -5)) {
            return 'below_market';
        }

        if ($delta <= (float) config('property-liquidity.price_position.at_market_max_pct', 5)) {
            return 'at_market';
        }

        return 'above_market';
    }

    private function priceScore(float $delta): int
    {
        return $this->clamp((int) round(match (true) {
            $delta <= -10 => 100,
            $delta <= 0 => 100 - (($delta + 10) / 10) * 15,
            $delta <= 5 => 85 - ($delta / 5) * 15,
            $delta <= 10 => 70 - (($delta - 5) / 5) * 20,
            $delta <= 20 => 50 - (($delta - 10) / 10) * 30,
            default => max(0, 20 - ($delta - 20)),
        }));
    }

    private function districtMarketScore(Property $property, Collection $active, Collection $sold): array
    {
        $districtDom = $this->medianDom($sold);
        $citySold = $this->comparableQuery($property, false, true, 0.30)->get();
        $cityActive = $this->comparableQuery($property, false, false, 0.30)->get();
        $cityDom = $this->medianDom($citySold);

        $domScore = $districtDom && $cityDom ? $this->clamp((int) round(50 * $cityDom / $districtDom)) : 50;
        $sellThrough = $sold->count() / max(1, $sold->count() + $active->count());
        $citySellThrough = $citySold->count() / max(1, $citySold->count() + $cityActive->count());
        $sellScore = $citySellThrough > 0 ? $this->clamp((int) round(50 * $sellThrough / $citySellThrough)) : 50;
        $raw = (int) round($domScore * 0.60 + $sellScore * 0.40);
        $n = $sold->count();
        $smoothed = (int) round(($n / ($n + 30)) * $raw + (30 / ($n + 30)) * 50);

        return ['score' => $this->clamp($smoothed), 'sell_through_rate' => round($sellThrough, 4)];
    }

    private function medianDom(Collection $properties): ?int
    {
        $days = $properties->map(function (Property $property): ?int {
            return $this->marketDays->calculate($property);
        })->filter(fn (?int $value) => $value !== null)->values();

        return $days->isEmpty() ? null : (int) round((float) $days->median());
    }

    private function demandScore(Property $property, int $activeSupply): array
    {
        if (! class_exists(ClientNeed::class)) {
            return [50, 0];
        }

        $price = $this->effectivePrice($property) ?? 0;
        $cutoff = now()->subDays((int) config('property-liquidity.demand_freshness_days', 90));
        $needs = ClientNeed::query()
            ->where('updated_at', '>=', $cutoff)
            ->whereNull('closed_at')
            ->where('currency', $property->currency)
            ->where('location_id', $property->location_id)
            ->whereHas('type', fn (Builder $query) => $query->whereIn('slug', ['buy', 'invest']))
            ->whereHas('status', fn (Builder $query) => $query->where('is_closed', false))
            ->where(function (Builder $query) use ($property) {
                if ($property->district_id !== null) {
                    $query->where('district_id', $property->district_id);
                } else {
                    $query->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower(trim((string) $property->district))]);
                }
            })
            ->where(function (Builder $query) use ($property) {
                $query->whereNull('property_type_id')->orWhere('property_type_id', $property->type_id);
            })
            ->where(function (Builder $query) use ($price) {
                $query->whereNull('budget_from')->orWhere('budget_from', '<=', $price * 1.10);
            })
            ->where(function (Builder $query) use ($price) {
                $query->whereNull('budget_to')->orWhere('budget_to', '>=', $price * 0.90);
            })
            ->where(function (Builder $query) use ($property) {
                $query->whereNull('rooms_from')->orWhere('rooms_from', '<=', $property->rooms);
            })
            ->where(function (Builder $query) use ($property) {
                $query->whereNull('rooms_to')->orWhere('rooms_to', '>=', $property->rooms);
            })
            ->where(function (Builder $query) use ($property) {
                $query->whereNull('area_from')->orWhere('area_from', '<=', $property->total_area);
            })
            ->where(function (Builder $query) use ($property) {
                $query->whereNull('area_to')->orWhere('area_to', '>=', $property->total_area);
            })
            ->get(['client_id', 'updated_at'])
            ->unique('client_id');

        $weighted = $needs->sum(function (ClientNeed $need) {
            $age = $need->updated_at->diffInDays(now());

            return $age <= 30 ? 1.0 : ($age <= 60 ? 0.7 : 0.4);
        });
        $ratio = $weighted / max(1, $activeSupply);

        return [$this->clamp((int) round(20 + $ratio * 40)), $needs->count()];
    }

    private function apartmentFitScore(Property $property): int
    {
        $score = 100;
        if ($property->floor !== null && (int) $property->floor === 1) {
            $score -= 10;
        }
        if ($property->floor !== null && $property->total_floors !== null && (int) $property->floor === (int) $property->total_floors) {
            $score -= 7;
        }
        if ($property->floor !== null && (int) $property->floor > 5 && ! $property->features?->contains('slug', 'elevator')) {
            $score -= 15;
        }
        if (! $property->condition && ! $property->repair_type_id) {
            $score -= 8;
        }
        if (! $property->has_parking) {
            $score -= 5;
        }
        if (! $property->is_mortgage_available) {
            $score -= 5;
        }

        return $this->clamp($score);
    }

    private function interest(Property $property, Collection $peers): array
    {
        $activeDays = max(1, $this->activeDays($property));
        $velocity = round((int) $property->views_count / $activeDays, 2);
        $peerVelocities = $peers->map(fn (Property $peer) => (int) $peer->views_count / max(1, $this->activeDays($peer)));
        $percentile = $peerVelocities->isEmpty()
            ? 50
            : (int) round(100 * $peerVelocities->filter(fn (float $value) => $value <= $velocity)->count() / $peerVelocities->count());

        return [
            'score' => $activeDays < 7 ? null : $percentile,
            'views_count' => (int) $property->views_count,
            'views_per_active_day' => $velocity,
            'percentile_in_district' => $activeDays < 7 ? null : $percentile,
            'insufficient_exposure' => $activeDays < 7,
        ];
    }

    private function activeDays(Property $property): int
    {
        /** @var CarbonInterface|null $listedAt */
        $listedAt = $property->listed_at ?? $property->created_at;

        return $listedAt ? max(1, $listedAt->diffInDays(now())) : 1;
    }

    private function confidence(Property $property, int $soldCount, int $activeCount, bool $fallback): array
    {
        $required = ['location_id', 'district', 'price', 'currency', 'total_area', 'rooms', 'floor', 'total_floors'];
        $complete = collect($required)->filter(fn (string $field) => $property->{$field} !== null && $property->{$field} !== '')->count();
        $completeness = 100 * $complete / count($required);
        $sample = min(100, ($soldCount / 50 * 70) + ($activeCount / 30 * 30));
        $district = $property->district_id ? 100 : 65;
        $score = (int) round($completeness * 0.40 + $sample * 0.45 + $district * 0.15 - ($fallback ? 15 : 0));
        $score = $this->clamp($score);
        $level = $soldCount >= (int) config('property-liquidity.high_confidence_sold_count', 50) && $score >= 75
            ? 'high'
            : ($soldCount >= (int) config('property-liquidity.medium_confidence_sold_count', 15) && $score >= 45 ? 'medium' : 'low');

        return [$score, $level];
    }

    private function predictedDays(?int $medianDom, int $soldCount, int $price, int $demand, int $fit): array
    {
        if ($medianDom === null || $soldCount < (int) config('property-liquidity.minimum_sold_for_prediction', 15)) {
            return [null, null];
        }

        $multiplier = (($this->scoreMultiplier($price) + $this->scoreMultiplier($demand) + $this->scoreMultiplier($fit)) / 3);
        $days = max(1, (int) round($medianDom * $multiplier));

        return [max(1, (int) floor($days * 0.80)), (int) ceil($days * 1.20)];
    }

    private function scoreMultiplier(int $score): float
    {
        return max(0.5, min(2.0, 1.5 - $score / 100));
    }

    private function promotion(Property $property, int $score, int $confidence, string $position, array $interest): array
    {
        $minimumPhotos = (int) config('property-liquidity.promotion.minimum_photos', 5);
        $photoScore = min(50, $property->photos->count() / max(1, $minimumPhotos) * 50);
        $contentReadiness = (int) round($photoScore + (trim((string) $property->description) !== '' ? 20 : 0) + 30);
        $freshness = $property->listing_updated_at?->diffInDays(now()) <= 30 ? 100 : 50;
        $rotationDays = (int) config('property-liquidity.promotion.rotation_days', 14);
        $lastPublished = $property->socialPromotions()->where('status', 'published')->max('published_at');
        $rotation = ! $lastPublished || now()->diffInDays($lastPublished) >= $rotationDays ? 100 : 0;
        $opportunity = $interest['insufficient_exposure'] ? 50 : 100 - (int) $interest['percentile_in_district'];
        $weights = config('property-liquidity.promotion.weights');
        $priority = $this->clamp((int) round(
            $score * $weights['liquidity']
            + $contentReadiness * $weights['content_readiness']
            + $freshness * $weights['freshness']
            + $rotation * $weights['rotation']
            + $opportunity * $weights['opportunity']
        ));

        $marketEligible = $score >= (int) config('property-liquidity.liquid_score_threshold', 65)
            && in_array($position, ['below_market', 'at_market'], true)
            && $confidence >= (int) config('property-liquidity.public_badge_minimum_confidence', 45);

        if (! $marketEligible || $rotation === 0) {
            return [$priority, 'not_eligible'];
        }

        return [$priority, $property->photos->count() >= $minimumPhotos && trim((string) $property->description) !== '' ? 'eligible' : 'content_needed'];
    }

    private function explanation(float $priceDelta, int $matchingNeeds, int $fit, array $interest): array
    {
        $factors = [[
            'code' => $priceDelta < 0 ? 'price_below_median' : 'price_above_median',
            'impact' => $priceDelta <= 0 ? 'positive' : 'negative',
            'text' => sprintf('Цена за м² %s медианы аналогов на %.1f%%', $priceDelta <= 0 ? 'ниже' : 'выше', abs($priceDelta)),
        ]];
        if ($matchingNeeds > 0) {
            $factors[] = ['code' => 'active_demand', 'impact' => 'positive', 'text' => "Подходящих активных потребностей: {$matchingNeeds}"];
        }
        if ($fit < 70) {
            $factors[] = ['code' => 'apartment_fit', 'impact' => 'negative', 'text' => 'Характеристики квартиры менее востребованы в этом сегменте'];
        }
        if (! $interest['insufficient_exposure'] && (int) $interest['percentile_in_district'] < 35) {
            $factors[] = ['code' => 'low_engagement', 'impact' => 'negative', 'text' => 'Темп просмотров ниже большинства аналогов'];
        }

        $recommendations = [];
        if ($priceDelta > 5) {
            $recommendations[] = ['code' => 'review_price', 'text' => 'Обсудить с собственником цену относительно аналогов района'];
        }
        if (! $interest['insufficient_exposure'] && (int) $interest['percentile_in_district'] < 35) {
            $recommendations[] = ['code' => 'improve_media', 'text' => 'Проверить фотографии, заголовок и полноту карточки'];
        }

        return [$factors, $recommendations];
    }

    private function category(int $score): string
    {
        return match (true) {
            $score >= 80 => 'very_high',
            $score >= 65 => 'high',
            $score >= 45 => 'medium',
            $score >= 25 => 'low',
            default => 'very_low',
        };
    }

    private function clearScore(Property $property): void
    {
        $property->updateQuietly([
            'liquidity_score' => null,
            'liquidity_category' => null,
            'liquidity_confidence' => null,
            'price_position' => null,
            'price_delta_pct' => null,
            'promotion_priority_score' => null,
            'promotion_eligibility' => 'not_eligible',
            'liquidity_calculated_at' => now(),
            'liquidity_model_version' => (string) config('property-liquidity.model_version'),
        ]);
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
