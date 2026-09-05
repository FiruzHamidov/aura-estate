<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\PropertyLog;
use App\Models\User;
use App\Services\PropertyDuplicateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AuditPropertyModerationMigrationCommand extends Command
{
    protected $signature = 'properties:moderation-audit {--days=90} {--limit=1000} {--output=}';

    protected $description = 'Build a read-only pre-migration report for property moderation risks';

    public function handle(PropertyDuplicateService $duplicates): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $published = Property::query()->when(
            Schema::hasColumn('properties', 'publication_status'),
            fn ($query) => $query->where('publication_status', 'published'),
            fn ($query) => $query->where('moderation_status', Property::PUBLIC_MODERATION_STATUS),
        );

        $report = [
            'generated_at' => now()->utc()->toIso8601String(),
            'window_days' => $days,
            'published_without_price' => (clone $published)->where(fn ($query) => $query->whereNull('price')->orWhere('price', '<=', 0))->pluck('id')->all(),
            'invalid_discounts' => (clone $published)->whereNotNull('discount_price')->where(fn ($query) => $query->where('discount_price', '<=', 0)->orWhereColumn('discount_price', '>', 'price'))->pluck('id')->all(),
            'currency_changes' => [],
            'suspicious_price_increases' => [],
            'probable_duplicate_groups' => [],
            'legacy_promotions' => [],
            'external_request_properties' => [],
            'scanned_properties' => 0,
            'scan_limit' => $limit,
        ];

        foreach (Property::query()->whereIn('listing_type', ['vip', 'urgent'])->get(['id', 'listing_type', 'created_by', 'agent_id', 'updated_at']) as $legacyPromotion) {
            $lastLog = Schema::hasTable('property_logs')
                ? PropertyLog::query()
                    ->where('property_id', $legacyPromotion->id)
                    ->whereNotNull('user_id')
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->first(fn (PropertyLog $log) => array_key_exists('listing_type', (array) $log->changes))
                : null;
            $changedBy = User::query()->with('role:id,slug')->find($lastLog?->user_id ?: $legacyPromotion->created_by);
            $report['legacy_promotions'][] = [
                'property_id' => (int) $legacyPromotion->id,
                'listing_type' => $legacyPromotion->listing_type,
                'created_by' => $legacyPromotion->created_by ? (int) $legacyPromotion->created_by : null,
                'agent_id' => $legacyPromotion->agent_id ? (int) $legacyPromotion->agent_id : null,
                'last_changed_by' => $changedBy?->id,
                'last_changed_role' => $changedBy?->role?->slug,
                'last_changed_at' => ($lastLog?->created_at ?: $legacyPromotion->updated_at)?->toIso8601String(),
                'source' => $lastLog?->action ?: 'legacy_property_state',
            ];
        }

        if (Schema::hasTable('external_property_requests') && Schema::hasColumn('external_property_requests', 'property_id')) {
            $propertyColumns = ['properties.id as property_id', 'properties.moderation_status', 'properties.listing_type'];
            if (Schema::hasColumn('properties', 'publication_status')) {
                $propertyColumns[] = 'properties.publication_status';
            }
            $report['external_request_properties'] = DB::table('external_property_requests as requests')
                ->join('properties', 'properties.id', '=', 'requests.property_id')
                ->whereNotNull('requests.property_id')
                ->orderBy('requests.id')
                ->limit($limit)
                ->get(array_merge(['requests.id as external_request_id'], $propertyColumns))
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        if (Schema::hasTable('property_price_history')) {
            $history = DB::table('property_price_history')
                ->where('changed_at', '>=', now()->subDays($days))
                ->orderBy('property_id')->orderBy('changed_at')->get()
                ->groupBy('property_id');
            foreach ($history as $propertyId => $rows) {
                $previous = null;
                foreach ($rows as $row) {
                    if ($previous && $previous->currency !== $row->currency) {
                        $report['currency_changes'][] = ['property_id' => (int) $propertyId, 'from' => $previous->currency, 'to' => $row->currency, 'at' => $row->changed_at];
                    }
                    $previousEffective = $previous
                        ? ((float) $previous->discount_price > 0 ? (float) $previous->discount_price : (float) $previous->price)
                        : null;
                    $currentEffective = (float) $row->discount_price > 0 ? (float) $row->discount_price : (float) $row->price;
                    if ($previousEffective !== null && $currentEffective > $previousEffective) {
                        $report['suspicious_price_increases'][] = ['property_id' => (int) $propertyId, 'from' => $previousEffective, 'to' => $currentEffective, 'currency' => $row->currency, 'at' => $row->changed_at];
                    }
                    $previous = $row;
                }
            }
        }

        foreach ((clone $published)->orderBy('id')->limit($limit)->get() as $property) {
            $report['scanned_properties']++;
            $candidateIds = $duplicates->find($property->getAttributes(), (int) $property->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($candidateIds !== []) {
                $report['probable_duplicate_groups'][] = ['property_id' => (int) $property->id, 'candidate_ids' => $candidateIds];
            }
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $output = $this->option('output');
        if ($output) {
            Storage::disk('local')->put((string) $output, $json."\n");
            $this->info('Report written to storage/app/private/'.$output);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
