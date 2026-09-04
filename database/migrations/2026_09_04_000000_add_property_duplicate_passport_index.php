<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index(
                ['offer_type', 'moderation_status', 'type_id', 'location_id', 'rooms', 'floor', 'total_area'],
                'properties_duplicate_passport_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_duplicate_passport_idx');
        });
    }
};
