<?php

namespace App\Services\Residential;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class UnitPrice
{
    /** Decimal arithmetic throughout; never calculate monetary values using binary floats. */
    public function calculate(array $data): array
    {
        if ($data['price_on_request'] ?? false) {
            return ['total_price' => null, 'price_per_sqm' => null, 'currency' => 'TJS'];
        }
        $basis = $data['pricing_basis'] ?? 'total';
        $field = $basis === 'per_sqm' ? 'price_per_sqm' : 'total_price';
        if (! isset($data[$field]) || BigDecimal::of((string) $data[$field])->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([$field => 'Укажите положительную цену или «По запросу».']);
        }
        $area = BigDecimal::of((string) ($data['area'] ?? 0));
        if ($area->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['area' => 'Площадь должна быть больше нуля.']);
        }
        $price = BigDecimal::of((string) $data[$field])->toScale(2, RoundingMode::HALF_UP);
        $total = $basis === 'total' ? $price : $price->multipliedBy($area)->toScale(2, RoundingMode::HALF_UP);
        $perSqm = $basis === 'per_sqm' ? $price : $price->dividedBy($area, 2, RoundingMode::HALF_UP);
        if ($total->isGreaterThan('9999999999999.99') || $perSqm->isGreaterThan('9999999999999.99')) {
            throw ValidationException::withMessages([$field => 'Цена превышает допустимое значение.']);
        }

        return ['total_price' => (string) $total, 'price_per_sqm' => (string) $perSqm, 'currency' => 'TJS'];
    }
}
