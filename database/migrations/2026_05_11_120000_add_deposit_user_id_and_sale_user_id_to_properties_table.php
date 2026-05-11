<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'deposit_user_id')) {
                $table->foreignId('deposit_user_id')
                    ->nullable()
                    ->after('deposit_taken_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('properties', 'sale_user_id')) {
                $table->foreignId('sale_user_id')
                    ->nullable()
                    ->after('sold_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'deposit_user_id')) {
                $table->dropConstrainedForeignId('deposit_user_id');
            }

            if (Schema::hasColumn('properties', 'sale_user_id')) {
                $table->dropConstrainedForeignId('sale_user_id');
            }
        });
    }
};
