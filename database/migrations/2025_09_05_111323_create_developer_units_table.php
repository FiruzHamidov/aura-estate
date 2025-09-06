<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('developer_units', function (Blueprint $table) {
            $table->id();

            $table->foreignId('new_building_id')->constrained('new_buildings')->cascadeOnDelete();
            $table->foreignId('block_id')->nullable()->constrained('new_building_blocks')->nullOnDelete();

            $table->string('name');                                    // Название/планировка (например, "1А", "2Б")
            $table->unsignedTinyInteger('bedrooms')->default(0);       // Кол-во спален
            $table->unsignedTinyInteger('bathrooms')->default(0);      // Кол-во санузлов
            $table->decimal('area', 10, 2);                            // Площадь (м²)
            $table->integer('floor')->nullable();                      // На каком этаже
            $table->decimal('price_per_sqm', 15, 2)->nullable();       // Цена за м²
            $table->decimal('total_price', 15, 2)->nullable();         // Общая стоимость
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(true);            // Доступен к продаже
            $table->enum('moderation_status', ['pending','approved','rejected','draft','deleted'])
                ->default('pending');
            $table->timestamps();

            $table->index(['new_building_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_units');
    }
};
