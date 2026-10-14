<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent receipts retain provenance; unknown historical assignments stay marked.
        if (Artisan::call('marketing:backfill-leads') !== 0) {
            throw new RuntimeException('Marketing receipt backfill failed.');
        }
    }

    public function down(): void
    {
        // Preserve attribution history and subsequent manual reviews.
    }
};
