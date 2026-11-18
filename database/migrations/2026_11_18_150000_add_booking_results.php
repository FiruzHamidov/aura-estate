<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
            $table->string('outcome', 32)->nullable();
            $table->string('result_reason', 64)->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('result_recorded_at')->nullable();
            $table->foreignId('result_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('result_version')->default(0);
            $table->foreignId('follow_up_task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();
            $table->index(['status', 'end_time'], 'bookings_pending_result_index');
        });
    }

    public function down(): void
    {
        DB::table('bookings')->where('status', 'completed')->update(['status' => 'confirmed']);
        DB::table('bookings')->where('status', 'not_held')->update(['status' => 'cancelled']);
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_pending_result_index');
            $table->dropConstrainedForeignId('result_recorded_by');
            $table->dropConstrainedForeignId('follow_up_task_id');
            $table->dropColumn(['outcome', 'result_reason', 'result_note', 'result_recorded_at', 'result_version']);
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending')->change();
        });
    }
};
