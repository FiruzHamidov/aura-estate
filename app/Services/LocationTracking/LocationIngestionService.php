<?php

namespace App\Services\LocationTracking;

use App\Events\UserLocationUpdated;
use App\Models\User;
use App\Models\UserCurrentLocation;
use App\Models\UserLocationDevice;
use App\Models\UserLocationPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LocationIngestionService
{
    public function __construct(
        private readonly LocationAccessService $access,
        private readonly LocationTrackingPolicyService $policies,
    ) {}

    public function ingest(User $user, string $deviceUuid, array $points): array
    {
        $this->access->assertCanTransmit($user);
        $settings = $this->policies->settingsFor($user);

        if (! $settings->tracking_enabled || $settings->mode === 'off') {
            $this->access->deny('LOCATION_TRACKING_DISABLED', 'Отслеживание местоположения выключено.');
        }

        $device = UserLocationDevice::query()
            ->where('user_id', $user->id)
            ->where('device_uuid', $deviceUuid)
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            $this->access->deny('LOCATION_DEVICE_NOT_REGISTERED', 'Устройство не зарегистрировано.', 409);
        }

        $result = ['accepted' => [], 'duplicates' => [], 'rejected' => []];
        $broadcastPoint = null;

        foreach ($points as $point) {
            $eventId = (string) ($point['event_id'] ?? '');
            $capturedAtUtc = CarbonImmutable::parse($point['captured_at'])->utc();

            if ($capturedAtUtc->isFuture() && $capturedAtUtc->diffInMinutes(CarbonImmutable::now()) > 5) {
                $result['rejected'][] = ['event_id' => $eventId, 'code' => 'LOCATION_INVALID_POINT', 'message' => 'Время точки находится в будущем.'];

                continue;
            }

            if ($capturedAtUtc->lt(CarbonImmutable::now()->subHours((int) config('location_tracking.offline_window_hours', 72)))) {
                $result['rejected'][] = ['event_id' => $eventId, 'code' => 'LOCATION_POINT_TOO_OLD', 'message' => 'Точка старше допустимого офлайн-периода.'];

                continue;
            }

            if (! $this->policies->isTrackingAllowedAt($user, $capturedAtUtc)) {
                $result['rejected'][] = ['event_id' => $eventId, 'code' => 'LOCATION_OUTSIDE_SCHEDULE', 'message' => 'Точка получена вне рабочего расписания.'];

                continue;
            }

            $existing = UserLocationPoint::query()
                ->where('device_id', $device->id)
                ->where('event_id', $eventId)
                ->first();

            if ($existing) {
                $result['duplicates'][] = ['event_id' => $eventId, 'point_id' => $existing->id];

                continue;
            }

            $capturedAt = $capturedAtUtc->setTimezone(config('app.timezone'));
            try {
                $saved = DB::transaction(function () use ($user, $device, $point, $capturedAt) {
                    $quality = $this->quality($user, $point, $capturedAt);
                    $receivedAt = now();
                    $stored = UserLocationPoint::query()->create([
                        ...$point,
                        'user_id' => $user->id,
                        'device_id' => $device->id,
                        'branch_id' => $user->branch_id,
                        'branch_group_id' => $user->branch_group_id,
                        'quality' => $quality,
                        'captured_at' => $capturedAt,
                        'received_at' => $receivedAt,
                    ]);

                    $current = UserCurrentLocation::query()->lockForUpdate()->find($user->id);
                    $updated = ! $current
                        || $capturedAt->gt($current->captured_at)
                        || ($capturedAt->equalTo($current->captured_at) && $receivedAt->gt($current->received_at));

                    if ($updated) {
                        UserCurrentLocation::query()->updateOrCreate(
                            ['user_id' => $user->id],
                            [
                                'location_point_id' => $stored->id,
                                'latitude' => $stored->latitude,
                                'longitude' => $stored->longitude,
                                'accuracy_m' => $stored->accuracy_m,
                                'quality' => $quality,
                                'captured_at' => $capturedAt,
                                'received_at' => $receivedAt,
                            ]
                        );
                    }

                    return [$stored, $updated, $quality];
                });
            } catch (QueryException $exception) {
                $duplicate = UserLocationPoint::query()
                    ->where('device_id', $device->id)
                    ->where('event_id', $eventId)
                    ->first();

                if (! $duplicate) {
                    throw $exception;
                }

                $result['duplicates'][] = ['event_id' => $eventId, 'point_id' => $duplicate->id];

                continue;
            }

            [$stored, $currentUpdated, $quality] = $saved;
            if ($currentUpdated) {
                $broadcastPoint = $stored;
            }
            $result['accepted'][] = [
                'event_id' => $eventId,
                'point_id' => $stored->id,
                'quality' => $quality,
                'current_location_updated' => $currentUpdated,
            ];
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        if ($broadcastPoint && config('location_tracking.realtime_broadcast_enabled', false)) {
            try {
                UserLocationUpdated::dispatch([
                    'user_id' => (int) $broadcastPoint->user_id,
                    'point_id' => (int) $broadcastPoint->id,
                    'latitude' => (float) $broadcastPoint->latitude,
                    'longitude' => (float) $broadcastPoint->longitude,
                    'accuracy_m' => (float) $broadcastPoint->accuracy_m,
                    'quality' => $broadcastPoint->quality,
                    'captured_at' => $broadcastPoint->captured_at->toISOString(),
                    'received_at' => $broadcastPoint->received_at->toISOString(),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }

    private function quality(User $user, array $point, CarbonImmutable $capturedAt): string
    {
        if (($point['is_mocked'] ?? false) === true) {
            return 'suspect';
        }

        $previous = UserLocationPoint::query()
            ->where('user_id', $user->id)
            ->where('captured_at', '<', $capturedAt)
            ->latest('captured_at')
            ->first();

        if ($previous) {
            $seconds = max(1, $previous->captured_at->diffInSeconds($capturedAt));
            $speedKmh = ($this->distanceMeters(
                (float) $previous->latitude,
                (float) $previous->longitude,
                (float) $point['latitude'],
                (float) $point['longitude']
            ) / $seconds) * 3.6;

            if ($speedKmh > (float) config('location_tracking.suspect_speed_kmh', 250)) {
                return 'suspect';
            }
        }

        $accuracy = (float) $point['accuracy_m'];

        return $accuracy <= 50 ? 'good' : ($accuracy <= 100 ? 'medium' : 'low');
    }

    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
