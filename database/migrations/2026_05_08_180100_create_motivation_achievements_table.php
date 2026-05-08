<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('motivation_rules')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('company_scope')->nullable();
            $table->dateTime('won_at');
            $table->enum('period_type', ['week', 'month', 'year']);
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('snapshot_value', 12, 4);
            $table->enum('status', ['new', 'approved', 'issued', 'cancelled'])->default('new');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'user_id', 'period_type', 'date_from', 'date_to'], 'motivation_achievements_agent_unique');
            $table->unique(['rule_id', 'company_scope', 'period_type', 'date_from', 'date_to'], 'motivation_achievements_company_unique');
            $table->index(['status', 'won_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_achievements');
    }
};
