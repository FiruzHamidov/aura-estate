<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1) Добавляем новое значение ENUM 'отказано' в moderation_status
        DB::statement("
            ALTER TABLE `properties`
            MODIFY `moderation_status`
            ENUM(
                'pending',
                'approved',
                'rejected',
                'draft',
                'deleted',
                'sold',
                'rented',
                'sold_by_owner',
                'denied'
            ) NOT NULL DEFAULT 'pending'
        ");

        // 2) Добавляем поле для комментария причины отказа
        Schema::table('properties', function (Blueprint $table) {
            // Добавляем текстовое поле комментария (nullable)
            $table->text('rejection_comment')
                ->nullable()
                ->after('moderation_status')
                ->comment('Комментарий администратора при отказе');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedSmallInteger('land_size')
                ->nullable()
                ->after('total_area')
                ->comment('Площадь участка в сотках (целые сотки)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1) Удаляем поле 'sotki'
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'sotki')) {
                $table->dropColumn('sotki');
            }
        });

        // 2) Удаляем поле rejection_comment
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'rejection_comment')) {
                $table->dropColumn('rejection_comment');
            }
        });

        // 3) Возвращаем ENUM moderation_status к прежнему состоянию (без 'отказано')
        DB::statement("
            ALTER TABLE `properties`
            MODIFY `moderation_status`
            ENUM(
                'pending',
                'approved',
                'rejected',
                'draft',
                'deleted',
                'sold',
                'rented',
                'sold_by_owner'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
