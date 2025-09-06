<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feature_new_building', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['new_building_id','feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_new_building');
    }
};
