<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->string('mortgage_availability_status', 20)->default('unknown'));
    }

    public function down(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('mortgage_availability_status'));
    }
};
