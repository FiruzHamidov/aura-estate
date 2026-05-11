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

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '992')) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '992' . substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '992' . $digits;
        }

        return $digits;
    }
}
