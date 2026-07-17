<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->index(['report_date', 'user_id'], 'daily_reports_date_user_kpi_idx');
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->index(['assignee_id', 'status', 'completed_at'], 'crm_tasks_kpi_assignee_status_completed_idx');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['agent_id', 'start_time'], 'bookings_kpi_agent_start_idx');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['created_by', 'created_at'], 'clients_kpi_creator_created_idx');
            $table->index(['responsible_agent_id', 'created_at'], 'clients_kpi_agent_created_idx');
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->index(['created_by', 'created_at'], 'properties_kpi_creator_created_idx');
            $table->index(['agent_id', 'created_at'], 'properties_kpi_agent_created_idx');
            $table->index(['moderation_status', 'sold_at'], 'properties_kpi_status_sold_idx');
        });
        Schema::table('kpi_plans', function (Blueprint $table) {
            $table->index(['user_id', 'effective_from', 'effective_to'], 'kpi_plans_user_period_idx');
            $table->index(['role_slug', 'branch_id', 'branch_group_id'], 'kpi_plans_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_plans', function (Blueprint $table) {
            $table->dropIndex('kpi_plans_user_period_idx');
            $table->dropIndex('kpi_plans_scope_idx');
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_kpi_creator_created_idx');
            $table->dropIndex('properties_kpi_agent_created_idx');
            $table->dropIndex('properties_kpi_status_sold_idx');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_kpi_creator_created_idx');
            $table->dropIndex('clients_kpi_agent_created_idx');
        });
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_kpi_agent_start_idx'));
        Schema::table('crm_tasks', fn (Blueprint $table) => $table->dropIndex('crm_tasks_kpi_assignee_status_completed_idx'));
        Schema::table('daily_reports', fn (Blueprint $table) => $table->dropIndex('daily_reports_date_user_kpi_idx'));
    }
};
