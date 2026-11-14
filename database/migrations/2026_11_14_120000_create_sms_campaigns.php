<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->unique(['created_by', 'request_id']);
            $table->string('preview_token', 64);
            $table->string('name', 120);
            $table->text('message');
            $table->string('transport', 12);
            $table->json('segment');
            $table->timestamp('scheduled_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sms_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->string('phone', 16);
            $table->unique(['campaign_id', 'phone']);
            $table->string('status', 16)->default('pending');
            $table->index(['campaign_id', 'status']);
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('bulk_name')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('next_action_at')->nullable()->index();
        });
        Schema::create('sms_campaign_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->unique();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaign_exclusions');
        Schema::dropIfExists('sms_campaign_recipients');
        Schema::dropIfExists('sms_campaigns');
    }
};
