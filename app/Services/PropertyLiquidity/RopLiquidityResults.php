<?php

namespace App\Services\PropertyLiquidity;

use App\Models\{Property, User};
use App\Support\RopGroupAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Derived current results; access version and current property scope are checked at every read. */
final class RopLiquidityResults
{
    public function __construct(private readonly PropertyLiquidityCalculator $calculator, private readonly RopGroupAccess $groups) {}

    public function refresh(User $actor): void
    {
        DB::transaction(function () use ($actor) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasRole('rop'), 403, 'FORBIDDEN_ACTION');
            DB::table('rop_liquidity_results')->where('rop_id', $actor->id)->delete();
            $this->groups->scope(Property::query(), $actor, 'properties.branch_group_id', 'properties.branch_id')
                ->publicSearchable()->whereNull('sold_at')->where('offer_type', 'sale')
                ->withMax(['socialPromotions as last_social_publication' => fn ($query) => $query->where('status', 'published')], 'published_at')
                ->with(['type', 'photos'])->chunkById(100, function ($properties) use ($actor) {
                    $rows = [];
                    foreach ($properties as $property) {
                        $snapshot = $this->calculator->previewForRop($property, $actor);
                        if (! $snapshot) continue;
                        $rows[] = [
                            'rop_id' => $actor->id, 'access_scope_version' => $actor->access_scope_version,
                            'property_id' => $property->id, 'branch_group_id' => $property->branch_group_id,
                            'score' => $snapshot->score, 'category' => $snapshot->category,
                            'confidence_score' => $snapshot->confidence_score, 'price_position' => $snapshot->price_position,
                            'promotion_priority_score' => $snapshot->promotion_priority_score,
                            'promotion_eligibility' => $snapshot->promotion_eligibility,
                            'snapshot' => json_encode($snapshot->attributesToArray(), JSON_THROW_ON_ERROR),
                            'calculated_at' => $snapshot->calculated_at,
                        ];
                    }
                    if ($rows) {
                        DB::table('rop_liquidity_results')->insert($rows);
                        $this->appendChangedHistory($actor, $rows);
                    }
                });
        });
    }

    private function appendChangedHistory(User $actor, array $rows): void
    {
        $latestIds = DB::table('rop_liquidity_history')->where('rop_id', $actor->id)
            ->where('access_scope_version', $actor->access_scope_version)
            ->whereIn('property_id', array_column($rows, 'property_id'))
            ->selectRaw('MAX(id)')->groupBy('property_id');
        $previous = DB::table('rop_liquidity_history')->whereIn('id', $latestIds)
            ->get(['property_id', 'branch_group_id', 'snapshot'])->keyBy('property_id');
        $changed = [];
        foreach ($rows as $row) {
            $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $last = $previous->get($row['property_id']);
            if ($last && (int) $last->branch_group_id === (int) $row['branch_group_id']) {
                $old = json_decode($last->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $current = $snapshot;
                unset($old['calculated_at'], $current['calculated_at']);
                // JSON object key order and numeric representation can differ across database engines.
                if ($old == $current) continue;
            }
            $changed[] = array_intersect_key($row, array_flip(['rop_id', 'access_scope_version', 'property_id', 'branch_group_id',
                'score', 'price_position', 'confidence_score', 'snapshot', 'calculated_at']))
                + ['price_delta_pct' => $snapshot['price_delta_pct']];
        }
        if ($changed) DB::table('rop_liquidity_history')->insert($changed);
    }

    public function query(User $actor): Builder
    {
        return $this->scopedQuery($actor, 'rop_liquidity_results', true);
    }

    public function history(User $actor, Property $property): Builder
    {
        $this->groups->ensureVisible($actor, $property);
        return $this->scopedQuery($actor, 'rop_liquidity_history', false)->where('property_id', $property->id)
            ->orderByDesc('calculated_at')->orderByDesc('id')->limit(90);
    }

    private function scopedQuery(User $actor, string $table, bool $active): Builder
    {
        abort_unless($actor->hasRole('rop'), 403, 'FORBIDDEN_ACTION');
        $properties = $this->groups->scope(Property::query(), $actor, 'properties.branch_group_id', 'properties.branch_id')
            ->whereColumn('properties.branch_group_id', $table.'.branch_group_id');
        if ($active) $properties->publicSearchable()->whereNull('sold_at')->where('offer_type', 'sale');

        return DB::table($table)->where('rop_id', $actor->id)
            ->whereIn('property_id', $properties->select('properties.id'))
            ->whereExists(fn (Builder $users) => $users->selectRaw('1')->from('users')
                ->whereColumn('users.id', $table.'.rop_id')
                ->whereColumn('users.access_scope_version', $table.'.access_scope_version'));
    }
}
