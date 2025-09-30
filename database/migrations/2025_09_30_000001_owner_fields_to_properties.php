<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Добавляем поле owner_name после owner_phone (если поле есть)
            $table->string('owner_name')->nullable()->after('owner_phone');
            $table->string('object_key')->nullable()->after('owner_name');
        });

        // Обновляем ENUM moderation_status
        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status
            ENUM('pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented', 'sold_by_owner')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Удаляем поле owner_name
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('owner_name');
            $table->dropColumn('object_key');
        });

        // Возвращаем ENUM moderation_status к предыдущему виду
        DB::statement("
            ALTER TABLE properties
            MODIFY moderation_status
            ENUM('pending', 'approved', 'rejected', 'draft', 'deleted', 'sold', 'rented')
            NOT NULL DEFAULT 'pending'
        ");
    }
};
