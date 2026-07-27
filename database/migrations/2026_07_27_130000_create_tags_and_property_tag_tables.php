<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('property_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'tag_id']);
            $table->index(['tag_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_tag');
        Schema::dropIfExists('tags');
    }
};
