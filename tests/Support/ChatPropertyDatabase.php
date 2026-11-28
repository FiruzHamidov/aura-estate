<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ChatPropertyDatabase
{
    protected function createChatPropertyDatabase(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->travelTo(now()->startOfSecond());
        Schema::dropAllTables();
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Квартира');
            $table->string('offer_type')->default('sale');
            $table->unsignedBigInteger('type_id')->default(1);
            $table->unsignedBigInteger('location_id')->default(1);
            $table->unsignedBigInteger('status_id')->default(1);
            $table->integer('rooms')->default(2);
            $table->decimal('price')->default(500000);
            $table->decimal('discount_price')->nullable();
            $table->string('currency')->default('TJS');
            $table->string('address')->default('Рудаки');
            $table->string('district')->default('Сино');
            $table->string('listing_type')->default('regular');
            $table->string('moderation_status')->default('approved');
            $table->string('publication_status')->default('published');
            $table->string('deal_status')->default('available');
            $table->timestamp('sold_at')->nullable();
            $table->integer('liquidity_score')->nullable();
            $table->integer('liquidity_confidence')->nullable();
            $table->string('price_position')->nullable();
            $table->string('liquidity_model_version')->nullable();
            $table->timestamp('liquidity_calculated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('city');
        });
        DB::table('locations')->insert([['id' => 1, 'city' => 'Душанбе'], ['id' => 2, 'city' => 'Худжанд']]);
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        DB::table('property_types')->insert([
            ['id' => 1, 'name' => 'Квартира', 'slug' => 'apartment'],
            ['id' => 2, 'name' => 'Дом', 'slug' => 'house'],
        ]);
        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
        });
        DB::table('property_statuses')->insert([['id' => 1, 'slug' => 'available'], ['id' => 2, 'slug' => 'sold']]);
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->integer('position');
        });
        Schema::create('property_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('type');
            $table->string('status');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
        });
        Schema::create('property_moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('status');
            $table->boolean('blocking');
        });
        Schema::create('property_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderation_case_id');
            $table->string('decision');
            $table->timestamp('reversed_at')->nullable();
        });
    }
}
