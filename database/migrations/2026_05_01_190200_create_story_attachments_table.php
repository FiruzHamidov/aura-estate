<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->morphs('attachable');
            $table->json('snapshot_json')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'attachable_type', 'attachable_id'], 'story_attachments_story_attachable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_attachments');
    }
};

