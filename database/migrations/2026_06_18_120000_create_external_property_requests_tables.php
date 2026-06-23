<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('external_property_requests')) {
            Schema::create('external_property_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('external_agent_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
                $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
                $table->foreignId('owner_client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->string('status', 40)->default('submitted');
                $table->enum('offer_type', ['rent', 'sale'])->nullable();
                $table->foreignId('type_id')->nullable()->constrained('property_types')->nullOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
                $table->string('district')->nullable();
                $table->string('address')->nullable();
                $table->string('landmark')->nullable();
                $table->decimal('price', 15, 2)->nullable();
                $table->enum('currency', ['TJS', 'USD'])->nullable();
                $table->unsignedTinyInteger('rooms')->nullable();
                $table->decimal('total_area', 10, 2)->nullable();
                $table->decimal('living_area', 10, 2)->nullable();
                $table->decimal('land_size', 10, 2)->nullable();
                $table->integer('floor')->nullable();
                $table->integer('total_floors')->nullable();
                $table->foreignId('repair_type_id')->nullable()->constrained('repair_types')->nullOnDelete();
                $table->string('condition')->nullable();
                $table->string('owner_name')->nullable();
                $table->string('owner_phone', 40)->nullable();
                $table->string('owner_phone_normalized', 40)->nullable()->index();
                $table->text('external_comment')->nullable();
                $table->text('internal_comment')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('needs_info_comment')->nullable();
                $table->foreignId('duplicate_property_id')->nullable()->constrained('properties')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->json('meta')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['external_agent_id', 'status']);
                $table->index(['assigned_agent_id', 'status']);
                $table->index(['branch_id', 'status']);
                $table->index(['branch_group_id', 'status']);
                $table->index('property_id');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('external_property_request_photos')) {
            Schema::create('external_property_request_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('external_property_request_id');
                $table->string('file_path');
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->foreign('external_property_request_id', 'epr_photos_request_fk')
                    ->references('id')
                    ->on('external_property_requests')
                    ->cascadeOnDelete();
                $table->index(['external_property_request_id', 'position'], 'epr_photos_request_position_idx');
            });
        }

        if (! Schema::hasTable('external_property_request_logs')) {
            Schema::create('external_property_request_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('external_property_request_id');
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 80);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->text('comment')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('external_property_request_id', 'epr_logs_request_fk')
                    ->references('id')
                    ->on('external_property_requests')
                    ->cascadeOnDelete();
                $table->index(['external_property_request_id', 'created_at'], 'external_request_logs_request_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_property_request_logs');
        Schema::dropIfExists('external_property_request_photos');
        Schema::dropIfExists('external_property_requests');
    }
};
