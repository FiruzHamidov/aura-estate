<?php

namespace App\Services\Attendance;

use App\Contracts\AttendanceDeviceProtocol;
use Carbon\CarbonImmutable;

final class ZktecoTaPushProtocol implements AttendanceDeviceProtocol
{
    public function parse(string $payload, string $timezone): array
    {
        $events = [];
        $rejected = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($payload, "\xEF\xBB\xBF\x00\x09\x0A\x0D ")) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim($line, "\x00\x09 ");
            if ($line === '') {
                continue;
            }

            try {
                $events[] = $this->parseLine($line, $timezone);
            } catch (\InvalidArgumentException $exception) {
                $rejected[] = [
                    'line' => $index + 1,
                    'raw' => mb_substr($line, 0, 500),
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return ['events' => $events, 'rejected' => $rejected];
    }

    /** @return array<string, mixed> */
    private function parseLine(string $line, string $timezone): array
    {
        $tabSeparated = str_contains($line, "\t");
        $parts = $tabSeparated
            ? array_map('trim', explode("\t", $line))
            : (preg_split('/\s+/', $line) ?: []);
        $minimumFields = $tabSeparated ? 2 : 3;
        if (count($parts) < $minimumFields) {
            throw new \InvalidArgumentException('ATTLOG line has too few fields.');
        }

        $deviceUserId = (string) array_shift($parts);
        if ($deviceUserId === '' || mb_strlen($deviceUserId) > 100) {
            throw new \InvalidArgumentException('Invalid device user ID.');
        }

        $dateTime = $tabSeparated
            ? (string) array_shift($parts)
            : ((string) array_shift($parts)).' '.((string) array_shift($parts));
        $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime, $timezone);
        $errors = CarbonImmutable::getLastErrors();
        if ($occurredAt === false
            || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $occurredAt->format('Y-m-d H:i:s') !== $dateTime) {
            throw new \InvalidArgumentException('Invalid ATTLOG timestamp.');
        }

        return [
            'device_user_id' => $deviceUserId,
            'occurred_at_local' => $occurredAt,
            'attendance_status' => $this->nullableField($parts[0] ?? null),
            'verify_mode' => $this->nullableField($parts[1] ?? null),
            'work_code' => $this->nullableField($parts[2] ?? null),
            'reserved' => array_values(array_slice($parts, 3)),
            'raw_line' => $line,
        ];
    }

    private function nullableField(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
