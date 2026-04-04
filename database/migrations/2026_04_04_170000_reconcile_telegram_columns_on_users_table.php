<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'telegram_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('telegram_id')->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('users', 'telegram_username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('telegram_username')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'telegram_photo_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('telegram_photo_url')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'telegram_chat_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('telegram_chat_id')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'telegram_linked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('telegram_linked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'telegram_id')) {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropUnique(['telegram_id']);
                });
            } catch (\Throwable) {
                // Ignore missing index state in partially migrated environments.
            }
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('users', 'telegram_id') ? 'telegram_id' : null,
            Schema::hasColumn('users', 'telegram_username') ? 'telegram_username' : null,
            Schema::hasColumn('users', 'telegram_photo_url') ? 'telegram_photo_url' : null,
            Schema::hasColumn('users', 'telegram_chat_id') ? 'telegram_chat_id' : null,
            Schema::hasColumn('users', 'telegram_linked_at') ? 'telegram_linked_at' : null,
        ]));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
