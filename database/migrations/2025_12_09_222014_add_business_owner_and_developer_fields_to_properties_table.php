<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Boolean: владелец бизнесмен?
            $table->boolean('is_business_owner')
                ->default(false)
                ->after('listing_type');

            // Boolean: Полноценная квартира?
            $table->boolean('is_full_apartment')
                ->default(false)
                ->after('is_business_owner');

            $table->boolean('is_for_aura')
                ->default(false)
                ->after('is_full_apartment');

            // Связь с developers
            $table->unsignedBigInteger('developer_id')
                ->nullable()
                ->after('is_for_aura');

            $table->foreign('developer_id')
                ->references('id')
                ->on('developers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['developer_id']);
            $table->dropColumn(['developer_id', 'is_business_owner', 'is_full_apartment', 'is_for_aura']);
        });
    }
};
