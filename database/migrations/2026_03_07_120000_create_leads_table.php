<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->text('note')->nullable();
            $table->string('source')->nullable()->index();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('status', 40)->default('new')->index();
            $table->timestamp('first_contact_due_at')->nullable()->index();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('converted_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->text('lost_reason')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['responsible_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
