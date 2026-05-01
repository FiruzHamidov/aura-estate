<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('viewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token', 100)->nullable();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(['story_id', 'viewer_user_id']);
            $table->index(['story_id', 'guest_token']);
            $table->unique(['story_id', 'viewer_user_id'], 'story_views_story_user_unique');
            $table->unique(['story_id', 'guest_token'], 'story_views_story_guest_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_views');
    }
};

