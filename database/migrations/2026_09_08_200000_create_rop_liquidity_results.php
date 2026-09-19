<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rop_liquidity_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rop_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('access_scope_version');
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('branch_group_id')->constrained('branch_groups')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('category', 32);
            $table->unsignedTinyInteger('confidence_score');
            $table->string('price_position', 32);
            $table->decimal('promotion_priority_score', 10, 2)->nullable();
            $table->string('promotion_eligibility', 32)->nullable();
            $table->json('snapshot');
            $table->timestamp('calculated_at');
            $table->unique(['rop_id', 'property_id']);
            $table->index(['rop_id', 'access_scope_version', 'score'], 'rop_liquidity_scope_score');
            $table->index(['rop_id', 'access_scope_version', 'category'], 'rop_liquidity_scope_category');
        });
    }

    public function down(): void
    {
        // Derived cache only; no original records, assignments or historical classifications live here.
        Schema::dropIfExists('rop_liquidity_results');
    }
};
