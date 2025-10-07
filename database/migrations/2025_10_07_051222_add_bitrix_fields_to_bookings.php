<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('place')->nullable();
            $table->string('bitrix_activity_id')->nullable()->index(); // если будете хранить ID активности
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'deal_id',
                'contact_id',
                'place',
                'bitrix_activity_id',
            ]);
        });
    }
};
