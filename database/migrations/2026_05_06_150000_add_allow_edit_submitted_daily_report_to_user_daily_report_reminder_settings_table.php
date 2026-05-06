<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_daily_report_reminder_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('user_daily_report_reminder_settings', 'allow_edit_submitted_daily_report')) {
                $table->boolean('allow_edit_submitted_daily_report')
                    ->default(false)
                    ->after('channels');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_daily_report_reminder_settings', function (Blueprint $table) {
            if (Schema::hasColumn('user_daily_report_reminder_settings', 'allow_edit_submitted_daily_report')) {
                $table->dropColumn('allow_edit_submitted_daily_report');
            }
        });
    }
};
