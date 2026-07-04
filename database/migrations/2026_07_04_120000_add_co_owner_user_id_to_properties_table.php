<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('co_owner_user_id')
                ->nullable()
                ->after('agent_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('co_owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['co_owner_user_id']);
            $table->dropIndex(['co_owner_user_id']);
            $table->dropColumn('co_owner_user_id');
        });
    }
};
