<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_catalog_claims', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'claimed_on']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Do not delete assignment history or partially restore the old constraint.
        if (DB::table('client_catalog_claims')->select('user_id', 'claimed_on')
            ->groupBy('user_id', 'claimed_on')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the daily limit while multiple same-day assignments exist.');
        }
        Schema::table('client_catalog_claims', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->unique(['user_id', 'claimed_on']);
        });
    }
};
