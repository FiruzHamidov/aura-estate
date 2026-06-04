<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index('title', 'properties_search_title_idx');
            $table->index('address', 'properties_search_address_idx');
            $table->index('district', 'properties_search_district_idx');
            $table->index('price', 'properties_search_price_idx');
            $table->index('rooms', 'properties_search_rooms_idx');
            $table->index('location_id', 'properties_search_location_idx');
            $table->index('status_id', 'properties_search_status_idx');
            $table->index('type_id', 'properties_search_type_idx');
            $table->index('created_at', 'properties_search_created_at_idx');
            $table->index(['moderation_status', 'created_at'], 'properties_search_visibility_created_idx');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('properties', function (Blueprint $table) {
                $table->fullText(
                    ['title', 'description', 'address', 'district', 'landmark', 'owner_name', 'owner_phone'],
                    'properties_search_fulltext_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropFullText('properties_search_fulltext_idx');
            });
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_search_title_idx');
            $table->dropIndex('properties_search_address_idx');
            $table->dropIndex('properties_search_district_idx');
            $table->dropIndex('properties_search_price_idx');
            $table->dropIndex('properties_search_rooms_idx');
            $table->dropIndex('properties_search_location_idx');
            $table->dropIndex('properties_search_status_idx');
            $table->dropIndex('properties_search_type_idx');
            $table->dropIndex('properties_search_created_at_idx');
            $table->dropIndex('properties_search_visibility_created_idx');
        });
    }
};
