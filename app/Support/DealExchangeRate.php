<?php

namespace App\Support;

/** Broad input sanity bounds for TJS per 1 USD, not a market-rate quote. */
final class DealExchangeRate
{
    public const MIN = 1;

    public const MAX = 100;

    public static function isValid(mixed $rate): bool
    {
        return is_numeric($rate) && is_finite((float) $rate)
            && (float) $rate >= self::MIN && (float) $rate <= self::MAX;
    }
}
