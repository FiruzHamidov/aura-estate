<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->enum('currency', ['TJS', 'USD'])->default('TJS');
            $table->float('total_area')->nullable();
            $table->float('living_area')->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->year('year_built')->nullable();
            $table->string('condition')->nullable(); // новостройка, требует ремонта и т.д.
            $table->boolean('has_garden')->default(false);
            $table->boolean('has_parking')->default(false);
            $table->string('apartment_type')->nullable(); // Студия, 1-комн, 2-комн и т.д.
            $table->string('repair_type')->nullable(); // Евроремонт, Капремонт и т.д.
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->enum('moderation_status', ['pending', 'approved', 'rejected', 'draft', 'deleted'])->default('pending');
            $table->string('landmark')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
