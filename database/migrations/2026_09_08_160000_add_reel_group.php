<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
        });
        // Standalone history requires reviewed classification, never the current author's group.
    }

    public function down(): void
    {
        throw new RuntimeException('Reel ownership must be preserved; use a forward migration.');
    }
};
