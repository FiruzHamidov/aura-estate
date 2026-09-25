<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class InternationalPhone
{
    /** Preserve the existing account identifier convention used by login and OTP. */
    public static function accountValue(string $raw): string
    {
        $phone = self::e164($raw);
        if ($phone === null) {
            return $raw;
        }

        return str_starts_with($phone, '+992') ? substr($phone, 4) : substr($phone, 1);
    }

    /** Validate national length, without rejecting newly allocated operator prefixes. */
    public static function e164(?string $raw): ?string
    {
        $raw = trim($raw ?? '');
        if ($raw === '' || ! preg_match('/^\+?[\d\s().-]+$/D', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+')) {
            $input = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $input = '+'.substr($digits, 2);
        } elseif ((strlen($digits) === 12 && str_starts_with($digits, '992'))
            || (strlen($digits) === 11 && str_starts_with($digits, '7'))) {
            $input = '+'.$digits;
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $input = '+992'.substr($digits, 1);
        } else {
            // Existing integrations can still send a local Tajik number.
            $input = $digits;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $phone = $util->parse($input, 'TJ');

            return $util->isPossibleNumber($phone)
                ? $util->format($phone, PhoneNumberFormat::E164)
                : null;
        } catch (NumberParseException) {
            return null;
        }
    }
}
