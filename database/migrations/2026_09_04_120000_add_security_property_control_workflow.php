<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STAGES = [
        ['name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false],
        ['name' => 'На проверке СБ', 'slug' => 'security_review', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false],
        ['name' => 'Запрос в филиал', 'slug' => 'branch_clarification', 'color' => '#f59e0b', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'is_lost' => false],
        ['name' => 'Исправление филиалом', 'slug' => 'branch_correction', 'color' => '#8b5cf6', 'sort_order' => 40, 'is_default' => false, 'is_closed' => false, 'is_lost' => false],
        ['name' => 'Повторная проверка', 'slug' => 'security_recheck', 'color' => '#0891b2', 'sort_order' => 50, 'is_default' => false, 'is_closed' => false, 'is_lost' => false],
        ['name' => 'Подтверждено СБ', 'slug' => 'security_verified', 'color' => '#16a34a', 'sort_order' => 60, 'is_default' => false, 'is_closed' => true, 'is_lost' => false],
        ['name' => 'Подозрительно', 'slug' => 'security_flagged', 'color' => '#dc2626', 'sort_order' => 70, 'is_default' => false, 'is_closed' => true, 'is_lost' => true],
        ['name' => 'Отменено/не требует проверки', 'slug' => 'cancelled', 'color' => '#6b7280', 'sort_order' => 80, 'is_default' => false, 'is_closed' => true, 'is_lost' => false],
    ];

    private const LEGACY_MAP = [
        'in_review' => 'security_review',
        'contacted' => 'branch_clarification',
        'waiting_owner' => 'branch_clarification',
        'reactivation_in_progress' => 'branch_correction',
        'no_answer' => 'branch_clarification',
        'reactivated' => 'cancelled',
        'owner_sold_confirmed' => 'security_verified',
        'not_relevant' => 'cancelled',
        'closed' => 'cancelled',
    ];

    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->string('control_kind', 64)->nullable()->after('source_property_status')->index();
            $table->uuid('source_event_uuid')->nullable()->after('control_kind')->unique();
            $table->index(['control_kind', 'created_at'], 'crm_deals_control_created_idx');
            $table->index(['pipeline_id', 'stage_id', 'branch_id'], 'crm_deals_pipeline_stage_branch_idx');
            $table->index(['source_property_status', 'created_at'], 'crm_deals_source_status_created_idx');
            $table->index(['responsible_agent_id', 'closed_at'], 'crm_deals_responsible_closed_idx');
            $table->index('next_activity_at', 'crm_deals_next_activity_idx');
        });

        $now = now();
        $pipelineIds = DB::table('crm_deal_pipelines')
            ->where('code', 'property_control')
            ->pluck('id');

        foreach ($pipelineIds as $pipelineId) {
            $targetIds = [];

            DB::table('crm_deals')
                ->where('pipeline_id', $pipelineId)
                ->whereNull('control_kind')
                ->update([
                    'control_kind' => 'security_property_closure',
                    'updated_at' => $now,
                ]);

            foreach (self::STAGES as $stage) {
                $existing = DB::table('crm_deal_stages')
                    ->where('pipeline_id', $pipelineId)
                    ->where('slug', $stage['slug'])
                    ->first();

                if ($existing) {
                    DB::table('crm_deal_stages')->where('id', $existing->id)->update(array_merge($stage, [
                        'is_active' => true,
                        'updated_at' => $now,
                    ]));
                    $targetIds[$stage['slug']] = $existing->id;
                } else {
                    $targetIds[$stage['slug']] = DB::table('crm_deal_stages')->insertGetId(array_merge($stage, [
                        'pipeline_id' => $pipelineId,
                        'is_active' => true,
                        'meta' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
            }

            foreach (self::LEGACY_MAP as $legacySlug => $targetSlug) {
                $legacyId = DB::table('crm_deal_stages')
                    ->where('pipeline_id', $pipelineId)
                    ->where('slug', $legacySlug)
                    ->value('id');

                if (! $legacyId || ! isset($targetIds[$targetSlug])) {
                    continue;
                }

                $deals = DB::table('crm_deals')->where('stage_id', $legacyId);
                if ($legacySlug === 'owner_sold_confirmed') {
                    $deals->where('source_property_status', 'sold_by_owner');
                }

                $dealIds = (clone $deals)->pluck('id');
                $this->auditLegacyMoves($dealIds->all(), $legacySlug, $targetSlug, $now);

                $updates = [
                    'stage_id' => $targetIds[$targetSlug],
                    'updated_at' => $now,
                ];
                if ($legacySlug === 'no_answer') {
                    $updates['next_activity_at'] = $now->copy()->subSecond();
                }
                if ($legacySlug === 'reactivated') {
                    $updates['lost_reason'] = 'Объект реактивирован';
                } elseif (in_array($legacySlug, ['not_relevant', 'closed'], true)) {
                    $updates['lost_reason'] = 'Миграция старого этапа: '.$legacySlug;
                }

                (clone $deals)->update($updates);

                if (! DB::table('crm_deals')->where('stage_id', $legacyId)->exists()) {
                    DB::table('crm_deal_stages')->where('id', $legacyId)->update([
                        'is_active' => false,
                        'is_default' => false,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropIndex('crm_deals_control_created_idx');
            $table->dropIndex('crm_deals_pipeline_stage_branch_idx');
            $table->dropIndex('crm_deals_source_status_created_idx');
            $table->dropIndex('crm_deals_responsible_closed_idx');
            $table->dropIndex('crm_deals_next_activity_idx');
            $table->dropUnique(['source_event_uuid']);
            $table->dropIndex(['control_kind']);
            $table->dropColumn(['control_kind', 'source_event_uuid']);
        });
    }

    private function auditLegacyMoves(array $dealIds, string $oldSlug, string $newSlug, $now): void
    {
        if ($dealIds === [] || ! Schema::hasTable('crm_audit_logs')) {
            return;
        }

        $dealMorphClass = (new App\Models\Deal)->getMorphClass();
        foreach (array_chunk($dealIds, 500) as $chunk) {
            DB::table('crm_audit_logs')->insert(array_map(fn ($dealId) => [
                'auditable_type' => $dealMorphClass,
                'auditable_id' => $dealId,
                'actor_id' => null,
                'event' => 'property_control_stage_migrated',
                'old_values' => json_encode(['stage_slug' => $oldSlug], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['stage_slug' => $newSlug], JSON_UNESCAPED_UNICODE),
                'context' => json_encode(['migration' => '2026_09_04_120000'], JSON_UNESCAPED_UNICODE),
                'message' => 'Системный этап контроля перенесён без потери истории.',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }
};
