<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('kpi_adjustment_logs', function (Blueprint $table) {
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
        });
    }
    public function down(): void { throw new RuntimeException('Adjustment group history must be preserved.'); }
};
