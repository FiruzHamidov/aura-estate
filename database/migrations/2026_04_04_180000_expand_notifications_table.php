<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('type', 100)->nullable()->after('actor_id')->index();
            $table->string('category', 32)->nullable()->after('type')->index();
            $table->string('status', 20)->default('unread')->after('category')->index();
            $table->unsignedTinyInteger('priority')->default(2)->after('status')->index();
            $table->json('channels')->nullable()->after('priority');
            $table->string('title')->nullable()->after('channels');
            $table->text('body')->nullable()->after('title');
            $table->string('action_url')->nullable()->after('body');
            $table->string('action_type', 50)->nullable()->after('action_url');
            $table->string('dedupe_key')->nullable()->after('action_type')->index();
            $table->unsignedInteger('occurrences_count')->default(1)->after('dedupe_key');
            $table->timestamp('last_occurred_at')->nullable()->after('occurrences_count');
            $table->timestamp('read_at')->nullable()->after('last_occurred_at')->index();
            $table->timestamp('delivered_at')->nullable()->after('read_at');
            $table->timestamp('scheduled_at')->nullable()->after('delivered_at');
            $table->nullableMorphs('subject');
            $table->json('data')->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('actor_id');
            $table->dropMorphs('subject');
            $table->dropColumn([
                'type',
                'category',
                'status',
                'priority',
                'channels',
                'title',
                'body',
                'action_url',
                'action_type',
                'dedupe_key',
                'occurrences_count',
                'last_occurred_at',
                'read_at',
                'delivered_at',
                'scheduled_at',
                'data',
            ]);
        });
    }
};
