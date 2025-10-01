<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();       // внешний ID с фронта
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 10)->nullable();   // например: ru, tg, en
            $table->timestamp('last_user_message_at')->nullable();
            $table->timestamp('last_assistant_message_at')->nullable();
            $table->json('meta')->nullable();             // любые доп. атрибуты
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
