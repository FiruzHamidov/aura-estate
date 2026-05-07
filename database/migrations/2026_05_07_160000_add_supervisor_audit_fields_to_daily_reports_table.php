<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_reports', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('finalized_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('daily_reports', 'updated_by_role')) {
                $table->string('updated_by_role', 64)->nullable()->after('updated_by');
            }

            if (! Schema::hasColumn('daily_reports', 'updated_reason')) {
                $table->string('updated_reason', 500)->nullable()->after('updated_by_role');
            }

            if (! Schema::hasColumn('daily_reports', 'edit_source')) {
                $table->string('edit_source', 64)->nullable()->after('updated_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            foreach (['edit_source', 'updated_reason', 'updated_by_role'] as $column) {
                if (Schema::hasColumn('daily_reports', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('daily_reports', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
        });
    }
};
