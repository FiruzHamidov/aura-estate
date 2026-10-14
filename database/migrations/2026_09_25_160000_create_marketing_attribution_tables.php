<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_lead_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lead_id')->unique();
            $t->string('phone_key', 50)->nullable()->index();
            $t->string('source', 100)->nullable();
            $t->string('campaign', 255)->nullable();
            $t->string('classification', 32);
            $t->boolean('returning_confirmed')->default(false);
            $t->text('classification_reason')->nullable();
            $t->boolean('historical')->default(false);
            $t->timestamp('received_at')->index();
            $t->timestamps();
        });
        Schema::create('marketing_lead_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lead_id')->index();
            $t->unsignedBigInteger('agent_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamp('assigned_at');
            $t->timestamp('ended_at')->nullable();
            $t->boolean('historical')->default(false);
        });
        Schema::create('marketing_sale_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id')->unique();
            $t->unsignedBigInteger('lead_id')->index();
            $t->string('decision', 16);
            $t->text('reason');
            $t->unsignedBigInteger('reviewed_by');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_sale_reviews');
        Schema::dropIfExists('marketing_lead_assignments');
        Schema::dropIfExists('marketing_lead_receipts');
    }
};
