<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('serial_number', 100)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->string('protocol', 32)->default('ta_push');
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->string('firmware_version', 100)->nullable();
            $table->string('platform', 50)->default('ZAM230');
            $table->string('device_model', 100)->default('SpeedFace-V5L-RFID');
            $table->text('communication_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->integer('clock_drift_seconds')->nullable();
            $table->timestamp('offline_notified_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'is_active']);
            $table->index(['branch_group_id', 'is_active']);
            $table->index('last_seen_at');
        });

        Schema::create('attendance_device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('device_user_id', 100);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('card_number', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'device_user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('attendance_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->json('schedule');
            $table->json('holidays')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_ingest_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('payload_hash', 64);
            $table->longText('raw_payload');
            $table->json('request_meta')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('received_at');
            $table->string('processing_status', 32)->default('received');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('unmapped_count')->default(0);
            $table->json('rejected_rows')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'received_at']);
            $table->index(['payload_hash', 'received_at']);
        });

        Schema::create('attendance_raw_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->foreignId('ingest_request_id')->nullable()->constrained('attendance_ingest_requests')->nullOnDelete();
            $table->string('event_hash', 64)->unique();
            $table->string('device_user_id', 100);
            $table->dateTime('occurred_at_local');
            $table->timestamp('occurred_at_utc');
            $table->string('attendance_status', 32)->nullable();
            $table->string('verify_mode', 32)->nullable();
            $table->string('work_code', 64)->nullable();
            $table->text('raw_payload');
            $table->json('request_meta')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('received_at');
            $table->string('processing_status', 32)->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'occurred_at_utc']);
            $table->index(['device_user_id', 'occurred_at_utc']);
            $table->index(['processing_status', 'received_at']);
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_event_id')->nullable()->unique()->constrained('attendance_raw_events')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->string('device_user_id', 100);
            $table->string('event_type', 32)->default('punch');
            $table->timestamp('occurred_at');
            $table->string('verification_method', 32)->default('unknown');
            $table->string('direction', 16)->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['branch_group_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
        });

        Schema::create('attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->foreignId('first_event_id')->nullable()->constrained('attendance_events')->nullOnDelete();
            $table->foreignId('last_event_id')->nullable()->constrained('attendance_events')->nullOnDelete();
            $table->unsignedInteger('events_count')->default(0);
            $table->json('device_ids')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('status', 32)->default('incomplete');
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
        Schema::dropIfExists('attendance_daily_summaries');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_raw_events');
        Schema::dropIfExists('attendance_ingest_requests');
        Schema::dropIfExists('attendance_work_schedules');
        Schema::dropIfExists('attendance_device_users');
        Schema::dropIfExists('attendance_devices');
    }
};
