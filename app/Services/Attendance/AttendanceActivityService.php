<?php

namespace App\Services\Attendance;

use Illuminate\Support\Collection;

final class AttendanceActivityService
{
    /** Last known location from this day's visible, non-duplicate terminal marks. */
    public function summarize(Collection $events): array
    {
        $activity = [
            'state' => 'unknown',
            'since' => null,
            'last_event_at' => null,
            'last_event_type' => null,
            'showing_count' => 0,
            'property_count' => 0,
        ];

        foreach ($events->sortBy([['occurred_at', 'asc'], ['id', 'asc']]) as $event) {
            if ($event->is_duplicate || $event->occurred_at === null || $event->occurred_at->isFuture()) {
                continue;
            }
            $state = match ($event->event_type) {
                'check_in', 'break_in', 'showing_in', 'property_in' => 'office',
                'check_out' => 'left',
                'break_out' => 'break',
                'showing_out' => 'showing',
                'property_out' => 'property',
                default => 'unknown',
            };
            if ($state !== $activity['state'] || $activity['since'] === null) {
                $activity['since'] = $event->occurred_at->toISOString();
                if ($state === 'showing') $activity['showing_count']++;
                if ($state === 'property') $activity['property_count']++;
            }
            $activity['state'] = $state;
            $activity['last_event_at'] = $event->occurred_at->toISOString();
            $activity['last_event_type'] = $event->event_type;
        }

        return $activity;
    }
}
