<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('new_building_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->cascadeOnDelete();
            $table->string('path');                 // Путь до файла
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['new_building_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_building_photos');
    }
};
