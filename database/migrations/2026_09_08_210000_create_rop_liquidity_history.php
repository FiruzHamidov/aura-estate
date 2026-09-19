<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rop_liquidity_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rop_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('access_scope_version');
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('branch_group_id')->constrained('branch_groups')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->decimal('price_delta_pct', 8, 2);
            $table->string('price_position', 32);
            $table->unsignedTinyInteger('confidence_score');
            $table->json('snapshot');
            $table->timestamp('calculated_at');
            $table->index(['rop_id', 'access_scope_version', 'property_id', 'calculated_at'], 'rop_liquidity_history_scope');
        });
        // Existing global calculations have no reconstructable scope; do not relabel them.
    }

    public function down(): void
    {
        throw new RuntimeException('Scoped calculation history must be preserved; use a forward migration.');
    }
};
