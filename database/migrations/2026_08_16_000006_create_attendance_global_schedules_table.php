<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_global_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('timezone', 64)->default('Asia/Dushanbe');
            $table->json('schedule');
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_global_schedules');
    }
};
