<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! preg_match('#^(/private)?/tmp/aura-residential-load\.[A-Za-z0-9]+/database\.sqlite$#', $target) || file_exists($target) || ! is_dir(dirname($target))) {
    throw new LogicException('Use a new mktemp directory aura-residential-load.XXXXXX and a nonexistent database.sqlite.');
}
Tests\Support\ResidentialSchema::create();
(require database_path('migrations/2026_06_03_120000_create_api_request_logs_table.php'))->up();
Tests\Support\ResidentialLoadFixture::seed();
Illuminate\Support\Facades\DB::statement('VACUUM INTO ?', [$target]);
echo "Created isolated load fixture: 100 complexes, 10000 lots; complex 1 has 1000 lots.\n";
