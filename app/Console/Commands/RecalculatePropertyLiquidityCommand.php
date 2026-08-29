<?php

namespace App\Console\Commands;

use App\Jobs\RecalculatePropertyLiquidity;
use App\Models\Property;
use App\Services\PropertyLiquidity\PropertyLiquidityCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculatePropertyLiquidityCommand extends Command
{
    protected $signature = 'properties:recalculate-liquidity {--property=} {--sync}';

    protected $description = 'Recalculate liquidity for one property or all active sale apartments';

    public function handle(PropertyLiquidityCalculator $calculator): int
    {
        $propertyId = $this->option('property');
        $query = Property::query()
            ->when($propertyId, fn ($builder) => $builder->whereKey((int) $propertyId))
            ->when(! $propertyId, fn ($builder) => $builder
                ->where('offer_type', 'sale')
                ->where('moderation_status', Property::PUBLIC_MODERATION_STATUS)
                ->whereNull('sold_at'));

        $count = 0;
        $query->select('properties.*')->orderBy('id')->chunkById(100, function ($properties) use ($calculator, &$count): void {
            foreach ($properties as $property) {
                if ($this->option('sync')) {
                    $calculator->calculate($property);
                } else {
                    RecalculatePropertyLiquidity::dispatch($property->id);
                }
                $count++;
            }
        });

        $this->snapshotViews();
        $this->info($this->option('sync') ? "Calculated: {$count}" : "Queued: {$count}");

        return self::SUCCESS;
    }

    private function snapshotViews(): void
    {
        $today = now()->toDateString();
        Property::query()
            ->where('moderation_status', Property::PUBLIC_MODERATION_STATUS)
            ->whereNull('sold_at')
            ->select(['id', 'views_count', 'listed_at', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($properties) use ($today): void {
                foreach ($properties as $property) {
                    $previous = DB::table('property_view_daily_stats')
                        ->where('property_id', $property->id)
                        ->where('date', '<', $today)
                        ->orderByDesc('date')
                        ->value('views_count');
                    $activeFrom = $property->listed_at ?? $property->created_at;
                    DB::table('property_view_daily_stats')->updateOrInsert(
                        ['property_id' => $property->id, 'date' => $today],
                        [
                            'views_count' => (int) $property->views_count,
                            'views_delta' => max(0, (int) $property->views_count - (int) ($previous ?? 0)),
                            'active_days' => $activeFrom ? max(1, $activeFrom->diffInDays(now())) : 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }
}
