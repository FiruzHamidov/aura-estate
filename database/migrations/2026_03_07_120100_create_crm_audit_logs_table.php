<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_audit_logs');
    }
};
