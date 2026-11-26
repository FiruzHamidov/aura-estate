<?php

namespace App\Services\PropertyLiquidity;

use App\Models\User;
use App\Support\RopGroupAccess;
use App\Models\Property;
use App\Models\PropertyLiquiditySnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PropertyLiquidityCalculator
{
    private const APARTMENT_TYPE_SLUGS = [
        'apartment', 'secondary', 'new-buildings', 'room',
    ];

    private const RESIDENTIAL_TYPE_SLUGS = [
        'apartment', 'secondary', 'new-buildings', 'room',
        'house', 'houses', 'cottage',
    ];

    public function __construct(
        private readonly PropertyMarketDays $marketDays,
        private readonly PropertyDemandBenchmark $demandBenchmark,
    ) {}

    public function calculate(Property $property): ?PropertyLiquiditySnapshot
    {
        return $this->buildSnapshot($property);
    }

    public function previewForRop(Property $property, User $actor): ?PropertyLiquiditySnapshot
    {
        abort_unless($actor->usesAssignedGroupScope(), 403, 'FORBIDDEN_ACTION');
        app(RopGroupAccess::class)->ensureVisible($actor, $property);

        return $this->buildSnapshot($property, $actor);
    }

    private function buildSnapshot(Property $property, ?User $actor = null): ?PropertyLiquiditySnapshot
    {
        $property->loadMissing(['type', 'photos']);

        if ($this->eligibility($property)['status'] !== 'eligible') {
            if (! $actor) $this->clearScore($property);

            return null;
        }

        $strictActive = $this->comparableQuery($property, true, false, 0.20, $actor)->get();
        $strictSold = $this->comparableQuery($property, true, true, 0.20, $actor)->get();
        $usedCityFallback = $strictSold->count() < (int) config('property-liquidity.minimum_sold_for_prediction', 15);

        $active = $strictActive;
        $sold = $strictSold;
        if ($usedCityFallback) {
            $active = $this->comparableQuery($property, false, false, 0.30, $actor)->get();
            $sold = $this->comparableQuery($property, false, true, 0.30, $actor)->get();
        }

        $priceSamples = $active->merge($sold)
            ->map(fn (Property $item) => $this->pricePerSquareMeter($item, $item->sold_at !== null))
            ->filter(fn (?float $value) => $value !== null && $value > 0)
            ->values();

        if ($priceSamples->count() < 3) {
            if (! $actor) $this->clearScore($property);

            return null;
        }

        $medianPriceSqm = (float) $priceSamples->median();
        $objectPriceSqm = $this->pricePerSquareMeter($property);
        if ($objectPriceSqm === null || $medianPriceSqm <= 0) {
            if (! $actor) $this->clearScore($property);

            return null;
        }

        $priceDelta = round(($objectPriceSqm / $medianPriceSqm - 1) * 100, 2);
        $pricePosition = $this->pricePosition($priceDelta);
        $priceScore = $this->priceScore($priceDelta);
        $districtMarket = $this->districtMarketScore($property, $strictActive, $strictSold, $actor);
        [$demandScore, $matchingNeeds, $demandAvailable] = $this->demandBenchmark->score($property, $this->usesRooms($property), $actor);
        $propertyFit = $this->propertyFitScore($property);
        $interest = $this->interest($property, $strictActive);

        $components = [
            'district_market' => $districtMarket['available'] ? $districtMarket['score'] : null,
            'price' => $priceScore,
            'demand' => $demandAvailable ? $demandScore : null,
            'apartment_fit' => $propertyFit,
        ];
        $score = LiquidityMath::weightedScore($components, config('property-liquidity.weights'));

        [$confidenceScore, $confidenceLevel] = $this->confidence(
            $property,
            $strictSold->count(),
            $strictActive->count(),
            $usedCityFallback,
            count(array_filter($components, fn ($value) => $value === null))
        );
        $medianDom = $this->medianDom($sold);
        [$predictedFrom, $predictedTo] = $this->predictedDays(
            $medianDom,
            $sold->count(),
            $priceScore,
            $demandScore,
            $propertyFit
        );
        [$promotionPriority, $promotionEligibility] = $this->promotion($property, $score, $confidenceScore, $pricePosition, $interest);
        [$factors, $recommendations] = $this->explanation($priceDelta, $matchingNeeds, $propertyFit, $interest);
        if (in_array(null, $components, true)) {
            $factors[] = ['impact' => 'neutral', 'code' => 'incomplete_market_data', 'text' => 'Недостаточно данных рынка или спроса: оценка рассчитана по доступным компонентам.'];
        }
        if (PropertyAmenities::parking($property) === null || ($this->isResidentialType($property) && PropertyAmenities::mortgage($property) === null)) {
            $recommendations[] = ['code' => 'clarify_amenities', 'text' => 'Уточните парковку и возможность ипотеки для повышения достоверности оценки.'];
        }
        $now = now();
        $modelVersion = (string) config('property-liquidity.model_version');

        $snapshot = new PropertyLiquiditySnapshot([
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
            'apartment_fit_score' => $propertyFit,
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
                'component_availability' => array_map(fn ($value) => $value !== null, $components),
                'parking_available' => PropertyAmenities::parking($property),
                'mortgage_available' => $this->isResidentialType($property) ? PropertyAmenities::mortgage($property) : null,
            ],
            'interest' => $interest,
            'model_version' => $modelVersion,
            'calculated_at' => $now,
        ]);

        if ($actor) {
            return $snapshot->forceFill([
                'promotion_priority_score' => $promotionPriority,
                'promotion_eligibility' => $promotionEligibility,
            ]);
        }
        $snapshot->save();

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
            return ['status' => 'data_error', 'reasons' => ['Этаж объекта не может быть выше этажности здания.']];
        }

        foreach ([
            'location_id' => 'Не указан город.',
            'district' => 'Не указан район.',
            'price' => 'Не указана цена.',
            'currency' => 'Не указана валюта.',
            'total_area' => 'Не указана общая площадь.',
        ] as $field => $message) {
            if ($property->{$field} === null || $property->{$field} === '' || (in_array($field, ['price', 'total_area'], true) && (float) $property->{$field} <= 0)) {
                $reasons[] = $message;
            }
        }

        if (! $property->type) {
            $reasons[] = 'Не указан тип объекта.';
        }
        if ($this->usesRooms($property) && ($property->rooms === null || $property->rooms === '')) {
            $reasons[] = 'Не указано количество комнат.';
        }

        if ($property->offer_type !== 'sale') {
            $reasons[] = 'Расчет доступен только для объектов на продажу.';
        }
        if ($property->moderation_status !== Property::PUBLIC_MODERATION_STATUS || $property->sold_at !== null) {
            $reasons[] = 'Расчет доступен только для активного опубликованного объекта.';
        }

        return ['status' => $reasons === [] ? 'eligible' : $state, 'reasons' => $reasons];
    }

    private function comparableQuery(Property $property, bool $districtOnly, bool $sold, float $areaTolerance = 0.20, ?User $actor = null): Builder
    {
        $area = (float) $property->total_area;
        $query = Property::query()
            ->whereKeyNot($property->id)
            ->where('offer_type', 'sale')
            ->where('type_id', $property->type_id)
            ->where('location_id', $property->location_id)
            ->where('currency', $property->currency)
            ->whereBetween('total_area', [$area * (1 - $areaTolerance), $area * (1 + $areaTolerance)]);

        if ($actor) app(RopGroupAccess::class)->scope($query, $actor, 'properties.branch_group_id', 'properties.branch_id');

        if ($this->usesRooms($property)) {
            $query->where('rooms', $property->rooms);
        }
        // The legacy generic apartment type stores primary/secondary market in a flag.
        // Current production types already split those markets into separate type IDs.
        if ($property->type?->slug === 'apartment') {
            $query->where('is_from_developer', (bool) $property->is_from_developer);
        }

        if ($districtOnly) {
            if ($property->district_id !== null) {
                $query->where('district_id', $property->district_id);
            } else {
                $query->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower(trim((string) $property->district))]);
            }
        }

        if ($sold) {
            $query->with('statusHistory')->where('moderation_status', 'sold')->where('sold_at', '>=', now()->subMonths((int) config('property-liquidity.lookback_months', 12)));
        } else {
            $query->publicSearchable()->whereNull('sold_at');
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

    private function districtMarketScore(Property $property, Collection $active, Collection $sold, ?User $actor = null): array
    {
        $citySold = $this->comparableQuery($property, false, true, 0.20, $actor)->get();
        $cityActive = $this->comparableQuery($property, false, false, 0.20, $actor)->get()->push($property);
        $cityDom = $this->medianDom($citySold);
        $citySellThrough = $citySold->count() / max(1, $citySold->count() + $cityActive->count());
        $key = static fn (Property $item) => $item->district_id !== null
            ? 'id:'.$item->district_id : 'name:'.mb_strtolower(trim((string) $item->district));
        $soldGroups = $citySold->groupBy($key);
        $activeGroups = $cityActive->groupBy($key);
        $metrics = [];
        foreach ($soldGroups as $district => $sales) {
            $n = $sales->count();
            $dom = $this->medianDom($sales);
            if ($n < (int) config('property-liquidity.district_minimum_sales', 5) || $dom === null || $cityDom === null) continue;
            $rate = $n / max(1, $n + ($activeGroups[$district] ?? collect())->count());
            // Smooth the observations, not the resulting percentile (which creates a score ceiling).
            $metrics[$district] = [
                'dom' => ($n * $dom + 30 * $cityDom) / ($n + 30),
                'rate' => ($n * $rate + 30 * $citySellThrough) / ($n + 30),
            ];
        }
        $current = $metrics[$key($property)] ?? null;
        $available = $current !== null && count($metrics) >= (int) config('property-liquidity.district_minimum_peers', 3);
        $score = $available ? (int) round(
            .60 * LiquidityMath::percentile(-$current['dom'], array_map(fn ($metric) => -$metric['dom'], $metrics))
            + .40 * LiquidityMath::percentile($current['rate'], array_column($metrics, 'rate'))
        ) : 50;

        return ['score' => $score, 'available' => $available,
            'sell_through_rate' => round($sold->count() / max(1, $sold->count() + $active->count() + 1), 4)];
    }

    private function medianDom(Collection $properties): ?int
    {
        $days = $properties->map(function (Property $property): ?int {
            return $this->marketDays->calculate($property);
        })->filter(fn (?int $value) => $value !== null)->values();

        return $days->isEmpty() ? null : (int) round((float) $days->median());
    }

    private function propertyFitScore(Property $property): int
    {
        if ($property->type?->slug === 'parking') {
            return 50;
        }

        $score = 100;
        if ($this->isApartmentType($property)) {
            if ($property->floor !== null && (int) $property->floor === 1) {
                $score -= 10;
            }
            if ($property->floor !== null && $property->total_floors !== null && (int) $property->floor === (int) $property->total_floors) {
                $score -= 7;
            }
            if ($property->floor !== null && (int) $property->floor > 5 && ! $property->features?->contains('slug', 'elevator')) {
                $score -= 15;
            }
        }
        if (! $property->condition && ! $property->repair_type_id) {
            $score -= 8;
        }
        if (PropertyAmenities::parking($property) === false) {
            $score -= 5;
        }
        if ($this->isResidentialType($property) && PropertyAmenities::mortgage($property) === false) {
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

    private function confidence(Property $property, int $soldCount, int $activeCount, bool $fallback, int $unavailableComponents = 0): array
    {
        $required = ['location_id', 'district', 'price', 'currency', 'total_area'];
        if ($this->usesRooms($property)) {
            $required[] = 'rooms';
        }
        if ($this->isApartmentType($property)) {
            $required[] = 'floor';
            $required[] = 'total_floors';
        }
        $complete = collect($required)->filter(fn (string $field) => $property->{$field} !== null && $property->{$field} !== '')->count();
        $completeness = 100 * $complete / count($required);
        $sample = min(100, ($soldCount / 50 * 70) + ($activeCount / 30 * 30));
        $district = $property->district_id ? 100 : 65;
        $score = (int) round($completeness * 0.40 + $sample * 0.45 + $district * 0.15 - ($fallback ? 15 : 0));
        $unknownAmenities = (PropertyAmenities::parking($property) === null ? 1 : 0)
            + ($this->isResidentialType($property) && PropertyAmenities::mortgage($property) === null ? 1 : 0);
        $score = $this->clamp($score - 10 * $unavailableComponents - 5 * $unknownAmenities);
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
        $lastPublished = array_key_exists('last_social_publication', $property->getAttributes())
            ? $property->last_social_publication
            : $property->socialPromotions()->where('status', 'published')->max('published_at');
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
            $factors[] = ['code' => 'apartment_fit', 'impact' => 'negative', 'text' => 'Характеристики объекта менее востребованы в этом сегменте'];
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

    private function isApartmentType(Property $property): bool
    {
        return in_array($property->type?->slug, self::APARTMENT_TYPE_SLUGS, true);
    }

    private function isResidentialType(Property $property): bool
    {
        return in_array($property->type?->slug, self::RESIDENTIAL_TYPE_SLUGS, true);
    }

    private function usesRooms(Property $property): bool
    {
        return $this->isResidentialType($property);
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
