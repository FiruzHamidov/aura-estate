<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_rop_plans', function (Blueprint $table) {
            $table->id();
            $table->string('role_slug', 64);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('month', 7);
            $table->json('items');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['month', 'role_slug'], 'kpi_rop_plans_month_role_idx');
            $table->index(['branch_id', 'branch_group_id'], 'kpi_rop_plans_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_rop_plans');
    }
};
