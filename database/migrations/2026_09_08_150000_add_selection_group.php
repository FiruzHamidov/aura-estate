<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->restrictOnDelete();
        });
        // Historical selections require classification; never infer from today's author group.
    }

    public function down(): void
    {
        throw new RuntimeException('Selection ownership must be preserved; use a forward migration.');
    }
};
