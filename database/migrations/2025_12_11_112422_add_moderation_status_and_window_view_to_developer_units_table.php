<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('developer_units', function (Blueprint $table) {
            // добавляем moderation_status (ENUM) со значением по умолчанию 'pending'
            Schema::table('developer_units', function (Blueprint $table) {
                $table->enum('moderation_status', [
                    'pending',
                    'available',
                    'sold',
                    'reserved',
                ])->default('pending')->change();
            });

            // добавляем window_view (ENUM) — NULLable (можно сделать NOT NULL если требуется)
            $table->enum('window_view', [
                'courtyard',   // Во двор
                'street',      // На улицу
                'park',        // На парк / зелёную зону
                'mountains',   // На горы
                'city',        // На город
                'panoramic',   // Панорамный вид
            ])->nullable()->after('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::table('developer_units', function (Blueprint $table) {
            // при откате просто удаляем добавленные столбцы
            $table->dropColumn(['window_view', 'moderation_status']);
        });
    }
};
