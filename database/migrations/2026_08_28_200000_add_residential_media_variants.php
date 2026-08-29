<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('variants')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            if (DB::table($name)->whereNotNull('variants')->exists()) {
                throw new RuntimeException('Export responsive media metadata before rollback.');
            }
        }
        foreach (['new_building_photos', 'developer_unit_photos', 'unit_layouts', 'building_floor_plans'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('variants'));
        }
    }
};
