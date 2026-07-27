<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('slug');
        });

        Schema::create('feature_property', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_property');

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
