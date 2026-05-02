<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_reports', 'ad_count')) {
                $table->unsignedInteger('ad_count')->default(0)->after('calls_count');
            }

            if (! Schema::hasColumn('daily_reports', 'deposits_count')) {
                $table->unsignedInteger('deposits_count')->default(0)->after('new_properties_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            if (Schema::hasColumn('daily_reports', 'ad_count')) {
                $table->dropColumn('ad_count');
            }

            if (Schema::hasColumn('daily_reports', 'deposits_count')) {
                $table->dropColumn('deposits_count');
            }
        });
    }
};
