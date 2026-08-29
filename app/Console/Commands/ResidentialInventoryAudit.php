<?php

namespace App\Console\Commands;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Services\Residential\InventoryStatus;
use App\Services\Residential\UnitPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidentialInventoryAudit extends Command
{
    protected $signature = 'residential:inventory-audit {--snapshot : Persist original values; never changes inventory} {--details : Include entity IDs and issue codes}';

    protected $description = 'Report legacy residential inventory conflicts without publishing or normalizing data.';

    public function handle(UnitPrice $prices): int
    {
        $batch = (string) Str::uuid();
        $counts = ['new_buildings' => 0, 'developer_units' => 0];
        $issues = [];
        $details = [];
        foreach (['new_buildings' => NewBuilding::class, 'developer_units' => DeveloperUnit::class] as $type => $model) {
            $model::query()->orderBy('id')->chunkById(500, function ($rows) use ($type, $batch, $prices, &$counts, &$issues, &$details) {
                $snapshots = [];
                foreach ($rows as $row) {
                    $counts[$type]++;
                    $found = [];
                    if ($type === 'new_buildings') {
                        if (! $row->responsible_agent_id) {
                            $found[] = 'missing_consultant';
                        }
                        if (! $row->data_verified_at) {
                            $found[] = 'freshness_unconfirmed';
                        }
                        if (! $row->location_id) {
                            $found[] = 'missing_location';
                        }
                    } else {
                        $raw = $row->getAttributes();
                        if (InventoryStatus::rooms($raw) === null) {
                            $found[] = 'rooms_unknown_not_studio';
                        }
                        if ((float) $row->area <= 0) {
                            $found[] = 'invalid_area';
                        }
                        if (! $row->entrance_id || $row->floor === null || $row->position_on_floor === null) {
                            $found[] = 'incomplete_grid_position';
                        }
                        if ($row->publication_status === null && in_array($row->moderation_status, ['approved', 'available', 'reserved', 'sold'], true) && InventoryStatus::unit($raw)[0] !== 'published') {
                            $found[] = 'legacy_status_conflict';
                        }
                        if (($row->publication_status === null) !== ($row->availability_status === null)) {
                            $found[] = 'partial_canonical_status';
                        }
                        if ($row->total_price !== null && $row->price_per_sqm !== null && (float) $row->area > 0) {
                            try {
                                $calculated = $prices->calculate($raw + ['pricing_basis' => 'total']);
                                if ($calculated['price_per_sqm'] !== $row->price_per_sqm) {
                                    $found[] = 'price_basis_mismatch';
                                }
                            } catch (\Throwable) {
                                $found[] = 'invalid_price';
                            }
                        } elseif (! $row->price_on_request && $row->total_price === null) {
                            $found[] = 'price_unconfirmed';
                        }
                    }
                    foreach ($found as $issue) {
                        $issues[$issue] = ($issues[$issue] ?? 0) + 1;
                    }
                    if ($found && $this->option('details')) {
                        $details[] = ['entity' => $type, 'id' => $row->id, 'issues' => $found];
                    }
                    if ($this->option('snapshot')) {
                        $snapshots[] = ['batch_id' => $batch, 'entity_type' => $type, 'entity_id' => $row->id, 'original_values' => json_encode($row->getAttributes(), JSON_THROW_ON_ERROR), 'issues' => json_encode($found, JSON_THROW_ON_ERROR), 'created_at' => now()];
                    }
                }
                if ($snapshots) {
                    DB::table('residential_inventory_snapshots')->insert($snapshots);
                }
            });
        }
        $this->line(json_encode(['batch_id' => $this->option('snapshot') ? $batch : null, 'as_of' => now()->toIso8601String(), 'counts' => $counts, 'issues' => $issues, 'details' => $details, 'inventory_changed' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
