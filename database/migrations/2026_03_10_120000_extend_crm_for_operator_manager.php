<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROPERTY_CONTROL_PIPELINE_CODE = 'property_control';

    public function up(): void
    {
        Schema::table('crm_deal_pipelines', function (Blueprint $table) {
            $table->string('code')->nullable()->after('slug');
            $table->string('type')->default('sales')->after('code');
            $table->index('type');
            $table->unique(['branch_id', 'code']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('meta');
            $table->string('last_contact_result', 100)->nullable()->after('tags');
            $table->timestamp('next_follow_up_at')->nullable()->after('last_contact_result');
            $table->timestamp('next_activity_at')->nullable()->after('next_follow_up_at');
            $table->foreignId('updated_by')->nullable()->after('next_activity_at')->constrained('users')->nullOnDelete();

            $table->index(['responsible_agent_id', 'next_follow_up_at']);
            $table->index(['responsible_agent_id', 'next_activity_at']);
        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->text('note')->nullable()->after('meta');
            $table->json('tags')->nullable()->after('note');
            $table->string('last_contact_result', 100)->nullable()->after('tags');
            $table->timestamp('next_activity_at')->nullable()->after('last_contact_result');
            $table->string('source_property_status', 40)->nullable()->after('next_activity_at');
            $table->foreignId('updated_by')->nullable()->after('source_property_status')->constrained('users')->nullOnDelete();

            $table->index(['responsible_agent_id', 'next_activity_at']);
            $table->index(['primary_property_id', 'source_property_status']);
        });

        Schema::table('crm_audit_logs', function (Blueprint $table) {
            $table->index(['actor_id', 'event', 'created_at']);
            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'crm_audit_logs_auditable_created_idx');
        });

        DB::statement('UPDATE crm_deal_pipelines SET code = slug WHERE code IS NULL');

        $this->seedPropertyControlPipelines();
    }

    public function down(): void
    {
        Schema::table('crm_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_id', 'event', 'created_at']);
            $table->dropIndex('crm_audit_logs_auditable_created_idx');
        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropIndex(['responsible_agent_id', 'next_activity_at']);
            $table->dropIndex(['primary_property_id', 'source_property_status']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'note',
                'tags',
                'last_contact_result',
                'next_activity_at',
                'source_property_status',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['responsible_agent_id', 'next_follow_up_at']);
            $table->dropIndex(['responsible_agent_id', 'next_activity_at']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'tags',
                'last_contact_result',
                'next_follow_up_at',
                'next_activity_at',
            ]);
        });

        DB::table('crm_deal_pipelines')
            ->where('code', self::PROPERTY_CONTROL_PIPELINE_CODE)
            ->delete();

        Schema::table('crm_deal_pipelines', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'code']);
            $table->dropIndex(['type']);
            $table->dropColumn(['code', 'type']);
        });
    }

    private function seedPropertyControlPipelines(): void
    {
        $branches = DB::table('branches')->select('id')->get();
        $now = now();

        foreach ($branches as $branch) {
            $existingPipelineId = DB::table('crm_deal_pipelines')
                ->where('branch_id', $branch->id)
                ->where('code', self::PROPERTY_CONTROL_PIPELINE_CODE)
                ->value('id');

            if ($existingPipelineId) {
                continue;
            }

            $sortOrder = (int) DB::table('crm_deal_pipelines')
                ->where('branch_id', $branch->id)
                ->max('sort_order') + 10;

            $pipelineId = DB::table('crm_deal_pipelines')->insertGetId([
                'name' => 'Контроль объектов',
                'slug' => self::PROPERTY_CONTROL_PIPELINE_CODE.'_branch_'.$branch->id,
                'code' => self::PROPERTY_CONTROL_PIPELINE_CODE,
                'type' => 'property_control',
                'branch_id' => $branch->id,
                'sort_order' => $sortOrder,
                'is_default' => false,
                'is_active' => true,
                'meta' => json_encode([
                    'system_managed' => true,
                    'role_scope' => 'manager',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('crm_deal_stages')->insert($this->propertyControlStages($pipelineId, $now));
        }
    }

    private function propertyControlStages(int $pipelineId, $now): array
    {
        return [
            $this->stageRow($pipelineId, 'Новая', 'new', '#64748b', 10, true, false, false, $now),
            $this->stageRow($pipelineId, 'На проверке', 'in_review', '#2563eb', 20, false, false, false, $now),
            $this->stageRow($pipelineId, 'Связались', 'contacted', '#0891b2', 30, false, false, false, $now),
            $this->stageRow($pipelineId, 'Ждём владельца', 'waiting_owner', '#f59e0b', 40, false, false, false, $now),
            $this->stageRow($pipelineId, 'Возврат в работу', 'reactivation_in_progress', '#8b5cf6', 50, false, false, false, $now),
            $this->stageRow($pipelineId, 'Реактивирован', 'reactivated', '#16a34a', 60, false, true, false, $now),
            $this->stageRow($pipelineId, 'Продан владельцем', 'owner_sold_confirmed', '#dc2626', 70, false, true, true, $now),
            $this->stageRow($pipelineId, 'Нет ответа', 'no_answer', '#f97316', 80, false, false, false, $now),
            $this->stageRow($pipelineId, 'Неактуально', 'not_relevant', '#6b7280', 90, false, true, true, $now),
            $this->stageRow($pipelineId, 'Закрыто', 'closed', '#111827', 100, false, true, true, $now),
        ];
    }

    private function stageRow(
        int $pipelineId,
        string $name,
        string $slug,
        string $color,
        int $sortOrder,
        bool $isDefault,
        bool $isClosed,
        bool $isLost,
        $now
    ): array {
        return [
            'pipeline_id' => $pipelineId,
            'name' => $name,
            'slug' => $slug,
            'color' => $color,
            'sort_order' => $sortOrder,
            'is_default' => $isDefault,
            'is_closed' => $isClosed,
            'is_lost' => $isLost,
            'is_active' => true,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
