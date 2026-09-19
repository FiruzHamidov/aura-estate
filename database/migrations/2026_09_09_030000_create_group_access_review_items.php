<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_access_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 80);
            $table->unsignedBigInteger('source_id');
            // No cascading FK: deleting a source must not silently erase review history.
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('reason', 100);
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['source_table', 'source_id', 'reason'], 'group_access_review_source_unique');
        });
    }

    public function down(): void
    {
        // Classification/review history survives an application rollback.
    }
};
