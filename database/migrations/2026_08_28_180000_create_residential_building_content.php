<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_building_nearby_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 40);
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedInteger('distance_meters')->nullable();
            $table->string('distance_method', 30)->default('straight_line');
            $table->decimal('distance_origin_latitude', 11, 8)->nullable();
            $table->decimal('distance_origin_longitude', 11, 8)->nullable();
            $table->string('source_url', 2000);
            $table->timestamp('data_verified_at');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['new_building_id', 'type', 'sort_order']);
        });
        Schema::create('new_building_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('provider', 20);
            $table->string('provider_id', 30);
            $table->string('url', 2000);
            $table->timestamp('data_verified_at');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['new_building_id', 'provider', 'provider_id'], 'building_video_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('new_building_nearby_places')->exists() || DB::table('new_building_videos')->exists()) {
            throw new RuntimeException('Export and explicitly remove building content before reverting its schema.');
        }
        Schema::dropIfExists('new_building_videos');
        Schema::dropIfExists('new_building_nearby_places');
    }
};
