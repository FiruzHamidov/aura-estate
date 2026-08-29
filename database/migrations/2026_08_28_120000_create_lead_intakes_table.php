<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_intakes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_hash', 64)->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_intakes');
    }
};
