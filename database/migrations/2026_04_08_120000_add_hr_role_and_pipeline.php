<?php

use App\Models\DealPipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_SLUG = 'hr';

    private const PIPELINE_CODE = DealPipeline::CODE_HR_RECRUITMENT;

    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'HR',
                'slug' => self::ROLE_SLUG,
                'description' => 'Управление пользователями и HR-воронкой найма',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'description', 'updated_at']);

        $pipelineId = DB::table('crm_deal_pipelines')
            ->whereNull('branch_id')
            ->where('code', self::PIPELINE_CODE)
            ->value('id');

        if (! $pipelineId) {
            $sortOrder = (int) DB::table('crm_deal_pipelines')
                ->whereNull('branch_id')
                ->max('sort_order') + 10;

            $pipelineId = DB::table('crm_deal_pipelines')->insertGetId([
                'name' => 'HR: Найм',
                'slug' => self::PIPELINE_CODE,
                'code' => self::PIPELINE_CODE,
                'type' => DealPipeline::TYPE_SALES,
                'branch_id' => null,
                'sort_order' => $sortOrder,
                'is_default' => false,
                'is_active' => true,
                'meta' => json_encode([
                    'system_managed' => true,
                    'role_scope' => self::ROLE_SLUG,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $existingStageSlugs = DB::table('crm_deal_stages')
            ->where('pipeline_id', $pipelineId)
            ->pluck('slug')
            ->all();

        if ($existingStageSlugs === []) {
            DB::table('crm_deal_stages')->insert($this->stageRows($pipelineId, $now));
        }
    }

    public function down(): void
    {
        DB::table('crm_deal_pipelines')
            ->whereNull('branch_id')
            ->where('code', self::PIPELINE_CODE)
            ->delete();
    }

    private function stageRows(int $pipelineId, $now): array
    {
        return [
            $this->stageRow($pipelineId, 'Новый отклик', 'new_application', '#64748b', 10, true, false, false, $now),
            $this->stageRow($pipelineId, 'Скрининг', 'screening', '#2563eb', 20, false, false, false, $now),
            $this->stageRow($pipelineId, 'Интервью с HR', 'hr_interview', '#0891b2', 30, false, false, false, $now),
            $this->stageRow($pipelineId, 'Тех/финальное интервью', 'final_interview', '#7c3aed', 40, false, false, false, $now),
            $this->stageRow($pipelineId, 'Оффер', 'offer', '#f59e0b', 50, false, false, false, $now),
            $this->stageRow($pipelineId, 'Нанят', 'hired', '#16a34a', 60, false, true, false, $now),
            $this->stageRow($pipelineId, 'Отказ', 'rejected', '#dc2626', 70, false, true, true, $now),
            $this->stageRow($pipelineId, 'Кадровый резерв', 'talent_pool', '#0f766e', 80, false, true, false, $now),
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
