<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients' => 'additional_phone', 'external_property_requests' => 'owner_additional_phone'] as $table => $column) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string($column, 50)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['clients' => 'additional_phone', 'external_property_requests' => 'owner_additional_phone'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
