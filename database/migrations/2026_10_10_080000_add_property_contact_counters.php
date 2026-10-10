<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('phone_clicks')->default(0);
            $table->unsignedBigInteger('chat_clicks')->default(0);
            $table->unsignedBigInteger('whatsapp_clicks')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn(['phone_clicks', 'chat_clicks', 'whatsapp_clicks']));
    }
};
