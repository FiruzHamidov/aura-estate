<?php

namespace App\Support;

use App\Models\Setting;

class Cursor
{
    public static function get(string $key, ?string $default = null): ?string
    {
        return optional(Setting::find($key))->value ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
