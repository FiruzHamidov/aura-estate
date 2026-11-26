<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->index(['status', 'start_time', 'property_id'], 'bookings_top50_period_idx'));
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_top50_period_idx'));
    }
};
