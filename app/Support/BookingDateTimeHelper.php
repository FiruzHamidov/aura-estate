<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class BookingDateTimeHelper
{
    public static function formatBookingDateTime(mixed $value, ?string $timezone = null): ?string
    {
        if (empty($value)) {
            return null;
        }

        $targetTimezone = $timezone ?: (string) config('app.timezone', 'Asia/Dushanbe');

        return Carbon::parse($value, 'UTC')
            ->setTimezone($targetTimezone)
            ->toIso8601String();
    }
}
