<?php

namespace Tests\Support;

use App\Models\Location;
use App\Models\NewBuilding;
use Illuminate\Support\Facades\DB;

final class ResidentialLoadFixture
{
    public static function seed(?string $mysqlSandbox = null): void
    {
        if ($mysqlSandbox !== null) {
            ResidentialSchema::assertIsolatedMysql($mysqlSandbox);
        } elseif (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Load fixture requires a new isolated SQLite :memory: schema.');
        }
        if (NewBuilding::exists()) {
            throw new \LogicException('Load fixture requires an empty inventory.');
        }
        $location = Location::create(['city' => 'QA load city']);
        DB::transaction(function () use ($location) {
            for ($b = 1; $b <= 100; $b++) {
                $building = NewBuilding::create(['title' => 'QA load complex '.$b, 'publication_status' => 'published', 'moderation_status' => 'approved', 'location_id' => $location->id, 'address' => 'Synthetic load address '.$b, 'latitude' => 38.5 + $b / 10000, 'longitude' => 68.7 + $b / 10000, 'published_at' => now(), 'data_verified_at' => now()]);
                $block = $building->blocks()->create(['name' => 'QA block', 'floors_from' => 1, 'floors_to' => 100]);
                $entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => 'QA entrance', 'residential_floor_from' => 1, 'residential_floor_to' => 100, 'technical_floors' => []]);
                // 1000 + 90*91 + 9*90 = exactly 10,000 unique lots.
                $count = $b === 1 ? 1000 : ($b <= 91 ? 91 : 90);
                $rows = [];
                for ($u = 1; $u <= $count; $u++) {
                    $availability = $u % 10 === 0 ? 'sold' : ($u % 10 === 1 ? 'reserved' : 'available');
                    $area = 40 + $u % 80;
                    $rows[] = ['new_building_id' => $building->id, 'block_id' => $block->id, 'entrance_id' => $entrance->id, 'name' => 'QA lot '.$u, 'number' => (string) $u, 'external_id' => 'qa-'.$u, 'rooms' => $u % 5, 'bedrooms' => $u % 5, 'area' => $area, 'floor' => (int) ceil($u / 10), 'position_on_floor' => ($u - 1) % 10 + 1, 'total_price' => $area * 10000, 'price_per_sqm' => 10000, 'pricing_basis' => 'total', 'publication_status' => 'published', 'availability_status' => $availability, 'is_available' => $availability === 'available', 'moderation_status' => $availability, 'created_at' => now(), 'updated_at' => now()];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('developer_units')->insert($chunk);
                }
            }
        });
    }
}
