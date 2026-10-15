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
            $table->unsignedBigInteger('created_by')->nullable()->index();
        });
        // Keep a fallback for legacy records without creation audit evidence.
        DB::table('bookings')->update(['created_by' => DB::raw('agent_id')]);
        if (Schema::hasTable('crm_audit_logs') && Schema::hasColumn('crm_audit_logs', 'context')) {
            DB::table('crm_audit_logs')->where('event', 'booking_created')->whereNotNull('actor_id')
                ->select(['id', 'actor_id', 'context'])->chunkById(500, function ($logs) {
                    foreach ($logs as $log) {
                        $context = json_decode((string) $log->context, true);
                        if (! empty($context['booking_id'])) {
                            DB::table('bookings')->where('id', (int) $context['booking_id'])
                                ->update(['created_by' => $log->actor_id]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('created_by'));
    }
};
