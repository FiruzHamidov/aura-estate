<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('remember_token');
            $table->timestamp('deletion_requested_at')->nullable()->after('deleted_at');
            $table->string('deletion_reason')->nullable()->after('deletion_requested_at');
            $table->foreignId('deleted_by_user_id')
                ->nullable()
                ->after('deletion_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('deletion_phone_hash', 64)->nullable()->after('deleted_by_user_id');

            $table->index(['status', 'deleted_at']);
            $table->index('deletion_phone_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'deleted_at']);
            $table->dropIndex(['deletion_phone_hash']);
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn([
                'deleted_at',
                'deletion_requested_at',
                'deletion_reason',
                'deletion_phone_hash',
            ]);
        });
    }
};
