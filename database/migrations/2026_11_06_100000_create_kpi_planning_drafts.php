<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drafts never enter the result ledger or the motivation scheduler.
        Schema::create('kpi_planning_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('slot', 100)->nullable()->unique();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('month', 7)->nullable()->index();
            $table->json('payload');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedTinyInteger('step')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_planning_drafts');
    }
};
