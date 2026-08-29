<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || ! $root || ! preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+$#', $root)
    || (fileperms($root) & 0077) !== 0 || ! file_exists($root.'/mysql.sock') || is_link($root.'/mysql.sock')) {
    throw new LogicException('Use a dedicated socket-only MySQL in a private mktemp sandbox.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || $app->configurationIsCached()) {
    throw new LogicException('Bootstrap only with uncached testing/SQLite :memory:.');
}
$pdo = new PDO('mysql:unix_socket='.$root.'/mysql.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT @@version AS version, @@datadir AS datadir, @@skip_networking AS isolated, @@sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
if (realpath($server['datadir']) !== $root.'/data' || (int) $server['isolated'] !== 1) {
    throw new LogicException('Refusing an existing or network-enabled MySQL server.');
}
$database = 'aura_residential_qa_'.bin2hex(random_bytes(4));
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.default' => 'residential_mysql', 'database.connections.residential_mysql' => [
    'driver' => 'mysql', 'unix_socket' => $root.'/mysql.sock', 'host' => 'localhost', 'port' => 0,
    'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
Tests\Support\ResidentialSchema::createMysql($root);
(require database_path('migrations/2026_06_03_120000_create_api_request_logs_table.php'))->up();
Tests\Support\ResidentialLoadFixture::seed($root);
echo json_encode([
    'root' => $root, 'database' => $database, 'server' => $server,
    'complexes' => App\Models\NewBuilding::count(), 'units' => App\Models\DeveloperUnit::count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
