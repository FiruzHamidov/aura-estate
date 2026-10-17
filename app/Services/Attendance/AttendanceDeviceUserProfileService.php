<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUserProfile;

final class AttendanceDeviceUserProfileService
{
    public function ingest(AttendanceDevice $device, string $payload, string $table): int
    {
        $profiles = [];
        $accepted = 0;
        foreach (preg_split('/\r\n|\r|\n/', $payload) ?: [] as $line) {
            $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line);
            if (preg_match('/^(?:USER|USERINFO)[ \t]+(.*)$/', $line, $match)) {
                $line = $match[1];
            } elseif ($table !== 'USERINFO' || ! str_starts_with($line, 'PIN=')) {
                continue;
            }

            // TA PUSH fields are tab-separated; spaces within names are significant.
            $fields = [];
            foreach (explode("\t", $line) as $field) {
                [$key, $value] = array_pad(explode('=', $field, 2), 2, null);
                if (in_array($key, ['PIN', 'Name'], true) && $value !== null) {
                    $fields[$key] = trim($value);
                }
            }
            $pin = $fields['PIN'] ?? '';
            $name = $fields['Name'] ?? null;
            if ($pin === '' || $name === null
                || ! mb_check_encoding($pin.$name, 'UTF-8')
                || mb_strlen($pin) > 100 || mb_strlen($name) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $pin.$name)) {
                continue;
            }

            // Never retain passwords, cards, templates or the original OPERLOG body.
            $profiles[$pin] = [
                'device_id' => $device->id,
                'device_user_id' => $pin,
                'name' => $name === '' ? null : $name,
            ];
            $accepted++;
        }

        foreach (array_chunk(array_values($profiles), 200) as $chunk) {
            AttendanceDeviceUserProfile::query()->upsert($chunk, ['device_id', 'device_user_id'], ['name', 'updated_at']);
        }

        return $accepted;
    }

    public function nextQuery(AttendanceDevice $device): ?string
    {
        // Backfill existing terminal users on the first poll, then refresh daily.
        // The conditional update also prevents duplicate commands from concurrent polls.
        $requestedAt = now();
        $claimed = AttendanceDevice::query()->whereKey($device->id)
            ->where(function ($query) use ($requestedAt) {
                $query->whereNull('user_info_requested_at')
                    ->orWhere('user_info_requested_at', '<=', $requestedAt->copy()->subDay());
            })
            ->update(['user_info_requested_at' => $requestedAt]);

        return $claimed ? 'C:'.$requestedAt->timestamp.":DATA QUERY USERINFO\n" : null;
    }
}
