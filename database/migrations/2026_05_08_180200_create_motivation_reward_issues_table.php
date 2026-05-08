<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_reward_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('achievement_id')->constrained('motivation_achievements')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['new', 'in_progress', 'issued', 'rejected'])->default('new');
            $table->dateTime('issued_at')->nullable();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['achievement_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_reward_issues');
    }
};
