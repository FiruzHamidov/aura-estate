<?php

// A dedicated socket-only mysqld with a NEW datadir is mandatory. Never accepts an existing application DB.
use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\User;
use App\Services\Crm\PublicLeadIntake;
use App\Services\Residential\InventoryCsv;
use App\Services\Residential\InventoryImport;
use App\Services\Residential\InventoryQuery;
use App\Services\Residential\InventoryWriter;
use App\Services\Residential\StructureWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (isset($argv[2]) && $argv[2] !== '--backup-restore') {
    throw new LogicException('The only optional argument is --backup-restore.');
}
$root = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || ! $root || ! preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+$#', $root) || (fileperms($root) & 0077) !== 0) {
    throw new LogicException('Require a private mktemp -d /tmp/aura-residential-mysql.XXXXXX directory.');
}
$socket = $root.'/mysql.sock';
if (! file_exists($socket) || is_link($socket)) {
    throw new LogicException('Require the dedicated local MySQL socket.');
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT @@version AS version, @@datadir AS datadir, @@skip_networking AS skip_networking, @@sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
if (realpath($server['datadir']) !== $root.'/data' || (int) $server['skip_networking'] !== 1) {
    throw new LogicException('Refusing MySQL outside the dedicated datadir or with network access enabled.');
}
$database = 'aura_residential_qa_'.bin2hex(random_bytes(4));
$run = $root.'/'.$database;
mkdir($run, 0700);
mkdir($run.'/storage/framework', 0700, true);
$checks = [];
$migrations = [];
$onFailure = function (Throwable $error) use ($run, &$checks, &$migrations, $server, $database): void {
    file_put_contents($run.'/report.json', json_encode(['status' => 'failed', 'server' => $server, 'database' => $database, 'checks' => $checks, 'migrations' => $migrations, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, 'FAIL: '.$error->getMessage().PHP_EOL.'Report: '.$run.'/report.json'.PHP_EOL);
    exit(1);
};
set_exception_handler($onFailure);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($run.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel installs its own handler during bootstrap; retain the standalone rehearsal report.
set_exception_handler($onFailure);
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || $app->configurationIsCached()) {
    throw new LogicException('Bootstrap must use uncached APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:.');
}
// Database name is generated here, not accepted from environment or arguments. CREATE refuses collisions.
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.residential_mysql' => [
    'driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost', 'port' => 0,
    'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
], 'database.default' => 'residential_mysql', 'cache.default' => 'array', 'session.driver' => 'array',
    'filesystems.disks.public.root' => $run.'/public', 'filesystems.disks.residential.root' => $run.'/private',
]);
Http::preventStrayRequests();
$check = function (bool $valid, string $label) use (&$checks): void {
    if (! $valid) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$migrate = function (string $file, string $direction = 'up') use (&$migrations): void {
    (require database_path('migrations/'.$file))->$direction();
    $migrations[] = $direction.':'.$file;
};
$check(DB::selectOne('SELECT DATABASE() AS name')->name === $database, 'dedicated database selected');
$check(str_contains(DB::selectOne('SELECT @@sql_mode AS mode')->mode, 'ONLY_FULL_GROUP_BY'), 'strict MySQL grouping is enabled');

// Minimal dependencies only; the residential tables below use the actual historical migrations.
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->timestamps();
});
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('phone')->unique();
    $table->foreignId('role_id');
    $table->foreignId('branch_id')->nullable();
    $table->string('status')->default('active');
    $table->string('auth_method')->default('password');
    $table->string('photo')->nullable();
    $table->timestamp('deleted_at')->nullable();
    $table->timestamp('deletion_requested_at')->nullable();
    $table->timestamps();
});
Schema::create('properties', function (Blueprint $table) {
    $table->id();
    $table->string('moderation_status')->default('approved');
    $table->timestamps();
});
Schema::create('clients', fn (Blueprint $table) => $table->id());
$legacyMigrations = [
    '2025_06_23_004353_create_locations_table.php',
    '2025_09_05_111317_create_construction_stages_table.php', '2025_09_05_111318_create_developers_table.php',
    '2025_09_05_111319_create_features_table.php', '2025_09_05_111320_create_materials_table.php',
    '2025_09_05_111321_create_new_buildings_table.php', '2025_09_05_111322_create_new_building_blocks_table.php',
    '2025_09_05_111323_create_developer_units_table.php', '2025_09_05_111324_create_developer_unit_photos_table.php',
    '2025_09_05_111325_create_feature_new_building_table.php', '2025_09_05_111326_create_new_building_photos_table.php',
    '2025_12_03_180553_add_district_to_new_buildings_table.php', '2025_12_11_151438_add_ceiling_height_to_new_buildings_table.php',
    '2025_12_11_112422_add_moderation_status_and_window_view_to_developer_units_table.php',
    '2025_06_24_151549_create_favorites_table.php', '2025_06_23_004505_create_reviews_table.php',
    '2026_03_03_150000_prepare_reviews_table.php', '2026_03_07_120100_create_crm_audit_logs_table.php', '2026_03_07_120000_create_leads_table.php',
];
foreach ($legacyMigrations as $file) {
    $migrate($file);
}
$role = DB::table('roles')->insertGetId(['name' => 'QA admin', 'slug' => 'admin']);
$branch = DB::table('branches')->insertGetId(['name' => 'QA isolated branch']);
$actor = User::create(['name' => 'QA MySQL', 'phone' => '+992900000099', 'role_id' => $role, 'branch_id' => $branch]);
$legacyId = DB::table('new_buildings')->insertGetId(['title' => 'QA legacy only', 'created_by' => $actor->id, 'moderation_status' => 'approved', 'completion_at' => '2028-08-27 00:00:00']);
$legacyBlock = DB::table('new_building_blocks')->insertGetId(['new_building_id' => $legacyId, 'name' => 'Legacy A']);
$legacyUnit = DB::table('developer_units')->insertGetId(['new_building_id' => $legacyId, 'block_id' => $legacyBlock, 'name' => 'Legacy unknown rooms', 'area' => '42.50', 'bedrooms' => 0, 'bathrooms' => 0, 'total_price' => '315000.55', 'price_per_sqm' => '7411.78', 'moderation_status' => 'available']);
DB::table('properties')->insert(['id' => 1]);
DB::table('favorites')->insert(['user_id' => $actor->id, 'property_id' => 1]);
$original = [];
foreach (['new_buildings', 'new_building_blocks', 'developer_units', 'favorites'] as $table) {
    $original[$table] = (array) DB::table($table)->first();
}
$expansions = [
    '2026_08_28_120000_create_lead_intakes_table.php', '2026_08_28_130000_expand_residential_complex_inventory.php',
    '2026_08_28_140000_add_residential_media_metadata.php', '2026_08_28_141000_allow_unknown_unit_bathrooms.php',
    '2026_08_28_150000_expand_favorites_for_residential_objects.php', '2026_08_28_160000_add_residential_review_moderation.php',
    '2026_08_28_170000_create_payment_programs.php', '2026_08_28_175000_allow_unknown_building_characteristics.php',
    '2026_08_28_180000_create_residential_building_content.php', '2026_08_28_190000_create_residential_import_batches.php',
    '2026_08_28_200000_add_residential_media_variants.php', '2026_08_28_210000_create_residential_media_migrations.php',
];
foreach ($expansions as $file) {
    $migrate($file);
}
foreach ($original as $table => $row) {
    $check(array_intersect_key((array) DB::table($table)->first(), $row) === $row, 'expansion preserves legacy '.$table);
}
$check(DB::table('developer_units')->where('id', $legacyUnit)->value('rooms') === null, 'legacy zero bedrooms is not invented studio');
// Reversal is rehearsed ONLY before any new records have been accepted, on this disposable schema.
foreach (array_reverse($expansions) as $file) {
    $migrate($file, 'down');
}
foreach ($original as $table => $row) {
    $check((array) DB::table($table)->first() === $row, 'empty expansion reversal preserves '.$table);
}
foreach ($expansions as $file) {
    $migrate($file);
}
$check(true, 'all twelve additive migrations apply, reverse before new writes, and reapply');

$writer = app(InventoryWriter::class);
$structure = app(StructureWriter::class);
$query = app(InventoryQuery::class);
$location = DB::table('locations')->insertGetId(['city' => 'QA city']);
$building = $writer->building($actor, ['title' => 'QA MySQL current', 'location_id' => $location, 'address' => 'Synthetic only', 'data_verified_at' => now()->subMinute()->toDateTimeString(), 'publication_status' => 'published']);
$check($building->heating === null && $building->has_terrace === null, 'new unknown characteristics remain SQL NULL');
$block = $structure->save($actor, $building, 'blocks', ['name' => 'A', 'floors_from' => 1, 'floors_to' => 5]);
$entrance = $structure->save($actor, $building, 'entrances', ['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 5]);
$unitData = ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor' => 1, 'area' => '42.50', 'rooms' => 2, 'total_price' => '315000.55', 'pricing_basis' => 'total', 'publication_status' => 'published'];
$unit = $writer->unit($actor, $building, ['name' => 'A-1', 'number' => '1', 'position_on_floor' => 1, 'external_id' => 'qa-1', ...$unitData]);
$cheap = $writer->unit($actor, $building, ['name' => 'A-2', ...$unitData, 'number' => '2', 'position_on_floor' => 2, 'external_id' => 'qa-2', 'rooms' => 1, 'total_price' => '100000.00']);
$check($unit->total_price === '315000.55' && $unit->price_per_sqm === '7411.78', 'DECIMAL prices retain cents and rounded derived value');
$check(! $query->buildings(['rooms' => ['2'], 'price_max' => 100000])->whereKey($building->id)->exists(), 'same-lot catalog AND does not combine different apartments');
$check($query->units($building->id, ['rooms' => ['2']])->count() === 1, 'unit filters match the actual apartment');
$check($query->roomsSummary([$building->id])->get($building->id)->count() === 2, 'rooms summary executes with ONLY_FULL_GROUP_BY');
$cheap = $writer->unit($actor, $building, ['version' => $cheap->version, 'availability_status' => 'reserved'], $cheap);
$aggregate = $query->withAggregates($query->buildings([])->whereKey($building->id))->first();
$check($aggregate->available_count === 1 && $aggregate->reserved_count === 1 && $aggregate->min_total_price === '315000.55', 'reserved apartment is excluded from minimum price and free count');
$check($query->units($building->id, ['include_reserved' => true])->count() === 2, 'reserved is included only explicitly');
$before = $unit->getAttributes();
try {
    $writer->unit($actor, $building, ['version' => 999, 'total_price' => '1'], $unit);
    throw new RuntimeException('stale version was accepted');
} catch (HttpExceptionInterface $error) {
    $check($error->getStatusCode() === 409 && $unit->fresh()->getAttributes() === $before, 'stale version refuses write without changing data');
}
try {
    $writer->unit($actor, $building, ['name' => 'duplicate', 'number' => '1', ...$unitData]);
    throw new RuntimeException('duplicate number accepted');
} catch (Illuminate\Validation\ValidationException $error) {
    $check(isset($error->errors()['number']), 'writer rejects a duplicate apartment number before insertion');
}
$duplicate = $unit->getAttributes();
unset($duplicate['id']);
foreach (['number' => 'units_entrance_number_unique', 'position_on_floor' => 'units_floor_position_unique', 'external_id' => 'units_external_id_unique'] as $field => $index) {
    $values = [...$duplicate, 'number' => null, 'position_on_floor' => null, 'external_id' => null];
    $values[$field] = $duplicate[$field];
    try {
        DB::table('developer_units')->insert($values);
        throw new RuntimeException('duplicate '.$field.' accepted by database');
    } catch (Illuminate\Database\QueryException $error) {
        $check((int) $error->errorInfo[1] === 1062 && str_contains($error->getMessage(), $index), 'InnoDB enforces '.$index);
    }
}
$second = new PDO('mysql:unix_socket='.$socket.';dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
DB::beginTransaction();
try {
    DB::table('developer_units')->where('id', $unit->id)->lockForUpdate()->first();
    $second->beginTransaction();
    try {
        $second->query('SELECT id FROM developer_units WHERE id='.(int) $unit->id.' FOR UPDATE NOWAIT');
        throw new RuntimeException('row lock not enforced');
    } catch (PDOException $error) {
        $check((int) $error->errorInfo[1] === 3572, 'independent connection cannot take the same row lock');
    } finally {
        $second->rollBack();
    }
} finally {
    DB::rollBack();
}
$building = $writer->building($actor, ['version' => $building->fresh()->version, 'completion_precision' => 'date', 'completion_at' => '2029-05-10'], $building);
$building = $writer->building($actor, ['version' => $building->version, 'completion_precision' => 'unknown'], $building);
$check($building->completion_at === null, 'explicit unknown precision clears exact date on MySQL');
$check($query->sortBuildingsByCompletion($query->buildings([]))->pluck('id')->last() === $building->id, 'unknown completion sorts after dated legacy building');

$csvPath = $run.'/units.csv';
file_put_contents($csvPath, "external_id;name;area;rooms;total_price;publication_status\nqa-csv;QA CSV;50,5;2;50500.55;published\n");
$rows = app(InventoryCsv::class)->parse(new UploadedFile($csvPath, 'units.csv', 'text/csv', null, true));
$importer = app(InventoryImport::class);
$count = DeveloperUnit::count();
$auditCount = DB::table('crm_audit_logs')->count();
$batch = $importer->preview($actor, $building, 'csv', $rows, 'units.csv');
$check($batch->counts['created'] === 1 && DeveloperUnit::count() === $count && DB::table('crm_audit_logs')->count() === $auditCount, 'CSV dry-run rolls back inventory and audit writes');
$importer->apply($actor, $building, $batch->id);
$check(DeveloperUnit::count() === $count + 1, 'CSV apply creates one real lot');
$importer->apply($actor, $building, $batch->id);
$repeat = $importer->preview($actor, $building, 'csv', $rows, 'units.csv');
$check(DeveloperUnit::count() === $count + 1 && $repeat->counts['unchanged'] === 1, 'CSV retry and repeated import do not duplicate lots');
$bulkRows = [
    ['line' => 1, 'id' => $unit->id, 'data' => ['total_price' => '75000.55']],
    ['line' => 2, 'id' => $cheap->id, 'data' => ['total_price' => '75000.55']],
];
$bulk = $importer->preview($actor, $building, 'bulk', $bulkRows);
$check($bulk->counts['updated'] === 2 && $bulk->counts['errors'] === 0, 'two-row bulk preview accepts exact prices');
$cheap = $writer->unit($actor, $building, ['version' => $cheap->version, 'change_reason' => 'QA concurrent edit', 'total_price' => '110000.22'], $cheap);
$firstBefore = $unit->fresh()->getAttributes();
$secondBefore = $cheap->getAttributes();
$auditBefore = DB::table('crm_audit_logs')->count();
try {
    $importer->apply($actor, $building, $bulk->id);
    throw new RuntimeException('stale second row accepted');
} catch (HttpExceptionInterface $error) {
    $check($error->getStatusCode() === 409 && $unit->fresh()->getAttributes() === $firstBefore && $cheap->fresh()->getAttributes() === $secondBefore && DB::table('crm_audit_logs')->count() === $auditBefore && $bulk->fresh()->status === 'preview', 'conflict in second row rolls back first row and audit without losing concurrent edit');
}
$leadInput = ['service_type' => 'residential', 'name' => 'QA MySQL lead', 'phone' => '+992900000099', 'source' => 'local-mysql-rehearsal', 'consent' => true, 'consent_version' => 'qa', 'idempotency_key' => 'qa-mysql-idempotency-only', 'context' => ['building_id' => $building->id, 'unit_id' => $unit->id, 'expected_version' => $unit->version, 'total_price' => '1.00', 'responsible_agent_id' => 99999]];
$receipt = app(PublicLeadIntake::class)->accept($leadInput);
$again = app(PublicLeadIntake::class)->accept($leadInput);
$check($receipt['lead_id'] === $again['lead_id'] && DB::table('leads')->count() === 1 && DB::table('lead_intakes')->count() === 1, 'internal CRM intake is idempotent on MySQL');
$lead = DB::table('leads')->find($receipt['lead_id']);
$meta = json_decode($lead->meta, true, 512, JSON_THROW_ON_ERROR);
$check($meta['context']['total_price'] === '315000.55' && $lead->responsible_agent_id === $actor->id && $lead->branch_id === $branch && $unit->fresh()->getAttributes() === $firstBefore, 'CRM stores authoritative decimal price and Aura consultant without reserving the apartment');
try {
    app(PublicLeadIntake::class)->accept([...$leadInput, 'name' => 'Different QA request']);
    throw new RuntimeException('changed idempotency payload accepted');
} catch (HttpExceptionInterface $error) {
    $check($error->getStatusCode() === 409 && DB::table('leads')->count() === 1 && DB::table('lead_intakes')->count() === 1, 'changed payload with same key is rejected without another lead or receipt');
}

// Snapshots are made on first modification of a LEGACY row, not on ordinary modern writes.
$legacyBuilding = NewBuilding::findOrFail($legacyId);
$legacyRecord = DeveloperUnit::findOrFail($legacyUnit);
$legacyBefore = $legacyRecord->getAttributes();
$changed = $writer->unit($actor, $legacyBuilding, ['version' => $legacyRecord->version, 'publication_status' => 'draft'], $legacyRecord);
$snapshot = DB::table('residential_inventory_snapshots')->where('entity_type', 'developer_unit')->where('entity_id', $legacyUnit)->first();
$savedOriginal = $snapshot ? json_decode($snapshot->original_values, true, 512, JSON_THROW_ON_ERROR) : [];
// MySQL sorts JSON object keys; compare values and types strictly, independently of key order.
ksort($savedOriginal);
ksort($legacyBefore);
$check($savedOriginal === $legacyBefore, 'MySQL JSON snapshot retains every original legacy field and exact decimal strings');
$check($changed->rooms === null && $changed->bedrooms === 0 && $changed->total_price === $legacyRecord->total_price && $changed->price_per_sqm === $legacyRecord->price_per_sqm, 'legacy first edit does not invent studio or recalculate original prices');
$writer->unit($actor, $legacyBuilding, ['version' => $changed->version, 'name' => 'QA reviewed legacy'], $changed);
$check(DB::table('residential_inventory_snapshots')->where('entity_type', 'developer_unit')->where('entity_id', $legacyUnit)->count() === 1, 'further edits keep the original legacy snapshot without duplicates');
$recovery = isset($argv[2]) ? \Tests\Support\ResidentialMysqlRecovery::run($root, $run, $database, $leadInput, $check) : null;
file_put_contents($run.'/report.json', json_encode(['status' => 'passed', 'server' => $server, 'database' => $database, 'checks' => $checks, 'migrations' => $migrations, 'recovery' => $recovery], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($checks).' checks passed. Report: '.$run.'/report.json'.PHP_EOL;
