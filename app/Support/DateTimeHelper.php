<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class DateTimeHelper
{
    public static function formatDateTimeForApi(mixed $value, ?string $sourceTimezone = 'UTC', ?string $targetTimezone = null): ?string
    {
        if (empty($value)) {
            return null;
        }

        $toTimezone = $targetTimezone ?: (string) config('app.timezone', 'Asia/Dushanbe');
        $fromTimezone = $sourceTimezone ?: 'UTC';

        return Carbon::parse($value, $fromTimezone)
            ->setTimezone($toTimezone)
            ->toIso8601String();
    }
}
