<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewBuildingPlansIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('developers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->unsignedInteger('under_construction_count')->default(0);
            $table->unsignedInteger('built_count')->default(0);
            $table->year('founded_year')->nullable();
            $table->unsignedInteger('total_projects')->default(0);
            $table->string('logo_path')->nullable();
            $table->string('moderation_status')->default('pending');
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('telegram')->nullable();
            $table->timestamps();
        });

        Schema::create('construction_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('new_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('developer_id')->nullable();
            $table->foreignId('construction_stage_id')->nullable();
            $table->foreignId('material_id')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('ceiling_height', 4, 2)->nullable();
            $table->string('moderation_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('developer_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id');
            $table->string('name');
            $table->unsignedTinyInteger('bedrooms')->default(0);
            $table->unsignedTinyInteger('bathrooms')->default(0);
            $table->decimal('area', 10, 2);
            $table->integer('floor')->nullable();
            $table->decimal('price_per_sqm', 15, 2)->nullable();
            $table->decimal('total_price', 15, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('moderation_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('developer_unit_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id');
            $table->string('path');
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function test_it_returns_paginated_public_new_building_plans_in_stable_format(): void
    {
        $developerId = DB::table('developers')->insertGetId([
            'name' => 'Manora Dev',
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stageA = DB::table('construction_stages')->insertGetId([
            'name' => 'Stage A',
            'slug' => 'stage-a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stageB = DB::table('construction_stages')->insertGetId([
            'name' => 'Stage B',
            'slug' => 'stage-b',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $materialId = DB::table('materials')->insertGetId([
            'name' => 'Brick',
            'slug' => 'brick',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherMaterialId = DB::table('materials')->insertGetId([
            'name' => 'Panel',
            'slug' => 'panel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buildingId = DB::table('new_buildings')->insertGetId([
            'title' => 'ЖК Сомон',
            'developer_id' => $developerId,
            'construction_stage_id' => $stageA,
            'material_id' => $materialId,
            'address' => 'Душанбе, Исмоили Сомони',
            'latitude' => 38.56000000,
            'longitude' => 68.78000000,
            'ceiling_height' => 3.20,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherBuildingId = DB::table('new_buildings')->insertGetId([
            'title' => 'ЖК Нури',
            'developer_id' => $developerId,
            'construction_stage_id' => $stageB,
            'material_id' => $otherMaterialId,
            'address' => 'Душанбе, Рудаки',
            'latitude' => 38.57000000,
            'longitude' => 68.79000000,
            'ceiling_height' => 2.80,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstUnitId = DB::table('developer_units')->insertGetId([
            'new_building_id' => $buildingId,
            'name' => 'Планировка 2А',
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area' => 68.50,
            'floor' => 5,
            'price_per_sqm' => 7883.21,
            'total_price' => 540000,
            'is_available' => true,
            'moderation_status' => 'approved',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('developer_unit_photos')->insert([
            'unit_id' => $firstUnitId,
            'path' => 'units/1001/first.jpg',
            'is_cover' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('developer_unit_photos')->insert([
            'unit_id' => $firstUnitId,
            'path' => 'units/1001/cover.jpg',
            'is_cover' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('developer_units')->insert([
            'new_building_id' => $otherBuildingId,
            'name' => 'Планировка 1Б',
            'bedrooms' => 1,
            'bathrooms' => 1,
            'area' => 42.10,
            'floor' => 3,
            'price_per_sqm' => 7125.00,
            'total_price' => 300000,
            'is_available' => true,
            'moderation_status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('developer_units')->insert([
            'new_building_id' => $buildingId,
            'name' => 'Черновик',
            'bedrooms' => 3,
            'bathrooms' => 2,
            'area' => 90.00,
            'floor' => 8,
            'price_per_sqm' => 7000,
            'total_price' => 630000,
            'is_available' => true,
            'moderation_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/new-buildings/plans?developer_id='.$developerId.'&stage_id='.$stageA.'&material_id='.$materialId.'&ceiling_height_min=3&search=Сомон&sort=price&dir=desc&per_page=1');

        $response
            ->assertOk()
            ->assertJson([
                'current_page' => 1,
                'per_page' => 1,
                'last_page' => 1,
                'total' => 1,
            ])
            ->assertJsonPath('data.0.unit_id', $firstUnitId)
            ->assertJsonPath('data.0.building_id', $buildingId)
            ->assertJsonPath('data.0.building_title', 'ЖК Сомон')
            ->assertJsonPath('data.0.building_address', 'Душанбе, Исмоили Сомони')
            ->assertJsonPath('data.0.building_latitude', 38.56)
            ->assertJsonPath('data.0.building_longitude', 68.78)
            ->assertJsonPath('data.0.rooms', 2)
            ->assertJsonPath('data.0.area', 68.5)
            ->assertJsonPath('data.0.price', 540000.0)
            ->assertJsonPath('data.0.currency', 'TJS')
            ->assertJsonPath('data.0.cover_photo', 'units/1001/cover.jpg');
    }

    public function test_it_returns_nulls_for_optional_fields_and_supports_alias_sort_min_price(): void
    {
        $developerId = DB::table('developers')->insertGetId([
            'name' => 'Aura Dev',
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stageId = DB::table('construction_stages')->insertGetId([
            'name' => 'Stage',
            'slug' => 'stage',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $materialId = DB::table('materials')->insertGetId([
            'name' => 'Concrete',
            'slug' => 'concrete',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buildingId = DB::table('new_buildings')->insertGetId([
            'title' => 'ЖК Карта',
            'developer_id' => $developerId,
            'construction_stage_id' => $stageId,
            'material_id' => $materialId,
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'ceiling_height' => null,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('developer_units')->insert([
            'new_building_id' => $buildingId,
            'name' => 'Планировка Null',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'area' => 25.00,
            'floor' => 1,
            'price_per_sqm' => null,
            'total_price' => null,
            'is_available' => true,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/new-buildings/plans?sort=min_price&dir=asc');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.building_address', null)
            ->assertJsonPath('data.0.building_latitude', null)
            ->assertJsonPath('data.0.building_longitude', null)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.currency', 'TJS')
            ->assertJsonPath('data.0.cover_photo', null);
    }
}
