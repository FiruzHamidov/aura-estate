<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motivation_rules', function (Blueprint $table) {
            $table->string('reward_type', 64)->change();
            $table->dropUnique('motivation_rules_unique_period_scope_reward');
            $table->json('condition')->nullable();
            $table->decimal('reward_amount', 16, 2)->nullable();
            $table->string('reward_currency', 3)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('motivation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('achievement_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 32);
            $table->string('reason', 1000)->nullable();
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Fail before dropping audit/condition data if new independent programs cannot fit the old key.
        $duplicates = \Illuminate\Support\Facades\DB::table('motivation_rules')
            ->select('scope')->groupBy('scope', 'metric_key', 'threshold_value', 'period_type', 'date_from', 'date_to', 'reward_type')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new \RuntimeException('Independent motivation programs exist. Preserve this schema when rolling back application code.');
        }
        Schema::dropIfExists('motivation_events');
        Schema::table('motivation_rules', fn (Blueprint $table) => $table->dropColumn([
            'condition', 'reward_amount', 'reward_currency', 'branch_id', 'branch_group_id', 'version',
        ]));
        Schema::table('motivation_rules', fn (Blueprint $table) => $table->unique(
            ['scope', 'metric_key', 'threshold_value', 'period_type', 'date_from', 'date_to', 'reward_type'],
            'motivation_rules_unique_period_scope_reward'
        ));
    }
};
