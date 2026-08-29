<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                // Existing files remain untouched until the explicit, audited media migration.
                $table->string('storage_disk', 20)->default('public');
                $table->string('original_path')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                if (str_ends_with($name, '_photos')) {
                    $table->string('kind', 20)->default('photo');
                    $table->string('alt')->nullable();
                    $table->unsignedInteger('version')->default(1);
                }
            });
        }
        Schema::table('new_building_photos', fn (Blueprint $table) => $table->json('block_regions')->nullable());
    }

    public function down(): void
    {
        // Do not lose storage metadata while private files are still in use.
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            if (\Illuminate\Support\Facades\DB::table($name)->where('storage_disk', 'residential')->exists()) {
                throw new RuntimeException('Private residential media must be exported before rolling back its metadata.');
            }
        }
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropColumn(['storage_disk', 'original_path', 'width', 'height']);
                if (str_ends_with($name, '_photos')) {
                    $table->dropColumn(['kind', 'alt', 'version']);
                }
            });
        }
        Schema::table('new_building_photos', fn (Blueprint $table) => $table->dropColumn('block_regions'));
    }
};
