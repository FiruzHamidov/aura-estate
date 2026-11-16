<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_moderation_cases', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('property_moderation_cases', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
