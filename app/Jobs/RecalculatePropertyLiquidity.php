<?php

namespace App\Jobs;

use App\Models\Property;
use App\Services\PropertyLiquidity\PropertyLiquidityCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculatePropertyLiquidity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $propertyId) {}

    public function handle(PropertyLiquidityCalculator $calculator): void
    {
        $property = Property::query()->find($this->propertyId);
        if ($property) {
            $calculator->calculate($property);
        }
    }
}
