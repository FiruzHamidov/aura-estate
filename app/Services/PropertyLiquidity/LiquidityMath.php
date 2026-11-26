<?php

namespace App\Services\PropertyLiquidity;

final class LiquidityMath
{
    /** Equal values share a rank; the best and worst distinct values reach 100 and 0. */
    public static function percentile(float $value, array $values): int
    {
        $values = array_map(fn ($item) => round((float) $item, 6), $values);
        if (count($values) < 2 || min($values) === max($values)) return 50;
        $rank = static function (float $target) use ($values): float {
            $less = count(array_filter($values, fn ($item) => $item < $target));
            $equal = count(array_filter($values, fn ($item) => $item === $target));
            return $less + ($equal - 1) / 2;
        };
        $low = $rank(min($values));
        $high = $rank(max($values));

        return max(0, min(100, (int) round(100 * ($rank(round($value, 6)) - $low) / ($high - $low))));
    }

    public static function weightedScore(array $components, array $weights): int
    {
        $available = array_filter($components, fn ($value) => $value !== null);
        $totalWeight = array_sum(array_intersect_key($weights, $available));
        if ($totalWeight <= 0) return 0;
        $sum = 0;
        foreach ($available as $key => $value) $sum += $value * $weights[$key];

        return max(0, min(100, (int) round($sum / $totalWeight)));
    }
}
