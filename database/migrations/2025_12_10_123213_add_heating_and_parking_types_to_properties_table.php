<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('heating_type_id')
                ->nullable()
                ->after('repair_type_id');

            $table->unsignedBigInteger('parking_type_id')
                ->nullable()
                ->after('heating_type_id');

            // Foreign keys (если таблицы существуют)
            $table->foreign('heating_type_id')
                ->references('id')
                ->on('heating_types')
                ->onDelete('set null');

            $table->foreign('parking_type_id')
                ->references('id')
                ->on('parking_types')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['heating_type_id']);
            $table->dropForeign(['parking_type_id']);

            $table->dropColumn(['heating_type_id', 'parking_type_id']);
        });
    }
};
