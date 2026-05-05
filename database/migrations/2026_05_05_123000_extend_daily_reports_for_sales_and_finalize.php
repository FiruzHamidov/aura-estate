<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_reports', 'sales_count')) {
                $table->decimal('sales_count', 8, 4)->default(0)->after('deals_count');
            }

            if (!Schema::hasColumn('daily_reports', 'is_finalized')) {
                $table->boolean('is_finalized')->default(false)->after('submitted_at');
            }

            if (!Schema::hasColumn('daily_reports', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('is_finalized');
            }

            if (!Schema::hasColumn('daily_reports', 'finalized_by')) {
                $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            if (Schema::hasColumn('daily_reports', 'finalized_by')) {
                $table->dropConstrainedForeignId('finalized_by');
            }

            foreach (['finalized_at', 'is_finalized', 'sales_count'] as $column) {
                if (Schema::hasColumn('daily_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

