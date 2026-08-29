<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Optional ordinary-listing fixture for checking the boundary with residential inventory. */
final class ResidentialOrdinaryListings
{
    public static function createSchema(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || Schema::hasTable('properties')) {
            throw new \LogicException('Create ordinary-listing fixtures only in a new in-memory QA schema.');
        }
        foreach ([
            '2025_06_23_004211_create_property_types_table.php',
            '2025_06_23_004242_create_property_statuses_table.php',
            '2025_06_23_171714_create_building_types_table.php',
            '2025_06_23_171714_create_heating_types_table.php',
            '2025_06_23_171714_create_parking_types_table.php',
            '2025_06_24_051518_create_repair_types_table.php',
            '2025_06_24_066251_create_properties_table.php',
            '2025_06_24_074258_create_property_photos_table.php',
            '2025_07_01_094130_add_owner_phone_to_properties_table.php',
            '2025_08_01_114130_add_district_to_properties_table.php',
            '2025_08_01_134818_add_listing_type_to_properties_table.php',
            '2025_08_20_061901_add_position_to_property_photos_table.php',
            '2025_09_01_044009_create_contract_types_table.php',
            '2025_09_01_044038_add_contract_type_id_to_properties_table.php',
            '2025_12_10_123213_add_heating_and_parking_types_to_properties_table.php',
            '2026_02_28_120000_add_discount_price_to_properties_table.php',
            '2026_03_03_140000_add_construction_fields_to_properties_table.php',
            '2026_07_27_130000_create_tags_and_property_tag_tables.php',
            '2026_07_27_150000_create_document_types_and_add_to_properties.php',
            '2026_08_08_120000_add_effective_price_index_to_properties_table.php',
            '2026_08_14_120000_add_listing_updated_at_to_properties_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public static function seed(int $creatorId, int $locationId): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || DB::table('properties')->exists()) {
            throw new \LogicException('Seed ordinary listings only in an empty in-memory QA schema.');
        }
        DB::table('property_types')->insert([
            ['id' => 2, 'name' => 'Новостройки', 'slug' => 'novostroyki'],
            ['id' => 3, 'name' => 'Вторичка', 'slug' => 'vtorichka'],
        ]);
        DB::table('property_statuses')->insert(['id' => 1, 'name' => 'Активно', 'slug' => 'active']);
        DB::table('building_types')->insert(['id' => 1, 'name' => 'Монолит']);
        foreach ([2 => 'Новостройки', 3 => 'Вторичка'] as $typeId => $label) {
            DB::table('properties')->insert([
                'title' => 'QA обычное объявление — '.$label,
                'description' => 'Синтетическое обычное объявление для проверки разделения с ЖК. Не реальное предложение.',
                'type_id' => $typeId, 'status_id' => 1, 'location_id' => $locationId,
                'created_by' => $creatorId, 'agent_id' => $creatorId,
                'price' => $typeId === 2 ? 735000 : 620000, 'currency' => 'TJS',
                'offer_type' => 'sale', 'listing_type' => 'regular', 'moderation_status' => 'approved',
                'rooms' => 2, 'total_area' => 70, 'floor' => 4, 'total_floors' => 10,
                'created_at' => now(), 'updated_at' => now(), 'listing_updated_at' => now(),
            ]);
        }
    }
}
