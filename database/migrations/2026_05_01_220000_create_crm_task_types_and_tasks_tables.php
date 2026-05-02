<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->string('group', 64)->default('kpi');
            $table->boolean('is_kpi')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_type_id')->constrained('crm_task_types')->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('new')->index();
            $table->string('result_code', 64)->nullable();
            $table->string('related_entity_type', 32)->nullable()->index();
            $table->unsignedBigInteger('related_entity_id')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->index(['assignee_id', 'completed_at']);
            $table->index(['task_type_id', 'status']);
        });

        $now = now();

        DB::table('crm_task_types')->insert([
            ['code' => 'CALL', 'name' => 'Звонок', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LEAD_ACCEPT', 'name' => 'Кабул', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MEETING_OFFICE', 'name' => 'Встреча в офисе', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SHOWING', 'name' => 'Показ', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DEPOSIT', 'name' => 'Залог', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DEAL_CLOSED', 'name' => 'Сделка', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AD_PUBLICATION', 'name' => 'Публикация рекламы', 'group' => 'kpi', 'is_kpi' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FOLLOW_UP', 'name' => 'Фоллоу-ап', 'group' => 'crm', 'is_kpi' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DOCUMENT_REQUEST', 'name' => 'Запрос документов', 'group' => 'crm', 'is_kpi' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AD_CREATE', 'name' => 'Создание объявления', 'group' => 'ads', 'is_kpi' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SHOWING_PLAN', 'name' => 'План показа', 'group' => 'showing', 'is_kpi' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_task_types');
    }
};
