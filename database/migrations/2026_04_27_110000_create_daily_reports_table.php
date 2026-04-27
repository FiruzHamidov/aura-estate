<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_slug', 64);
            $table->date('report_date');
            $table->unsignedInteger('calls_count')->default(0);
            $table->unsignedInteger('meetings_count')->default(0);
            $table->unsignedInteger('shows_count')->default(0);
            $table->unsignedInteger('new_clients_count')->default(0);
            $table->unsignedInteger('new_properties_count')->default(0);
            $table->unsignedInteger('deals_count')->default(0);
            $table->text('comment')->nullable();
            $table->text('plans_for_tomorrow')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'report_date']);
            $table->index(['report_date', 'role_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
