<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable()->after('guest_session_id');
            $table->unique(
                ['conversation_id', 'author_id', 'client_message_id'],
                'conversation_messages_author_client_id_unique'
            );
            $table->unique(
                ['conversation_id', 'guest_session_id', 'client_message_id'],
                'conversation_messages_guest_client_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropUnique('conversation_messages_author_client_id_unique');
            $table->dropUnique('conversation_messages_guest_client_id_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
