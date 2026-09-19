<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_early_risk_alerts', function (Blueprint $table) {
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
            $table->index(['branch_group_id', 'alert_date']);
        });
        // Historical alerts require reviewed classification; current membership is not historical evidence.
    }

    public function down(): void
    {
        throw new RuntimeException('Alert group history must be preserved; use a forward migration.');
    }
};
