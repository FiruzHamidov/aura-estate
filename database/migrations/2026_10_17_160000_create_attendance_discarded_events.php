<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep only deduplication keys after an operator removes unmatched events.
        Schema::create('attendance_discarded_events', function (Blueprint $table) {
            $table->char('event_hash', 64)->primary();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_discarded_events');
    }
};
