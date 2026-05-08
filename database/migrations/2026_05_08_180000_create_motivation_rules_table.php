<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['agent', 'company']);
            $table->string('metric_key', 64)->default('sales_count');
            $table->decimal('threshold_value', 8, 4);
            $table->enum('reward_type', ['trip_tashkent', 'trip_umra', 'company_party']);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('period_type', ['week', 'month', 'year']);
            $table->date('date_from');
            $table->date('date_to');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique([
                'scope',
                'metric_key',
                'threshold_value',
                'period_type',
                'date_from',
                'date_to',
                'reward_type',
            ], 'motivation_rules_unique_period_scope_reward');
            $table->index(['is_active', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_rules');
    }
};
