<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PropertyPublicationRepair;
use Illuminate\Console\Command;

final class ReconcilePropertyPublicationCommand extends Command
{
    protected $signature = 'properties:reconcile-publication
        {--actor= : Existing active reviewer user ID}
        {--export= : New private JSON path for a review manifest; makes no database changes}
        {--manifest= : Reviewed manifest JSON path}
        {--rollback= : Previously applied batch UUID}
        {--apply : Apply the reviewed repair or rollback; otherwise preview only}
        {--backup= : New absolute private backup path, required with --apply}';

    protected $description = 'Preview or explicitly reconcile the eight agent 163 publication conflicts with guarded rollback';

    public function handle(PropertyPublicationRepair $repair): int
    {
        if (count(array_filter([$this->option('export'), $this->option('manifest'), $this->option('rollback')])) !== 1
            || ($this->option('export') && $this->option('apply'))) {
            $this->error('Choose exactly one of --export, --manifest, --rollback. Export cannot apply changes.');
            return self::FAILURE;
        }
        $actor = User::find($this->option('actor'));
        if (! $actor) {
            $this->error('--actor must identify an existing reviewer.');
            return self::FAILURE;
        }
        try {
            if ($this->option('export')) {
                $repair->writePrivateJson($this->option('export'), $repair->export($actor));
                $this->info('Private review manifest exported. Every entry is on hold; no database changes.');
                return self::SUCCESS;
            }
            if ($this->option('manifest')) {
                $data = json_decode(file_get_contents($this->option('manifest')), true, flags: JSON_THROW_ON_ERROR);
                $results = $repair->run($data, $actor, (bool) $this->option('apply'), $this->option('backup'));
            } else {
                $results = $repair->rollback($this->option('rollback'), $actor, (bool) $this->option('apply'), $this->option('backup'));
            }
            $this->table(['ID', 'Result', 'Reason', 'Changes', 'Public before / after', 'Sale date'], array_map(fn ($r) => [
                $r['id'], $r['status'], $r['reason'] ?? '', $this->describeChanges($r['changes'] ?? []),
                isset($r['public_before']) ? (int) $r['public_before'].' / '.(int) $r['public_after'] : '',
                array_key_exists('sold_at', $r) ? ($r['sold_at'] ?? 'Unknown; will not be invented') : '',
            ], $results));
            if ($this->option('manifest')) {
                $changed = collect($results)->whereIn('status', ['ready', 'applied']);
                $this->line('Approved listings delta: -'.$changed->count().'; public listings delta: '.
                    $changed->sum(fn ($r) => (int) $r['public_after'] - (int) $r['public_before']));
                $this->line('Historical reports may reclassify these records; sale dates, financial values and participants are preserved.');
            }
            $this->info($this->option('apply') ? 'Explicit operation completed. Review every row above.' : 'Preview only; no database changes.');
            return collect($results)->contains('status', 'skipped') ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof \DomainException ? $e->getMessage() : 'Operation stopped: '.class_basename($e).'. No partial changes within the failed record; earlier completed records retain their receipts.');
            return self::FAILURE;
        }
    }

    private function describeChanges(array $changes): string
    {
        $lines = [];
        foreach ($changes as $field => $change) {
            $lines[] = $field.': '.json_encode($change['old'], JSON_UNESCAPED_UNICODE).
                ' -> '.json_encode($change['new'], JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $lines);
    }
}
