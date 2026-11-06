<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('rental_monthly_amount', 16, 2)->nullable();
            $table->decimal('rental_contract_amount', 16, 2)->nullable();
            // Historic conversion: TJS for one USD, fixed at deal closure.
            $table->decimal('deal_fx_rate', 16, 8)->nullable();
        });
        Schema::create('kpi_result_facts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->index();
            $table->string('source_event', 100)->unique();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('kind', 16);
            $table->string('state', 16);
            $table->dateTime('closed_at')->nullable()->index();
            $table->decimal('amount', 16, 2)->nullable();
            $table->decimal('rental_monthly_amount', 16, 2)->nullable();
            $table->decimal('rental_contract_amount', 16, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('fx_rate', 16, 8)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_group_id', 'closed_at']);
        });
        Schema::create('kpi_result_goals', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->date('month');
            $table->string('kind', 16);
            $table->unsignedInteger('version');
            $table->string('mode', 32);
            $table->unsignedInteger('count_target')->nullable();
            $table->decimal('amount_target', 16, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->string('rental_basis', 32)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['scope_key', 'month', 'kind', 'version'], 'kpi_result_goal_version_unique');
        });
        Schema::create('kpi_result_periods', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('closed_by');
            $table->timestamps();
            $table->unique(['month', 'user_id']);
        });
        Schema::create('kpi_result_period_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id')->index();
            $table->unsignedInteger('version');
            $table->json('before');
            $table->json('after');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('actor_id');
            $table->timestamps();
            $table->unique(['period_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_result_period_revisions');
        Schema::dropIfExists('kpi_result_periods');
        Schema::dropIfExists('kpi_result_goals');
        Schema::dropIfExists('kpi_result_facts');
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn([
            'rental_monthly_amount', 'rental_contract_amount', 'deal_fx_rate',
        ]));
    }
};
