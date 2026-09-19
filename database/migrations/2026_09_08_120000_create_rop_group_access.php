<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('access_scope_version')->default(0);
        });

        Schema::create('rop_branch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rop_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_group_id')->constrained('branch_groups')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['rop_id', 'branch_group_id']);
            $table->index(['branch_group_id', 'rop_id']);
        });

        // Audit identifiers deliberately survive deletion of their subjects.
        Schema::create('group_access_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 80);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('reason')->nullable();
            $table->string('trace_id', 100)->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'group_access_audit_subject');
        });

        foreach (['leads', 'crm_deals', 'bookings', 'crm_tasks', 'daily_reports', 'attendance_daily_summaries'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'branch_group_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
                });
            }
        }
        if (Schema::hasTable('properties') && ! Schema::hasColumn('properties', 'branch_id')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->index(['branch_id', 'branch_group_id', 'moderation_status'], 'properties_group_access');
            });
        }
        // No automatic grants or historical backfill from employees' current groups.
    }

    public function down(): void
    {
        throw new RuntimeException('Group access data must be preserved. Use a forward migration; do not restore branch-wide ROP access.');
    }
};
