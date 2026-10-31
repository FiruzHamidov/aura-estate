<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: status_id has two historical meanings and requires review.
        Schema::table('properties', function (Blueprint $table): void {
            $table->foreignId('building_type_id')->nullable()->constrained('building_types')->restrictOnDelete();
            $table->timestamp('building_type_resolved_at')->nullable();
        });
        Schema::create('property_publication_repair_batches', function (Blueprint $table): void {
            $table->uuid('batch_id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->char('manifest_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('property_publication_repairs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id');
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->char('manifest_hash', 64);
            $table->json('before_state');
            $table->json('changes');
            $table->char('after_fingerprint', 64);
            $table->timestamp('created_at');
            $table->timestamp('rolled_back_at')->nullable();
            $table->unsignedBigInteger('rollback_actor_id')->nullable();
            $table->unique(['batch_id', 'property_id']);
            $table->foreign('batch_id')->references('batch_id')->on('property_publication_repair_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Reviewed types and repair receipts are business/audit data. Use the
        // guarded repair rollback command, never drop them during a code rollback.
        throw new RuntimeException('This additive migration retains reviewed building types and repair history. Roll back application code without dropping these columns.');
    }
};
