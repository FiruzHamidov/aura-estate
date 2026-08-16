<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

final class DateOnly implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $rawValue = $attributes[$key] ?? null;

        return $rawValue === null ? null : CarbonImmutable::parse((string) $rawValue)->toDateString();
    }
}
