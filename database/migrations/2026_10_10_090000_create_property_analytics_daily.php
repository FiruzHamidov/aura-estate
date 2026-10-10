<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_analytics_daily', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->date('day');
            foreach (['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->primary(['property_id', 'day']);
            $table->index('day');
        });
        Schema::create('property_analytics_coverage', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('started_at');
        });
        DB::table('property_analytics_coverage')->insert(['id' => 1, 'started_at' => now('Asia/Dushanbe')->toIso8601String()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('property_analytics_daily');
        Schema::dropIfExists('property_analytics_coverage');
    }
};
