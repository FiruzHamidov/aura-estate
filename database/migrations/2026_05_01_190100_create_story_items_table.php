<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('media_type', 20); // image|video
            $table->text('media_url');
            $table->text('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_sec')->default(5);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_items');
    }
};

