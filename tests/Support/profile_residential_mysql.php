<?php

// Read-only SQL diagnosis against the dedicated synthetic MySQL load fixture.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$fixture = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || ! $fixture || ! preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+/load-fixture\.json$#', $fixture)) {
    throw new LogicException('Use the dedicated MySQL load fixture metadata.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || $app->configurationIsCached()) {
    throw new LogicException('Bootstrap only with uncached testing/SQLite :memory:.');
}
$root = dirname($fixture);
$metadata = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
$name = $metadata['database'] ?? '';
if (! preg_match('/^aura_residential_qa_[a-f0-9]{8}$/', $name)) {
    throw new LogicException('Unexpected fixture database name.');
}
config(['database.default' => 'residential_mysql', 'database.connections.residential_mysql' => [
    'driver' => 'mysql', 'unix_socket' => $root.'/mysql.sock', 'host' => 'localhost', 'port' => 0,
    'database' => $name, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
Tests\Support\ResidentialSchema::assertIsolatedMysql($root);
Illuminate\Support\Facades\DB::statement('START TRANSACTION READ ONLY');
foreach ([
    [fn () => Tests\Support\ResidentialSchema::createMysql($root), 'MySQL fixture database must be new and empty; existing tables are never dropped.'],
    [fn () => Tests\Support\ResidentialLoadFixture::seed($root), 'Load fixture requires an empty inventory.'],
] as [$operation, $expectedMessage]) {
    try {
        $operation();
        throw new RuntimeException('Existing MySQL fixture was not rejected.');
    } catch (LogicException $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            throw $exception;
        }
    }
}
Illuminate\Support\Facades\DB::enableQueryLog();
$response = $app->make(App\Http\Controllers\NewBuildingController::class)->index(
    Illuminate\Http\Request::create('/api/new-buildings', 'GET', ['rooms' => ['2'], 'price_max' => 900000, 'area_min' => 50, 'per_page' => 15])
);
$queries = Illuminate\Support\Facades\DB::getQueryLog();
Illuminate\Support\Facades\DB::disableQueryLog();
foreach ($queries as &$query) {
    $query['explain'] = Illuminate\Support\Facades\DB::select('EXPLAIN ANALYZE '.$query['query'], $query['bindings']);
}
unset($query);
$parity = null;
if (isset($argv[2])) {
    $baselinePath = realpath($argv[2]);
    if (! $baselinePath || dirname($baselinePath) !== $root) {
        throw new LogicException('SQL baseline must belong to this sandbox.');
    }
    $baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['queries'];
    if (count($baseline) !== count($queries)) {
        throw new LogicException('SQL result-set count changed; inspect the baseline manually.');
    }
    $read = function (array $query): array {
        if (! preg_match('/^select /i', $query['query'])) {
            throw new LogicException('Only captured SELECT queries are permitted.');
        }

        return array_map(function ($row) {
            $row = (array) $row;
            ksort($row);

            return $row;
        }, Illuminate\Support\Facades\DB::select($query['query'], $query['bindings']));
    };
    foreach ($queries as $index => $query) {
        if ($read($baseline[$index]) !== $read($query)) {
            throw new RuntimeException('SQL result differs from baseline at query '.$index);
        }
    }
    $parity = count($queries).' result sets identical to the original SELECTs';
}
echo json_encode(['status' => $response->getStatusCode(), 'nonempty_fixture_guards' => 'passed', 'baseline_parity' => $parity, 'queries' => $queries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
Illuminate\Support\Facades\DB::rollBack();
