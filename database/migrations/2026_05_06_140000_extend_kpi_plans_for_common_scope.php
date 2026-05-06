<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('kpi_plans', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('user_id')->constrained('branches')->nullOnDelete();
            }

            if (! Schema::hasColumn('kpi_plans', 'branch_group_id')) {
                $table->foreignId('branch_group_id')->nullable()->after('branch_id')->constrained('branch_groups')->nullOnDelete();
            }

            $table->index(['role_slug', 'branch_id', 'branch_group_id', 'effective_from', 'effective_to'], 'kpi_plans_common_scope_effective_idx');
        });

        Schema::table('kpi_plans', function (Blueprint $table) {
            $table->dropUnique('kpi_plans_role_slug_metric_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            $table->unique(['role_slug', 'metric_key']);
            $table->dropIndex('kpi_plans_common_scope_effective_idx');

            if (Schema::hasColumn('kpi_plans', 'branch_group_id')) {
                $table->dropConstrainedForeignId('branch_group_id');
            }

            if (Schema::hasColumn('kpi_plans', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }
};
