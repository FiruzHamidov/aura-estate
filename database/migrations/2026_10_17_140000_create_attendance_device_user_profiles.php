<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_device_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('device_user_id', 100);
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'device_user_id'], 'attendance_device_profile_identity');
        });

        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->timestamp('user_info_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropColumn('user_info_requested_at');
        });
        Schema::dropIfExists('attendance_device_user_profiles');
    }
};
