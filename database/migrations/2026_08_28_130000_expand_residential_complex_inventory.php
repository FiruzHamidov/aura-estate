<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable new statuses intentionally distinguish legacy rows from reviewed inventory.
        // No old status, price, room count or publication decision is overwritten here.
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->string('publication_status', 20)->nullable()->index();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('data_verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('housing_class', 80)->nullable();
            $table->json('advantages')->nullable();
            $table->string('parking', 255)->nullable();
            $table->string('completion_precision', 10)->default('unknown');
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->unsignedTinyInteger('completion_quarter')->nullable();
        });
        Schema::table('new_building_blocks', function (Blueprint $table) {
            $table->string('code', 50)->nullable();
            $table->string('completion_precision', 10)->default('unknown');
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->unsignedTinyInteger('completion_quarter')->nullable();
            $table->foreignId('construction_stage_id')->nullable()->constrained('construction_stages')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('new_building_entrances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->restrictOnDelete();
            $table->foreignId('block_id')->constrained('new_building_blocks')->restrictOnDelete();
            $table->string('name', 100);
            $table->smallInteger('residential_floor_from');
            $table->smallInteger('residential_floor_to');
            $table->json('technical_floors')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['block_id', 'name']);
        });
        Schema::create('unit_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->restrictOnDelete();
            $table->string('code', 100);
            $table->unsignedTinyInteger('rooms')->nullable(); // 0 is explicitly confirmed studio, null is unknown.
            $table->decimal('typical_area', 10, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['new_building_id', 'code']);
        });
        Schema::table('developer_units', function (Blueprint $table) {
            $table->foreignId('entrance_id')->nullable()->constrained('new_building_entrances')->restrictOnDelete();
            $table->foreignId('layout_id')->nullable()->constrained('unit_layouts')->restrictOnDelete();
            $table->string('number', 50)->nullable();
            $table->unsignedSmallInteger('position_on_floor')->nullable();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
            $table->decimal('kitchen_area', 10, 2)->nullable();
            $table->decimal('ceiling_height', 4, 2)->nullable();
            $table->string('finishing', 100)->nullable();
            $table->string('publication_status', 20)->nullable();
            $table->string('availability_status', 20)->nullable();
            $table->string('pricing_basis', 10)->nullable();
            $table->boolean('price_on_request')->default(false);
            $table->char('currency', 3)->default('TJS');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('data_verified_at')->nullable();
            $table->string('external_id', 100)->nullable();
            $table->unique(['entrance_id', 'number'], 'units_entrance_number_unique');
            $table->unique(['entrance_id', 'floor', 'position_on_floor'], 'units_floor_position_unique');
            $table->unique(['new_building_id', 'external_id'], 'units_external_id_unique');
            $table->index(['new_building_id', 'publication_status', 'availability_status'], 'units_public_inventory');
            $table->index(['new_building_id', 'rooms', 'total_price', 'area'], 'units_search');
        });
        Schema::create('building_floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_building_id')->constrained('new_buildings')->restrictOnDelete();
            $table->foreignId('block_id')->constrained('new_building_blocks')->restrictOnDelete();
            $table->foreignId('entrance_id')->constrained('new_building_entrances')->restrictOnDelete();
            $table->smallInteger('floor_from');
            $table->smallInteger('floor_to');
            $table->string('image_path')->nullable();
            $table->string('alt')->nullable();
            $table->json('unit_regions')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('residential_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->json('original_values');
            $table->json('issues');
            $table->timestamp('created_at');
            $table->unique(['batch_id', 'entity_type', 'entity_id'], 'inventory_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residential_inventory_snapshots');
        Schema::dropIfExists('building_floor_plans');
        Schema::table('developer_units', function (Blueprint $table) {
            $table->dropForeign(['entrance_id']);
            $table->dropForeign(['layout_id']);
            $table->dropUnique('units_entrance_number_unique');
            $table->dropUnique('units_floor_position_unique');
            $table->dropUnique('units_external_id_unique');
            $table->dropIndex('units_public_inventory');
            $table->dropIndex('units_search');
            $table->dropColumn(['entrance_id', 'layout_id', 'number', 'position_on_floor', 'rooms', 'living_area', 'kitchen_area', 'ceiling_height', 'finishing', 'publication_status', 'availability_status', 'pricing_basis', 'price_on_request', 'currency', 'version', 'data_verified_at', 'external_id']);
        });
        Schema::dropIfExists('unit_layouts');
        Schema::dropIfExists('new_building_entrances');
        Schema::table('new_building_blocks', function (Blueprint $table) {
            $table->dropForeign(['construction_stage_id']);
            $table->dropColumn(['code', 'completion_precision', 'completion_year', 'completion_quarter', 'construction_stage_id', 'sort_order', 'archived_at', 'version']);
        });
        Schema::table('new_buildings', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['responsible_agent_id']);
            $table->dropIndex(['publication_status']);
            $table->dropColumn(['publication_status', 'branch_id', 'responsible_agent_id', 'data_verified_at', 'published_at', 'version', 'housing_class', 'advantages', 'parking', 'completion_precision', 'completion_year', 'completion_quarter']);
        });
    }
};
