<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attendance_leaves', 'attendance_duties'] as $name) {
            if (! Schema::hasTable($name)) continue;
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
                $table->index(['branch_group_id', 'date_from', 'date_to']);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve attendance classification with a forward migration.');
    }
};
