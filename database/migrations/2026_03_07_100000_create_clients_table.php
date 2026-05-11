<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->text('note')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['created_by', 'phone_normalized'], 'clients_unique_phone_per_creator');
            $table->unique(['created_by', 'email_normalized'], 'clients_unique_email_per_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
