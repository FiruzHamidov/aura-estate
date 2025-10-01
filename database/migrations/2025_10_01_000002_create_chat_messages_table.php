<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->enum('role', ['system','user','assistant','tool']);
            $table->longText('content')->nullable();      // текст сообщения
            $table->string('tool_name')->nullable();      // если это tool-вызов
            $table->json('tool_args')->nullable();        // аргументы инструмента
            $table->json('items')->nullable();            // предложенные объекты (карточки)
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();

            $table->index(['chat_session_id','created_at']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
