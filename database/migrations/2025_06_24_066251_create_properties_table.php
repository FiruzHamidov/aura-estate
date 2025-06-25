<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->enum('currency', ['TJS', 'USD'])->default('TJS');
            $table->enum('offer_type', ['rent', 'sale'])->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->string('youtube_link')->nullable();
            $table->float('total_area')->nullable();
            $table->float('living_area')->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->year('year_built')->nullable();
            $table->string('condition')->nullable();
            $table->boolean('has_garden')->default(false);
            $table->boolean('has_parking')->default(false);
            $table->string('apartment_type')->nullable();
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->enum('moderation_status', ['pending', 'approved', 'rejected', 'draft', 'deleted'])->default('pending');
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('type_id')->references('id')->on('property_types');
            $table->foreign('status_id')->references('id')->on('property_statuses');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('repair_type_id')->references('id')->on('repair_types');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
