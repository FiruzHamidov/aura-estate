<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('kpi_plans', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('role_slug')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('kpi_plans', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('comment');
            }

            if (! Schema::hasColumn('kpi_plans', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }

            $table->index(['user_id', 'effective_from', 'effective_to'], 'kpi_plans_user_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            $table->dropIndex('kpi_plans_user_effective_idx');

            if (Schema::hasColumn('kpi_plans', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }

            if (Schema::hasColumn('kpi_plans', 'effective_from')) {
                $table->dropColumn('effective_from');
            }

            if (Schema::hasColumn('kpi_plans', 'effective_to')) {
                $table->dropColumn('effective_to');
            }
        });
    }
};
