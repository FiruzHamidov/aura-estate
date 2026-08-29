<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residential_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('mode', 20);
            $table->string('source_name')->nullable();
            $table->string('status', 20)->default('preview');
            $table->unsignedInteger('building_version');
            $table->json('rows');
            $table->json('report');
            $table->json('counts');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['new_building_id', 'actor_id', 'created_at'], 'residential_import_history');
        });
    }

    public function down(): void
    {
        if (DB::table('residential_import_batches')->exists()) {
            throw new RuntimeException('Retain or export the import audit trail before reverting its schema.');
        }
        Schema::dropIfExists('residential_import_batches');
    }
};
