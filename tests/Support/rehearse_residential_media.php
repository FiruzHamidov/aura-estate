<?php

// Rehearse the operator runbook against a NEW disposable, persistent SQLite database.
// Never accepts an existing database, application storage directory, or production data.
use App\Models\BuildingFloorPlan;
use App\Models\NewBuilding;
use App\Models\UnitLayout;
use App\Services\Crm\PublicLeadIntake;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ResidentialSchema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || ! $root || ! preg_match('#^/(private/)?tmp/aura-residential-rehearsal\.[A-Za-z0-9]+$#', $root) || count(scandir($root)) !== 2) {
    throw new LogicException('Pass an empty directory created by mktemp -d /tmp/aura-residential-rehearsal.XXXXXX.');
}
foreach (['storage/framework', 'public', 'private'] as $directory) {
    mkdir($root.'/'.$directory, 0700, true);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($root.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: '.get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || $app->configurationIsCached()) {
    throw new LogicException('Require uncached APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:.');
}
config([
    'filesystems.disks.public.root' => $root.'/public',
    'filesystems.disks.public.throw' => true,
    'filesystems.disks.residential.root' => $root.'/private',
    'app.maintenance.driver' => 'file',
    'app.url' => 'http://localhost',
]);
Http::preventStrayRequests();

$checks = [];
$check = function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException('Rehearsal failed: '.$name);
    }
    $checks[] = $name;
};
$commands = [];
$command = function (string $name, array $arguments, int $expected) use (&$commands, $check): string {
    $code = Artisan::call($name, $arguments);
    $output = Artisan::output();
    $commands[] = ['command' => $name, 'arguments' => $arguments, 'exit_code' => $code, 'output' => trim($output)];
    $check($code === $expected, $name.' '.json_encode($arguments).' exit='.$expected);

    return $output;
};
$files = function (string $disk): array {
    $result = [];
    foreach (Storage::disk($disk)->allFiles() as $path) {
        $result[$path] = hash_file('sha256', Storage::disk($disk)->path($path));
    }
    ksort($result);

    return $result;
};

ResidentialSchema::create();
Schema::create('clients', fn (Blueprint $table) => $table->id());
foreach (['2026_03_07_120000_create_leads_table.php', '2026_08_28_120000_create_lead_intakes_table.php'] as $migration) {
    (require database_path('migrations/'.$migration))->up();
}
$building = NewBuilding::create(['title' => 'QA media rehearsal — not a real offer', 'publication_status' => 'draft']);
$block = $building->blocks()->create(['name' => 'QA block']);
$entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => 'QA entrance', 'residential_floor_from' => 1, 'residential_floor_to' => 1]);
$unit = $building->units()->create(['name' => 'QA unique lot', 'external_id' => 'qa-media-1', 'area' => '42.50', 'total_price' => '315000.55', 'publication_status' => 'draft', 'availability_status' => 'available']);
$sharedPath = 'new-buildings/'.$building->id.'/shared.png';
$unitPath = 'units/qa-unit.png';
$invalidPath = 'new-buildings/'.$building->id.'/invalid.png';
$image = imagecreatetruecolor(800, 600);
imagefill($image, 0, 0, imagecolorallocate($image, 240, 245, 255));
imagestring($image, 5, 30, 30, 'SYNTHETIC MEDIA RECOVERY TEST', imagecolorallocate($image, 0, 40, 130));
ob_start();
imagepng($image);
$bytes = ob_get_clean();
imagedestroy($image);
foreach ([$sharedPath, $unitPath] as $path) {
    Storage::disk('public')->put($path, $bytes);
}
Storage::disk('public')->put($invalidPath, '<svg onload="invalid"/>');
$photo = $building->photos()->create(['path' => $sharedPath])->fresh();
$invalidPhoto = $building->photos()->create(['path' => $invalidPath])->fresh();
$unitPhoto = $unit->photos()->create(['path' => $unitPath])->fresh();
$layout = UnitLayout::create(['new_building_id' => $building->id, 'code' => 'QA', 'image_path' => $sharedPath])->fresh();
$floor = BuildingFloorPlan::create(['new_building_id' => $building->id, 'block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 1, 'floor_to' => 1, 'image_path' => $sharedPath])->fresh();
$records = [$photo, $unitPhoto, $layout, $floor];
$originalRecords = array_map(fn ($record) => $record->getAttributes(), $records);
$originalUnit = $unit->fresh()->getAttributes();
$originalPublicFiles = $files('public');

// Export the fresh fixture, then reconnect. All commands below use a real file, not :memory:.
$database = $root.'/database.sqlite';
DB::statement('VACUUM INTO ?', [$database]);
DB::purge('sqlite');
config(['database.connections.sqlite.database' => $database]);
$check(DB::selectOne('PRAGMA database_list')->file === $database, 'commands use isolated persistent SQLite');
DB::statement('VACUUM INTO ?', [$root.'/before-copy.sqlite']);
$databaseHash = hash_file('sha256', $database);
$command('residential:inventory-audit', ['--details' => true], 0);
$command('residential:media-migrate', ['--building' => $building->id], 0);
$check(hash_file('sha256', $database) === $databaseHash && $files('public') === $originalPublicFiles && $files('residential') === [], 'readonly reports change neither database nor files');
$command('residential:media-migrate', ['--apply' => true], 2);
$check(hash_file('sha256', $database) === $databaseHash, 'copy without confirmation changes nothing');

// Exercise production maintenance gating, not the testing environment shortcut.
$app['env'] = 'staging';
$command('residential:media-migrate', ['--apply' => true, '--confirm' => true], 1);
$check(DB::table('residential_media_migrations')->count() === 4, 'mixed batch commits four valid media records and fails invalid image');
$check($invalidPhoto->fresh()->getAttributes() === $invalidPhoto->getAttributes(), 'invalid image record unchanged');
$check($files('public') === $originalPublicFiles, 'copy retains every public source');
foreach ($records as $index => $record) {
    $fresh = $record->fresh();
    $journal = DB::table('residential_media_migrations')->where('entity_type', $record->getTable())->where('entity_id', $record->id)->first();
    $check(json_decode($journal->old_values, true) === $originalRecords[$index], $record->getTable().' recovery metadata exact');
    $check(Storage::disk('residential')->get($journal->backup_path) === $bytes && hash('sha256', $bytes) === $journal->source_sha256, $record->getTable().' byte-identical source backup');
    $check($fresh->storage_disk === 'residential' && $fresh->version === 2 && count($fresh->variants) === 3, $record->getTable().' private record and responsive variants');
    foreach ([$fresh->path ?? $fresh->image_path, $fresh->original_path, ...array_column($fresh->variants, 'path')] as $path) {
        $check(getimagesize(Storage::disk('residential')->path($path))['mime'] === 'image/webp', $record->getTable().' valid WebP '.$path);
    }
}
$check($building->fresh()->publication_status === 'draft' && $building->fresh()->version === 5 && $unit->fresh()->getAttributes() === $originalUnit, 'migration does not publish or alter real-lot values');
$beforeRepeatFiles = $files('residential');
$beforeRepeatDatabase = hash_file('sha256', $database);
$command('residential:media-migrate', ['--apply' => true, '--confirm' => true], 1);
$check($beforeRepeatFiles === $files('residential') && $beforeRepeatDatabase === hash_file('sha256', $database), 'repeat has no duplicate journal, assets, or versions');

// A lead accepted AFTER the backup must survive media recovery; never restore the old DB over it.
$input = ['service_type' => 'residential', 'name' => 'QA recovery lead', 'phone' => '+992900000099', 'source' => 'local-rehearsal', 'source_url' => 'http://localhost/qa', 'idempotency_key' => 'qa-media-rehearsal-only', 'consent' => true, 'consent_version' => 'qa'];
$receipt = app(PublicLeadIntake::class)->accept($input);
$leadBeforeRecovery = DB::table('leads')->find($receipt['lead_id']);
$receiptBeforeRecovery = DB::table('lead_intakes')->find($receipt['request_id']);
$journalId = DB::table('residential_media_migrations')->where('entity_type', $photo->getTable())->where('entity_id', $photo->id)->value('id');
$output = $command('residential:media-migrate', ['--quarantine' => $journalId, '--confirm' => true], 1);
$check(str_contains($output, 'maintenance_mode_required_for_quarantine') && $files('public') === $originalPublicFiles, 'quarantine refuses live mode');
$command('down', [], 0);
try {
    $check(is_file($root.'/storage/framework/down'), 'maintenance marker belongs only to disposable storage');
    // A newly referenced old URL must still block deletion even after all initial records migrated.
    $shared = $building->photos()->create(['path' => $sharedPath])->fresh();
    $output = $command('residential:media-migrate', ['--quarantine' => $journalId, '--confirm' => true], 1);
    $check(str_contains($output, 'source_still_referenced') && Storage::disk('public')->exists($sharedPath), 'remaining public reference blocks deletion');
    $command('residential:media-migrate', ['--apply' => true, '--confirm' => true], 1);
    foreach (DB::table('residential_media_migrations')->orderBy('id')->get() as $journal) {
        $command('residential:media-migrate', ['--quarantine' => $journal->id, '--confirm' => true], 0);
    }
    $command('residential:media-migrate', ['--quarantine' => $journalId, '--confirm' => true], 0);
    $check(! Storage::disk('public')->exists($sharedPath) && ! Storage::disk('public')->exists($unitPath) && Storage::disk('public')->exists($invalidPath), 'only verified journaled source files removed');
    $check(DB::table('residential_media_migrations')->whereNull('quarantined_at')->count() === 0, 'all five journal records marked quarantined');

    // Restore original bytes into a NEW private recovery area, not onto public URLs or live metadata.
    foreach (DB::table('residential_media_migrations')->get() as $journal) {
        $target = 'recovered/'.$journal->id.'/source.png';
        $check(Storage::disk('residential')->copy($journal->backup_path, $target), 'private recovery copy '.$journal->id);
        $check(hash_file('sha256', Storage::disk('residential')->path($target)) === $journal->source_sha256, 'private recovery SHA256 '.$journal->id);
    }
    $refused = false;
    try {
        (require database_path('migrations/2026_08_28_210000_create_residential_media_migrations.php'))->down();
    } catch (RuntimeException $error) {
        $refused = str_contains($error->getMessage(), 'Keep the media migration journal');
    }
    $check($refused && DB::table('residential_media_migrations')->count() === 5, 'destructive journal rollback refused');
} finally {
    $command('up', [], 0);
}
$check(! is_file($root.'/storage/framework/down'), 'disposable maintenance mode cleared');
$check(DB::table('leads')->find($receipt['lead_id']) == $leadBeforeRecovery && DB::table('lead_intakes')->find($receipt['request_id']) == $receiptBeforeRecovery, 'post-backup lead and receipt preserved exactly');
$replay = app(PublicLeadIntake::class)->accept($input);
$check($replay['replayed'] && $replay['request_id'] === $receipt['request_id'] && DB::table('leads')->count() === 1, 'post-recovery lead retry returns same receipt without duplication');
$check($building->fresh()->publication_status === 'draft' && $unit->fresh()->getAttributes() === $originalUnit, 'final publication and lot values unchanged');
$check(DB::selectOne('PRAGMA integrity_check')->integrity_check === 'ok', 'persistent SQLite integrity check');

// Open the pre-copy backup as a separate read-only DB: prove it is usable, never replace the live DB.
$backup = new PDO('sqlite:file:'.$root.'/before-copy.sqlite?mode=ro');
$check((int) $backup->query('SELECT COUNT(*) FROM leads')->fetchColumn() === 0, 'pre-copy database backup opens and predates accepted lead');
$check((int) $backup->query('SELECT COUNT(*) FROM residential_media_migrations')->fetchColumn() === 0, 'pre-copy database backup contains original metadata');
$report = ['result' => 'PASS', 'database' => $database, 'environment_during_commands' => 'staging with isolated storage', 'scope' => 'Synthetic local SQLite rehearsal; not production, MySQL, HTTP/CDN, or complete data restoration.', 'checks' => $checks, 'commands' => $commands, 'counts' => ['journals' => DB::table('residential_media_migrations')->count(), 'leads' => DB::table('leads')->count(), 'receipts' => DB::table('lead_intakes')->count()], 'public_files' => $files('public'), 'private_files' => $files('residential')];
file_put_contents($root.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'checks' => count($checks), 'report' => $root.'/report.json', 'counts' => $report['counts']], JSON_THROW_ON_ERROR).PHP_EOL;
