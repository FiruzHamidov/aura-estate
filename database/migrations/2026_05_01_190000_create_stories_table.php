<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20); // media|property|reel
            $table->string('status', 20)->default('draft'); // draft|active|archived|hidden|deleted
            $table->string('caption', 500)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};

