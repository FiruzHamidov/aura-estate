<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_location_tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('tracking_enabled')->default(true);
            $table->string('mode', 20)->default('work_schedule');
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->json('schedule')->nullable();
            $table->unsignedInteger('foreground_interval_sec')->default(30);
            $table->unsignedInteger('background_interval_sec')->default(120);
            $table->unsignedInteger('min_distance_m')->default(75);
            $table->unsignedInteger('history_retention_days')->default(90);
            $table->boolean('require_background_permission')->default(true);
            $table->unsignedBigInteger('policy_version')->default(1);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('user_location_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('device_uuid');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->string('permission_status', 32)->default('not_determined');
            $table->boolean('background_permission')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('last_policy_version')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::create('user_location_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('user_location_devices')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2);
            $table->decimal('altitude_m', 9, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->decimal('heading_deg', 6, 2)->nullable();
            $table->string('source', 32)->default('unknown');
            $table->string('app_state', 16)->default('foreground');
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('is_mocked')->nullable();
            $table->string('quality', 16);
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['device_id', 'event_id']);
            $table->index(['user_id', 'captured_at']);
            $table->index(['branch_id', 'captured_at']);
            $table->index(['branch_group_id', 'captured_at']);
        });

        Schema::create('user_current_locations', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignId('location_point_id')->constrained('user_location_points')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2);
            $table->string('quality', 16);
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('user_location_view_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('mode', 20)->default('all_available');
            $table->json('filters')->nullable();
            $table->timestamps();
        });

        Schema::create('user_location_watchlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['viewer_user_id', 'target_user_id']);
        });

        Schema::create('user_location_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('user_location_devices')->nullOnDelete();
            $table->string('event', 64);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::create('user_location_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('succeeded')->default(true);
            $table->timestamps();
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_audit_logs');
        Schema::dropIfExists('user_location_status_events');
        Schema::dropIfExists('user_location_watchlist');
        Schema::dropIfExists('user_location_view_preferences');
        Schema::dropIfExists('user_current_locations');
        Schema::dropIfExists('user_location_points');
        Schema::dropIfExists('user_location_devices');
        Schema::dropIfExists('user_location_tracking_settings');
    }
};
