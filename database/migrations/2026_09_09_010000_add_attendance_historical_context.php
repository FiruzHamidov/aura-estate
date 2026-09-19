<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attendance_events', 'attendance_daily_summaries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('role_slug', 40)->nullable();
                $table->index(['branch_group_id', 'role_slug']);
            });
        }
        Schema::table('attendance_daily_summaries', fn (Blueprint $table) => $table->json('schedule_snapshot')->nullable());
        // Unknown historical roles/schedules must not be inferred from today's user profile.
    }

    public function down(): void
    {
        throw new RuntimeException('Historical attendance classification must be preserved.');
    }
};
