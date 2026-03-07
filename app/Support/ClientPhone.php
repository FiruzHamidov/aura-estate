<?php

namespace App\Support;

class ClientPhone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (!str_starts_with($digits, '992') && strlen($digits) === 9) {
            $digits = '992' . $digits;
        }

        return $digits;
    }
}
