<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('kpi_plans', 'plan_period')) {
                $table->string('plan_period', 16)->default('month')->after('metric_key');
                $table->index(['plan_period'], 'kpi_plans_plan_period_idx');
            }
        });

        DB::table('kpi_plans')->update(['plan_period' => 'month']);
    }

    public function down(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_plans', 'plan_period')) {
                $table->dropIndex('kpi_plans_plan_period_idx');
                $table->dropColumn('plan_period');
            }
        });
    }
};
