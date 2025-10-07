<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('selections', function (Blueprint $table) {
            $table->id();
            // Кто создал (агент/менеджер)
            $table->unsignedBigInteger('created_by')->nullable()->index();

            // Связки с Bitrix24 (необязательные)
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();

            // Бизнес-поля
            $table->string('title')->nullable();
            $table->json('property_ids');                 // массив ID объектов
            $table->string('channel')->nullable();        // whatsapp|telegram|sms|email
            $table->text('note')->nullable();

            // Публичная ссылка
            $table->string('selection_hash', 64)->unique();
            $table->string('selection_url')->unique();

            // Жизненный цикл
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Тех и аналитика
            $table->string('status', 24)->default('draft'); // draft|sent|viewed|expired|archived
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selections');
    }
};
