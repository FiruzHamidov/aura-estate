<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_plans', function (Blueprint $table) {
            $table->id();
            $table->string('role_slug', 64);
            $table->string('metric_key', 64);
            $table->decimal('daily_plan', 14, 4)->default(0);
            $table->decimal('weight', 8, 4)->default(0);
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['role_slug', 'metric_key']);
            $table->index(['role_slug']);
        });

        Schema::create('kpi_integration_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->string('status', 32)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('kpi_telegram_report_configs', function (Blueprint $table) {
            $table->id();
            $table->boolean('daily_enabled')->default(false);
            $table->string('daily_time', 5)->default('09:00');
            $table->boolean('weekly_enabled')->default(true);
            $table->unsignedTinyInteger('weekly_day')->default(1);
            $table->string('weekly_time', 5)->default('10:00');
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->timestamps();
        });

        Schema::create('kpi_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('severity', 32)->default('medium');
            $table->timestamp('detected_at')->nullable();
            $table->string('status', 32)->default('open');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['detected_at', 'status']);
        });

        Schema::create('kpi_early_risk_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('alert_date');
            $table->string('status', 32)->default('acknowledged');
            $table->string('message', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['alert_date', 'status']);
        });

        Schema::create('kpi_acceptance_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 64)->default('daily');
            $table->string('status', 32)->default('success');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['run_type', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_acceptance_runs');
        Schema::dropIfExists('kpi_early_risk_alerts');
        Schema::dropIfExists('kpi_quality_issues');
        Schema::dropIfExists('kpi_telegram_report_configs');
        Schema::dropIfExists('kpi_integration_statuses');
        Schema::dropIfExists('kpi_plans');
    }
};
