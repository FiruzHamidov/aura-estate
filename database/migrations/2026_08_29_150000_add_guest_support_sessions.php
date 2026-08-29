<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->foreignId('guest_session_id')
                ->nullable()
                ->after('author_id')
                ->constrained('guest_support_sessions')
                ->nullOnDelete();
        });

        Schema::table('support_threads', function (Blueprint $table) {
            $table->foreignId('requester_user_id')->nullable()->change();
            $table->foreignId('guest_session_id')
                ->nullable()
                ->after('requester_user_id')
                ->constrained('guest_support_sessions')
                ->cascadeOnDelete();
            $table->index(['guest_session_id', 'status']);
        });
    }

    public function down(): void
    {
        $guestConversationIds = DB::table('support_threads')
            ->whereNotNull('guest_session_id')
            ->pluck('conversation_id');

        DB::table('conversations')->whereIn('id', $guestConversationIds)->delete();

        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropForeign(['guest_session_id']);
            $table->dropIndex(['guest_session_id', 'status']);
            $table->dropColumn('guest_session_id');
            $table->foreignId('requester_user_id')->nullable(false)->change();
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropForeign(['guest_session_id']);
            $table->dropColumn('guest_session_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });

        Schema::dropIfExists('guest_support_sessions');
    }
};
