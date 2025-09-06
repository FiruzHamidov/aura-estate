<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('new_building_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->cascadeOnDelete();
            $table->string('name');                   // "А", "Б", "С"
            $table->unsignedInteger('floors_from')->nullable();
            $table->unsignedInteger('floors_to')->nullable();
            $table->dateTime('completion_at')->nullable(); // Можно отдельный срок сдачи по блоку
            $table->timestamps();

            $table->unique(['new_building_id', 'name']); // Один блок с именем в пределах ЖК
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_building_blocks');
    }
};
