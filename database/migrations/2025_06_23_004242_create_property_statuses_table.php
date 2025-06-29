<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // например: Доступен, Продан, Арендован
            $table->string('slug')->unique(); // например: available, sold, rented
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_statuses');
    }
};
