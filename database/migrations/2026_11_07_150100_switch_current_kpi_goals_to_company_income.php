<?php

use App\Services\Kpi\ResultAmountUpgrade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The owner explicitly approved converting November 2026 targets with
        // unchanged thresholds. Never reinterpret a later month on a delayed rollout.
        if (CarbonImmutable::now('Asia/Dushanbe')->format('Y-m') === '2026-11') {
            app(ResultAmountUpgrade::class)->run('2026-11');
        }
    }

    public function down(): void
    {
        // Revisions are an audit trail. Rolling back a release must not erase them.
    }
};
