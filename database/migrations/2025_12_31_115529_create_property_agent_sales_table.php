<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');

            // Роль агента
            $table->enum('role', [
                'main',
                'assistant',
                'partner'
            ])->default('assistant');

            // Комиссия конкретного агента
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->enum('agent_commission_currency', ['TJS', 'USD'])->default('TJS');

            // Когда агент получил деньги
            $table->timestamp('agent_paid_at')->nullable();

            $table->timestamps();

            $table->foreign('property_id')
                ->references('id')->on('properties')
                ->onDelete('cascade');

            $table->foreign('agent_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->unique(['property_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_agent_sales');
    }
};
