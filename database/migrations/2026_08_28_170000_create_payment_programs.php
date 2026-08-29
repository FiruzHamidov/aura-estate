<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->nullable()->constrained('new_buildings')->restrictOnDelete();
            $table->string('title');
            $table->string('type', 20);
            $table->string('bank_name')->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->string('scope', 20)->default('all');
            $table->string('calculation_method', 30)->default('manual');
            $table->unsignedSmallInteger('period_months')->nullable();
            $table->unsignedSmallInteger('term_min_months')->nullable();
            $table->unsignedSmallInteger('term_max_months')->nullable();
            $table->decimal('min_down_percent', 5, 2)->nullable();
            $table->decimal('annual_rate', 6, 3)->nullable();
            $table->decimal('upfront_fee_percent', 5, 2)->nullable();
            $table->decimal('upfront_fee_fixed', 15, 2)->nullable();
            $table->decimal('min_principal', 15, 2)->nullable();
            $table->decimal('max_principal', 15, 2)->nullable();
            $table->boolean('fees_verified')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('conditions')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('confirmation_reference', 1000)->nullable();
            $table->timestamp('data_verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('publication_status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['new_building_id', 'publication_status', 'valid_until'], 'payment_programs_public');
        });
        Schema::create('payment_program_blocks', function (Blueprint $table) {
            $table->foreignId('payment_program_id')->constrained('payment_programs')->cascadeOnDelete();
            $table->foreignId('block_id')->constrained('new_building_blocks')->restrictOnDelete();
            $table->primary(['payment_program_id', 'block_id']);
        });
        Schema::create('payment_program_units', function (Blueprint $table) {
            $table->foreignId('payment_program_id')->constrained('payment_programs')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('developer_units')->restrictOnDelete();
            $table->primary(['payment_program_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('payment_programs')->exists()) {
            throw new RuntimeException('Keep verified payment program history; disable the interface instead.');
        }
        Schema::dropIfExists('payment_program_units');
        Schema::dropIfExists('payment_program_blocks');
        Schema::dropIfExists('payment_programs');
    }
};
