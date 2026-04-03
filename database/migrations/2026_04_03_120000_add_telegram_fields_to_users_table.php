<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_id')->nullable()->unique()->after('auth_method');
            $table->string('telegram_username')->nullable()->after('telegram_id');
            $table->text('telegram_photo_url')->nullable()->after('telegram_username');
            $table->string('telegram_chat_id')->nullable()->after('telegram_photo_url');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_id']);
            $table->dropColumn([
                'telegram_id',
                'telegram_username',
                'telegram_photo_url',
                'telegram_chat_id',
                'telegram_linked_at',
            ]);
        });
    }
};
