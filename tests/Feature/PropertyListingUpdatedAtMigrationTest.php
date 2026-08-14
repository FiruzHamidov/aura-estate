<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyListingUpdatedAtMigrationTest extends TestCase
{
    public function test_migration_backfills_listing_update_date_without_changing_creation_date(): void
    {
        Schema::dropAllTables();
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        DB::table('properties')->insert([
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-08-01 12:00:00',
        ]);

        $migration = require database_path('migrations/2026_08_14_120000_add_listing_updated_at_to_properties_table.php');
        $migration->up();

        $property = DB::table('properties')->first();

        $this->assertSame('2026-07-01 10:00:00', $property->created_at);
        $this->assertSame('2026-08-01 12:00:00', $property->updated_at);
        $this->assertSame('2026-07-01 10:00:00', $property->listing_updated_at);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('properties', 'listing_updated_at'));
    }
}
