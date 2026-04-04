<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reel_likes')) {
            return;
        }

        Schema::create('reel_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_token', 100)->nullable();
            $table->timestamps();

            $table->unique(['reel_id', 'user_id']);
            $table->unique(['reel_id', 'guest_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reel_likes');
    }
};
