<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['properties', 'property_analytics_daily'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('non_client_views_count')->default(0);
            });
        }
        Schema::table('property_analytics_coverage', function (Blueprint $table) {
            $table->string('non_client_views_started_at')->nullable();
        });
        // Existing aggregates contain no visitor role, so they cannot be backfilled.
        DB::table('property_analytics_coverage')->where('id', 1)->update([
            'non_client_views_started_at' => now('Asia/Dushanbe')->toIso8601String(),
        ]);
    }

    public function down(): void
    {
        foreach (['properties', 'property_analytics_daily'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('non_client_views_count'));
        }
        Schema::table('property_analytics_coverage', fn (Blueprint $table) => $table->dropColumn('non_client_views_started_at'));
    }
};
