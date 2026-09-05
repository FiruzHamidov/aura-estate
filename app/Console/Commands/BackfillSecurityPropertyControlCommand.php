<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Crm\PropertyControlService;
use Illuminate\Console\Command;

class BackfillSecurityPropertyControlCommand extends Command
{
    protected $signature = 'security:backfill-property-control {--apply : Create or enrich CRM control cards}';

    protected $description = 'Backfill CRM property-control cards for existing sold and rented properties';

    public function handle(PropertyControlService $service): int
    {
        $query = Property::query()
            ->whereIn('moderation_status', config('security-property-control.successful_closing_statuses', []));

        $total = (clone $query)->count();
        $apply = (bool) $this->option('apply');
        $processed = 0;
        $withoutBranch = 0;

        $this->info(sprintf('%s %d closed properties.', $apply ? 'Processing' : 'Found', $total));

        $query->with(['agent.role', 'creator.role', 'ownerClient.type'])
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($service, $apply, &$processed, &$withoutBranch): void {
                foreach ($properties as $property) {
                    if (! $service->branchIdFor($property)) {
                        $withoutBranch++;
                        continue;
                    }

                    if (! $apply) {
                        continue;
                    }

                    $eventIdentity = implode('-', [
                        'backfill',
                        $property->moderation_status,
                        $property->sold_at ?: $property->updated_at?->format('YmdHis') ?: 'unknown',
                    ]);
                    $deal = $service->backfillProperty(
                        $property,
                        $service->eventUuidFor($property, $eventIdentity)
                    );

                    if ($deal) {
                        $processed++;
                    }
                }
            });

        $this->table(
            ['Found', 'Created or enriched', 'Without branch'],
            [[$total, $processed, $withoutBranch]]
        );

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to persist CRM cards.');
        }

        return self::SUCCESS;
    }
}
