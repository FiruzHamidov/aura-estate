<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deal_pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('crm_deal_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 24)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'slug']);
            $table->index(['pipeline_id', 'sort_order']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_deal_pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('crm_deal_stages')->restrictOnDelete();
            $table->foreignId('primary_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->decimal('expected_company_income', 15, 2)->nullable();
            $table->string('expected_company_income_currency', 3)->default('TJS');
            $table->decimal('expected_agent_commission', 15, 2)->nullable();
            $table->string('expected_agent_commission_currency', 3)->default('TJS');
            $table->decimal('actual_company_income', 15, 2)->nullable();
            $table->string('actual_company_income_currency', 3)->default('TJS');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->string('source')->nullable()->index();
            $table->unsignedInteger('board_position')->default(0);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'pipeline_id']);
            $table->index(['pipeline_id', 'stage_id', 'board_position']);
            $table->index(['responsible_agent_id', 'stage_id']);
            $table->index(['client_id', 'stage_id']);
        });

        $now = now();

        DB::table('crm_deal_pipelines')->insert([
            'name' => 'Основная воронка',
            'slug' => 'default_sales',
            'branch_id' => null,
            'sort_order' => 10,
            'is_default' => true,
            'is_active' => true,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pipelineId = DB::table('crm_deal_pipelines')->where('slug', 'default_sales')->value('id');

        if ($pipelineId) {
            DB::table('crm_deal_stages')->insert([
                ['pipeline_id' => $pipelineId, 'name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'В работе', 'slug' => 'in_progress', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'Показ', 'slug' => 'showing', 'color' => '#0891b2', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'Переговоры', 'slug' => 'negotiation', 'color' => '#7c3aed', 'sort_order' => 40, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'Задаток', 'slug' => 'deposit', 'color' => '#d97706', 'sort_order' => 50, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'Успешно закрыта', 'slug' => 'won', 'color' => '#16a34a', 'sort_order' => 60, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'name' => 'Потеряна', 'slug' => 'lost', 'color' => '#dc2626', 'sort_order' => 70, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_deal_stages');
        Schema::dropIfExists('crm_deal_pipelines');
    }
};
