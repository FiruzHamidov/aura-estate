<?php

namespace App\Services\AgentWorkspace;

use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One source per closing cycle. Corrections reuse the cycle; reopening ends it. */
final class RatingSources
{
    public function sync(bool $force = false): void
    {
        DB::transaction(function () use ($force) {
            $cursor = DB::table('agent_rating_state')->where('key', 'facts')->lockForUpdate()->first();
            $syncedAt = DB::table('agent_rating_state')->where('key', 'synced_at')->value('value');
            if (! $force && $syncedAt && (int) $syncedAt > time() - 30) {
                return;
            }
            if (Schema::hasTable('kpi_result_facts')) {
                DB::table('kpi_result_facts')->where('id', '>', $cursor->value)->orderBy('id')->chunkById(300, function ($facts) {
                    foreach ($facts as $fact) {
                        $previous = DB::table('agent_rating_sources')->where('property_id', $fact->property_id)->whereNull('canonical_source_id')->where('state', '!=', 'needs_link')->latest('id')->first();
                        if ($fact->state !== 'active') {
                            if ($previous) {
                                DB::table('agent_rating_sources')->where('id', $previous->id)->update(['state' => 'reversed', 'source_version' => $fact->id, 'updated_at' => now()->utc()]);
                            }

                            continue;
                        }
                        $data = [
                            'source_version' => $fact->id, 'kind' => $fact->kind, 'state' => 'closed',
                            'closed_at' => $fact->closed_at, 'user_id' => $fact->user_id,
                            'branch_id' => $fact->branch_id, 'branch_group_id' => $fact->branch_group_id,
                            'financials' => json_encode(['commission' => $fact->company_commission_amount ?? null, 'currency' => $fact->company_commission_currency ?? null, 'fx_rate' => $fact->fx_rate ?? null]),
                            'approved_revision_id' => null, 'pending_revision_id' => null, 'updated_at' => now()->utc(),
                        ];
                        if ($previous && $previous->state === 'closed') {
                            DB::table('agent_rating_sources')->where('id', $previous->id)->update($data);
                        } else {
                            DB::table('agent_rating_sources')->insert($data + ['source_key' => 'property:'.$fact->property_id.':'.$fact->id, 'property_id' => $fact->property_id, 'created_at' => now()->utc()]);
                        }
                    }
                    DB::table('agent_rating_state')->where('key', 'facts')->update(['value' => $facts->last()->id]);
                });
            }
            // Legacy closings are candidates only. They never receive guessed shares or approval.
            if (Schema::hasTable('properties')) {
                $query = DB::table('properties')->whereIn('moderation_status', ['sold', 'rented'])->whereNotNull('sold_at');
                if (Schema::hasTable('kpi_result_facts')) {
                    $query->whereNotIn('id', DB::table('kpi_result_facts')->select('property_id'));
                }
                if (Schema::hasColumn('properties', 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }
                $query->orderBy('id')->chunkById(300, function ($properties) {
                    foreach ($properties as $p) {
                        $userId = app(\App\Services\SalesAttributionService::class)->primarySellerId($p);
                        $user = $userId ? User::find($userId) : null;
                        $this->upsertLegacy('property:'.$p->id.':legacy', [
                            'property_id' => $p->id, 'kind' => $p->moderation_status === 'sold' ? 'sale' : 'rental',
                            'closed_at' => CarbonImmutable::parse($p->sold_at, config('app.timezone'))->utc()->toDateTimeString(),
                            'user_id' => $userId ?: null, 'branch_id' => $user?->branch_id, 'branch_group_id' => $user?->branch_group_id,
                        ], ['commission' => $p->company_commission_amount ?? null, 'currency' => $p->company_commission_currency ?? null, 'fx_rate' => $p->deal_fx_rate ?? null]);
                    }
                });
                // A legacy source can be reopened before its first KPI event has been recorded.
                foreach (DB::table('agent_rating_sources')->where('source_key', 'like', '%:legacy')->where('state', 'closed')->get() as $source) {
                    $p = DB::table('properties')->where('id', $source->property_id)->first();
                    if (! $p || ! in_array($p->moderation_status, ['sold', 'rented'], true) || ($p->deleted_at ?? null)) {
                        DB::table('agent_rating_sources')->where('id', $source->id)->update(['state' => 'reversed', 'updated_at' => now()->utc()]);
                    }
                }
            }
            if (Schema::hasTable('crm_deals')) {
                Deal::with(['stage', 'pipeline'])->where(function ($q) {
                    $q->whereNotNull('closed_at')->orWhereIn('id', DB::table('agent_rating_sources')->whereNotNull('deal_id')->select('deal_id'))
                        ->orWhereHas('stage', fn ($stage) => $stage->where('is_closed', true)->where('is_lost', false));
                })->whereHas('pipeline', fn ($q) => $q->where(fn ($q) => $q->whereNull('code')->orWhere('code', '!=', 'hr_recruitment')))
                    ->orderBy('id')->chunkById(200, function ($deals) {
                        foreach ($deals as $deal) {
                            $existing = DB::table('agent_rating_sources')->where('deal_id', $deal->id)->first();
                            if ($existing?->canonical_source_id) {
                                continue;
                            }
                            $closed = $deal->stage?->is_closed && ! $deal->stage?->is_lost;
                            if (! $closed) {
                                if ($existing && str_starts_with($existing->source_key, 'crm:')) {
                                    DB::table('agent_rating_sources')->where('id', $existing->id)->update(['state' => 'reversed', 'updated_at' => now()->utc()]);
                                }

                                continue;
                            }
                            $closedAt = $deal->closed_at?->copy()->utc()->toDateTimeString();
                            if ($deal->primary_property_id && $closedAt) {
                                $linked = DB::table('agent_rating_sources')->where('property_id', $deal->primary_property_id)->where('state', 'closed')
                                    ->whereNull('canonical_source_id')->where('source_key', 'like', 'property:%')->where('closed_at', $closedAt)->first();
                                if ($linked && ! $existing) {
                                    if (! $linked->deal_id) {
                                        DB::table('agent_rating_sources')->where('id', $linked->id)->update(['deal_id' => $deal->id]);
                                    }

                                    continue;
                                }
                                if ($existing && ! str_starts_with($existing->source_key, 'crm:')) {
                                    continue;
                                }
                            }
                            $meta = $deal->meta ?? [];
                            $kind = $meta['transaction_kind'] ?? $meta['deal_kind'] ?? 'unknown';
                            if (! in_array($kind, ['sale', 'rental'], true)) {
                                $kind = 'unknown';
                            }
                            $this->upsertLegacy('crm:'.$deal->id, ['deal_id' => $deal->id, 'property_id' => $deal->primary_property_id,
                                'state' => $deal->primary_property_id ? 'needs_link' : 'closed',
                                'kind' => $kind, 'closed_at' => $closedAt, 'user_id' => $deal->responsible_agent_id,
                                'branch_id' => $deal->branch_id, 'branch_group_id' => $deal->branch_group_id],
                                ['commission' => $deal->actual_company_income, 'currency' => $deal->actual_company_income_currency, 'fx_rate' => $meta['fx_rate'] ?? null]);
                        }
                    });
            }
            if (Schema::hasTable('crm_deals')) {
                $deleted = DB::table('crm_deals')->whereNotNull('deleted_at')->select('id');
                DB::table('agent_rating_sources')->where('source_key', 'like', 'crm:%')->whereNull('canonical_source_id')->whereIn('deal_id', $deleted)->update(['state' => 'reversed', 'updated_at' => now()->utc()]);
            }
            DB::table('agent_rating_state')->where('key', 'synced_at')->update(['value' => time()]);
        });
    }

    private function upsertLegacy(string $key, array $identity, array $financials): void
    {
        $previous = DB::table('agent_rating_sources')->where('source_key', $key)->first();
        if ($previous && $previous->state === 'reversed') {
            // Reclosing a reopened CRM/legacy record starts a new stable contract cycle.
            DB::table('agent_rating_sources')->where('id', $previous->id)->update(['source_key' => $key.':reversed:'.$previous->id, 'deal_id' => null]);
            $previous = null;
        }
        $encoded = json_encode($financials);
        if (! $previous) {
            DB::table('agent_rating_sources')->insert($identity + ['source_key' => $key, 'source_version' => 1, 'state' => 'closed', 'financials' => $encoded, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
        } elseif (json_decode($previous->financials ?? '{}', true) != $financials || $previous->closed_at !== $identity['closed_at'] || $previous->kind !== $identity['kind'] || (int) $previous->user_id !== (int) $identity['user_id']) {
            DB::table('agent_rating_sources')->where('id', $previous->id)->update(['source_version' => $previous->source_version + 1, 'state' => $identity['state'] ?? 'closed', 'financials' => $encoded, 'closed_at' => $identity['closed_at'], 'kind' => $identity['kind'], 'user_id' => $identity['user_id'], 'approved_revision_id' => null, 'pending_revision_id' => null, 'updated_at' => now()->utc()]);
        }
    }

    public function month(object $source): string
    {
        return CarbonImmutable::parse($source->closed_at, 'UTC')->setTimezone('Asia/Dushanbe')->format('Y-m');
    }
}
