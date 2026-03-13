<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('hls_url')->nullable();
            $table->string('mp4_url')->nullable();
            $table->string('preview_image')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('aspect_ratio', 16)->default('9:16');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('video_size')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->string('transcode_status', 32)->default('pending');
            $table->json('processing_meta')->nullable();
            $table->unsignedInteger('poster_second')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('property_id');
            $table->index('status');
            $table->index('published_at');
            $table->index('sort_order');
            $table->index('is_featured');
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reels');
    }
};
