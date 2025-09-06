<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('new_buildings', function (Blueprint $table) {
            $table->id();

            $table->string('title');                   // Название ЖК/объекта
            $table->text('description')->nullable();

            // Связи
            $table->foreignId('developer_id')->nullable()->constrained('developers')->nullOnDelete();
            $table->foreignId('construction_stage_id')->nullable()->constrained('construction_stages')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();

            // Привязка к локации как в properties (если используешь тот же справочник)
            $table->unsignedBigInteger('location_id')->nullable();
            $table->foreign('location_id')->references('id')->on('locations');

            // Флаги/поля по ТЗ
            $table->boolean('installment_available')->default(false); // Рассрочка
            $table->boolean('heating')->default(false);               // Отопление (да/нет)
            $table->boolean('has_terrace')->default(false);           // Терраса (да/нет)

            $table->string('floors_range')->nullable();               // "3-14"
            $table->dateTime('completion_at')->nullable();            // Срок сдачи (точная дата)

            // Адрес (по желанию) и геопозиция как в properties
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Модерация (если нужно, можно убрать)
            $table->enum('moderation_status', ['pending','approved','rejected','draft','deleted'])->default('pending');

            // Автор (если нужно вести)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Индексы
            $table->index(['developer_id']);
            $table->index(['construction_stage_id']);
            $table->index(['material_id']);
            $table->index(['location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_buildings');
    }
};
