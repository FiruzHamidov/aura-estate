<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class AttendanceEventClassifier
{
    public function classify(AttendanceDevice $device, ?string $code, CarbonInterface $occurredAt): string
    {
        // Select by event time, not upload time: an offline device can send old marks.
        $startedAt = config('attendance.fieldwork_started_at');
        $serials = config('attendance.fieldwork_device_serials', []);
        if ($startedAt && ($serials === [] || in_array($device->serial_number, $serials, true))
            && $occurredAt->greaterThanOrEqualTo(CarbonImmutable::parse($startedAt, config('attendance.timezone')))) {
            return match ($code) {
                '0' => 'check_in',
                '1' => 'check_out',
                '2' => 'showing_out',
                '3' => 'showing_in',
                '4' => 'property_out',
                '5' => 'property_in',
                default => 'punch',
            };
        }

        return (string) (config('attendance.status_map.'.(string) $code) ?? 'punch');
    }

    public function direction(string $eventType): ?string
    {
        return match ($eventType) {
            'check_in', 'break_in', 'showing_in', 'property_in' => 'in',
            'check_out', 'break_out', 'showing_out', 'property_out' => 'out',
            default => null,
        };
    }
}
