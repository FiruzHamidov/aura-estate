<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_period_locks', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->string('period_key', 32);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->foreignId('locked_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['period_type', 'period_key', 'branch_id', 'branch_group_id'], 'kpi_period_locks_scope_unique');
            $table->index(['period_type', 'period_key']);
        });

        Schema::create('kpi_adjustment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->string('period_key', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('field_name', 64);
            $table->decimal('old_value', 14, 4)->nullable();
            $table->decimal('new_value', 14, 4)->nullable();
            $table->text('reason');
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['period_type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_adjustment_logs');
        Schema::dropIfExists('kpi_period_locks');
    }
};
