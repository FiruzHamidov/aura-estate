<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserCurrentLocation;
use App\Models\UserLocationDevice;
use App\Models\UserLocationPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DushanbeUserLocationSeeder extends Seeder
{
    private const USER_ID = 1;

    private const DEVICE_UUID = '00000000-0000-4000-8000-000000000001';

    public function run(): void
    {
        $user = User::query()->with('role')->find(self::USER_ID);

        if (! $user) {
            throw new RuntimeException('DushanbeUserLocationSeeder requires users.id=1.');
        }

        $device = UserLocationDevice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'device_uuid' => self::DEVICE_UUID,
            ],
            [
                'platform' => 'android',
                'app_version' => 'demo-seeder',
                'os_version' => 'demo',
                'permission_status' => 'always',
                'background_permission' => true,
                'last_seen_at' => now(),
                'last_policy_version' => 1,
                'revoked_at' => null,
            ]
        );

        $waypoints = [
            ['label' => 'Старт — север проспекта Рудаки', 'lat' => 38.5852000, 'lng' => 68.7861000],
            ['label' => 'Проспект Рудаки — точка 1', 'lat' => 38.5829000, 'lng' => 68.7863000],
            ['label' => 'Проспект Рудаки — точка 2', 'lat' => 38.5806000, 'lng' => 68.7865000],
            ['label' => 'Район парка Рудаки', 'lat' => 38.5783000, 'lng' => 68.7866000],
            ['label' => 'Центральная часть Душанбе', 'lat' => 38.5759000, 'lng' => 68.7868000],
            ['label' => 'Район площади Дусти', 'lat' => 38.5735000, 'lng' => 68.7869000],
            ['label' => 'Проспект Рудаки — точка 3', 'lat' => 38.5709000, 'lng' => 68.7871000],
            ['label' => 'Проспект Рудаки — точка 4', 'lat' => 38.5682000, 'lng' => 68.7873000],
            ['label' => 'Район театра оперы и балета', 'lat' => 38.5654000, 'lng' => 68.7877000],
            ['label' => 'Поворот на восток', 'lat' => 38.5629000, 'lng' => 68.7889000],
            ['label' => 'Улица Айни — точка 1', 'lat' => 38.5608000, 'lng' => 68.7904000],
            ['label' => 'Улица Айни — точка 2', 'lat' => 38.5589000, 'lng' => 68.7921000],
            ['label' => 'Улица Айни — точка 3', 'lat' => 38.5573000, 'lng' => 68.7940000],
            ['label' => 'Финиш — юго-восток центра', 'lat' => 38.5559000, 'lng' => 68.7961000],
        ];

        $lastCapturedAt = CarbonImmutable::now(config('app.timezone'))->subMinute();
        $firstCapturedAt = $lastCapturedAt->subMinutes((count($waypoints) - 1) * 3);
        $latestPoint = null;

        DB::transaction(function () use ($user, $device, $waypoints, $firstCapturedAt, &$latestPoint) {
            foreach ($waypoints as $index => $waypoint) {
                $capturedAt = $firstCapturedAt->addMinutes($index * 3);
                $eventId = sprintf('10000000-0000-4000-8000-%012d', $index + 1);

                $latestPoint = UserLocationPoint::query()->updateOrCreate(
                    [
                        'device_id' => $device->id,
                        'event_id' => $eventId,
                    ],
                    [
                        'user_id' => $user->id,
                        'branch_id' => $user->branch_id,
                        'branch_group_id' => $user->branch_group_id,
                        'latitude' => $waypoint['lat'],
                        'longitude' => $waypoint['lng'],
                        'accuracy_m' => 8 + ($index % 4) * 3,
                        'altitude_m' => 790 + $index,
                        'speed_mps' => $index === 0 ? 0 : 1.25,
                        'heading_deg' => $index < 9 ? 178 : 132,
                        'source' => 'gps',
                        'app_state' => 'background',
                        'battery_percent' => 92 - $index,
                        'is_mocked' => false,
                        'quality' => 'good',
                        'meta' => [
                            'demo' => true,
                            'sequence' => $index + 1,
                            'label' => $waypoint['label'],
                            'city' => 'Душанбе',
                        ],
                        'captured_at' => $capturedAt,
                        'received_at' => $capturedAt->addSeconds(2),
                    ]
                );
            }

            UserCurrentLocation::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'location_point_id' => $latestPoint->id,
                    'latitude' => $latestPoint->latitude,
                    'longitude' => $latestPoint->longitude,
                    'accuracy_m' => $latestPoint->accuracy_m,
                    'quality' => $latestPoint->quality,
                    'captured_at' => $latestPoint->captured_at,
                    'received_at' => $latestPoint->received_at,
                ]
            );
        });

        $this->command?->info(sprintf(
            'Seeded %d Dushanbe location points for user ID %d (%s).',
            count($waypoints),
            $user->id,
            $user->role?->slug ?? 'no-role'
        ));
    }
}
