<?php

namespace Tests\Unit;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingNearbyPlace;
use App\Models\NewBuildingVideo;
use App\Models\PaymentProgram;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VerificationDateTest extends TestCase
{
    public function test_verification_dates_keep_the_same_instant_across_repeated_json_round_trips(): void
    {
        config(['app.timezone' => 'Asia/Dushanbe']);
        foreach ([NewBuilding::class, DeveloperUnit::class, PaymentProgram::class, NewBuildingNearbyPlace::class, NewBuildingVideo::class] as $modelClass) {
            foreach (['2026-08-28T10:16:00Z', '2026-08-28T15:16:00+05:00', Carbon::parse('2026-08-28T10:16:00Z'), '2026-08-28 15:16:00'] as $input) {
                $model = new $modelClass(['data_verified_at' => $input]);
                for ($round = 0; $round < 3; $round++) {
                    $this->assertSame('2026-08-28 15:16:00', $model->getAttributes()['data_verified_at']);
                    $this->assertSame('2026-08-28T10:16:00.000000Z', $model->toArray()['data_verified_at']);
                    $model = new $modelClass(['data_verified_at' => $model->toArray()['data_verified_at']]);
                }
            }
            foreach ([null, ''] as $empty) {
                $this->assertNull((new $modelClass(['data_verified_at' => $empty]))->data_verified_at);
            }
        }
    }
}
