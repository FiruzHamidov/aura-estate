<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_reason', 1000)->nullable();
            $table->index(['reviewable_type', 'reviewable_id', 'author_user_id'], 'reviews_target_author');
        });
        Schema::create('review_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 1000)->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'user_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('review_complaints')->exists() || \Illuminate\Support\Facades\DB::table('reviews')->whereNotNull('moderated_by')->exists()) {
            throw new RuntimeException('Review moderation history must be retained. Disable the UI instead.');
        }
        Schema::dropIfExists('review_complaints');
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_target_author');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['version', 'moderated_at', 'moderation_reason']);
        });
    }
};
