<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['kpi_quality_issues', 'kpi_acceptance_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Diagnostic group classification must be preserved.');
    }
};
