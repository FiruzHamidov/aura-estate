<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_catalog_merge_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('catalog', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('source_label');
            $table->unsignedBigInteger('replacement_id');
            $table->string('replacement_label');
            $table->unsignedBigInteger('reassigned_count')->default(0);
            $table->json('usage');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['catalog', 'source_id']);
            $table->index(['catalog', 'replacement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_catalog_merge_audits');
    }
};
