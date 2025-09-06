<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('developer_unit_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('developer_units')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_unit_photos');
    }
};
