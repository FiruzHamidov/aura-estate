<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('reviews', 'reviewable_type')) {
                $table->string('reviewable_type')->nullable()->after('id');
            }

            if (!Schema::hasColumn('reviews', 'reviewable_id')) {
                $table->unsignedBigInteger('reviewable_id')->nullable()->after('reviewable_type');
            }

            if (!Schema::hasColumn('reviews', 'author_name')) {
                $table->string('author_name')->nullable()->after('reviewable_id');
            }

            if (!Schema::hasColumn('reviews', 'author_phone')) {
                $table->string('author_phone', 64)->nullable()->after('author_name');
            }

            if (!Schema::hasColumn('reviews', 'author_user_id')) {
                $table->foreignId('author_user_id')->nullable()->after('author_phone')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('reviews', 'rating')) {
                $table->unsignedTinyInteger('rating')->nullable()->after('author_user_id');
            }

            if (!Schema::hasColumn('reviews', 'text')) {
                $table->text('text')->nullable()->after('rating');
            }

            if (!Schema::hasColumn('reviews', 'status')) {
                $table->string('status', 32)->default('pending')->after('text');
            }

            if (!Schema::hasColumn('reviews', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('status');
            }
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->index(['reviewable_type', 'reviewable_id'], 'reviews_reviewable_index');
            $table->index('status', 'reviews_status_index');
            $table->unique(['reviewable_type', 'reviewable_id', 'author_phone'], 'reviews_unique_target_phone');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_unique_target_phone');
            $table->dropIndex('reviews_reviewable_index');
            $table->dropIndex('reviews_status_index');
            $table->dropConstrainedForeignId('author_user_id');
            $table->dropColumn([
                'reviewable_type',
                'reviewable_id',
                'author_name',
                'author_phone',
                'rating',
                'text',
                'status',
                'published_at',
            ]);
        });
    }
};
