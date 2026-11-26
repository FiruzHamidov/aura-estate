<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_result_facts', fn (Blueprint $table) => $table->json('sale_credits')->nullable());
    }

    public function down(): void
    {
        Schema::table('kpi_result_facts', fn (Blueprint $table) => $table->dropColumn('sale_credits'));
    }
};
