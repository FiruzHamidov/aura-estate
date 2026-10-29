<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make the existing author fallback explicit, including closed listings.
        // Never replace a transferred responsibility or assign work to a deleted
        // or inactive account. Query Builder preserves listing dates and history.
        DB::table('properties')
            ->whereNull('agent_id')
            ->whereIn('created_by', DB::table('users')->select('id')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereNull('deletion_requested_at'))
            ->update(['agent_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        // Responsibility may have changed since the backfill; keep assignments.
    }
};
