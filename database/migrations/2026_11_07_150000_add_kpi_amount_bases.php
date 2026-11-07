<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_result_goals', function (Blueprint $table) {
            $table->string('amount_basis', 32)->default('deal_value');
        });
        Schema::table('kpi_result_facts', function (Blueprint $table) {
            $table->decimal('company_commission_amount', 16, 2)->nullable();
            $table->string('company_commission_currency', 3)->nullable();
            // [] means no recorded participant rewards; null is a pre-upgrade snapshot.
            $table->json('participant_commissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kpi_result_facts', fn (Blueprint $table) => $table->dropColumn([
            'company_commission_amount', 'company_commission_currency', 'participant_commissions',
        ]));
        Schema::table('kpi_result_goals', fn (Blueprint $table) => $table->dropColumn('amount_basis'));
    }
};
