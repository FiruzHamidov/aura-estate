<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('developers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                           // Имя застройщика
            $table->string('phone')->nullable();              // Телефон
            $table->unsignedInteger('under_construction_count')->default(0); // Строится
            $table->unsignedInteger('built_count')->default(0);              // Построено
            $table->year('founded_year')->nullable();         // Год основания
            $table->unsignedInteger('total_projects')->default(0); // Всего проектов
            $table->string('logo_path')->nullable();          // Путь к логотипу
            $table->enum('moderation_status', ['pending','approved','rejected','draft','deleted'])
                ->default('pending');
            // Соцсети/сайты
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('telegram')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developers');
    }
};
