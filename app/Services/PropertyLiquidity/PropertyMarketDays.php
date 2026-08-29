<?php

namespace App\Services\PropertyLiquidity;

use App\Models\Property;

class PropertyMarketDays
{
    public function calculate(Property $property): ?int
    {
        $from = $property->listed_at ?? $property->created_at;
        $to = $property->sold_at;
        if (! $from || ! $to) {
            return null;
        }

        $history = $property->relationLoaded('statusHistory')
            ? $property->statusHistory
            : $property->statusHistory()->get();
        if ($history->isEmpty()) {
            return max(0, (int) floor($from->diffInDays($to)));
        }

        $activeDays = 0.0;
        $cursor = $from->copy();
        $active = true;

        foreach ($history as $event) {
            $changedAt = $event->changed_at;
            if (! $changedAt) {
                continue;
            }
            if ($changedAt->lte($from)) {
                $active = $event->to_status === Property::PUBLIC_MODERATION_STATUS;

                continue;
            }
            if ($changedAt->gte($to)) {
                continue;
            }
            if ($active) {
                $activeDays += $cursor->diffInDays($changedAt);
            }
            $cursor = $changedAt->copy();
            $active = $event->to_status === Property::PUBLIC_MODERATION_STATUS;
        }

        if ($active) {
            $activeDays += $cursor->diffInDays($to);
        }

        return max(0, (int) floor($activeDays));
    }
}
