<?php

namespace App\Services\LocationTracking;

use App\Models\User;
use App\Models\UserLocationTrackingSetting;
use Carbon\CarbonImmutable;

class LocationTrackingPolicyService
{
    public function isEligible(User $user): bool
    {
        $user->loadMissing('role');

        return in_array($user->role?->slug, config('location_tracking.tracked_roles', []), true);
    }

    public function settingsFor(User $user): UserLocationTrackingSetting
    {
        $stored = $user->relationLoaded('locationTrackingSetting')
            ? $user->getRelation('locationTrackingSetting')
            : $user->locationTrackingSetting()->first();

        if ($stored) {
            return $stored;
        }

        return new UserLocationTrackingSetting([
            'user_id' => $user->id,
            'tracking_enabled' => (bool) config('location_tracking.default_enabled', true),
            'mode' => config('location_tracking.default_mode', 'work_schedule'),
            'timezone' => config('location_tracking.default_timezone', 'Asia/Dushanbe'),
            'schedule' => config('location_tracking.default_schedule', []),
            'foreground_interval_sec' => config('location_tracking.foreground_interval_sec', 30),
            'background_interval_sec' => config('location_tracking.background_interval_sec', 120),
            'min_distance_m' => config('location_tracking.min_distance_m', 75),
            'history_retention_days' => config('location_tracking.history_retention_days', 90),
            'require_background_permission' => true,
            'policy_version' => 1,
        ]);
    }

    public function isTrackingAllowedAt(User $user, CarbonImmutable $moment): bool
    {
        if (! $this->isEligible($user)) {
            return false;
        }

        $settings = $this->settingsFor($user);

        if (! $settings->tracking_enabled || $settings->mode === 'off') {
            return false;
        }

        if ($settings->mode === 'always') {
            return true;
        }

        $local = $moment->setTimezone($settings->timezone);
        $intervals = ($settings->schedule ?: config('location_tracking.default_schedule', []))[(string) $local->dayOfWeekIso] ?? [];

        foreach ($intervals as $interval) {
            if (! is_array($interval) || count($interval) !== 2) {
                continue;
            }

            [$start, $end] = $interval;
            $startAt = CarbonImmutable::parse($local->format('Y-m-d').' '.$start, $settings->timezone);
            $endAt = CarbonImmutable::parse($local->format('Y-m-d').' '.$end, $settings->timezone);

            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt = $endAt->addDay();
            }

            if ($local->betweenIncluded($startAt, $endAt)) {
                return true;
            }
        }

        return false;
    }

    public function payload(User $user): array
    {
        $eligible = $this->isEligible($user);
        $settings = $this->settingsFor($user);
        $enabled = $eligible && (bool) $settings->tracking_enabled && $settings->mode !== 'off';

        return [
            'eligible_for_location_permission' => $eligible,
            'should_request_location_permission' => $enabled,
            'tracking_enabled' => $enabled,
            'mode' => $eligible ? $settings->mode : 'off',
            'timezone' => $settings->timezone,
            'schedule' => $eligible ? $settings->schedule : [],
            'should_track_now' => $enabled && $this->isTrackingAllowedAt($user, CarbonImmutable::now()),
            'foreground_interval_sec' => (int) $settings->foreground_interval_sec,
            'background_interval_sec' => (int) $settings->background_interval_sec,
            'min_distance_m' => (int) $settings->min_distance_m,
            'require_background_permission' => $enabled && (bool) $settings->require_background_permission,
            'policy_version' => (int) $settings->policy_version,
            'tracked_roles' => config('location_tracking.tracked_roles', ['agent', 'mop']),
        ];
    }
}
