<?php

// Read-only reconciliation of the isolated HTTP benchmark and its actual audit records.
$database = realpath($argv[1] ?? '');
$reportPath = realpath($argv[2] ?? '');
if (PHP_SAPI !== 'cli' || ! $database || ! $reportPath
    || dirname($database) !== dirname($reportPath)) {
    throw new LogicException('Use the existing database/fixture and benchmark JSON in the same isolated directory.');
}
$report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
$expected = $report['audit_requests'] ?? [];
if (count($expected) !== 480 || count(array_unique(array_column($expected, 'trace_id'))) !== 480) {
    throw new LogicException('Expected four profiles, each with 20 warmup and 100 measured requests, with unique trace IDs.');
}
if (preg_match('#^/(private/)?tmp/aura-residential-load\.[A-Za-z0-9]+/database\.sqlite$#', $database)) {
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only=ON');
} elseif (preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+/load-fixture\.json$#', $database)) {
    $root = dirname($database);
    $fixture = json_decode(file_get_contents($database), true, 512, JSON_THROW_ON_ERROR);
    $name = $fixture['database'] ?? '';
    if ((fileperms($root) & 0077) !== 0 || ! preg_match('/^aura_residential_qa_[a-f0-9]{8}$/', $name)
        || ! file_exists($root.'/mysql.sock') || is_link($root.'/mysql.sock')) {
        throw new LogicException('Require the dedicated socket-only MySQL load fixture.');
    }
    $pdo = new PDO('mysql:unix_socket='.$root.'/mysql.sock;dbname='.$name, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server = $pdo->query('SELECT @@datadir AS datadir, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
    if (realpath($server['datadir']) !== $root.'/data' || (int) $server['isolated'] !== 1) {
        throw new LogicException('Refusing MySQL outside the dedicated sandbox.');
    }
    $pdo->exec('START TRANSACTION READ ONLY');
} else {
    throw new LogicException('Only isolated SQLite/MySQL load fixtures are allowed.');
}
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$check((int) $pdo->query('SELECT COUNT(*) FROM new_buildings')->fetchColumn() === 100, 'Expected 100 synthetic complexes.');
$check((int) $pdo->query('SELECT COUNT(*) FROM developer_units')->fetchColumn() === 10000, 'Expected 10000 unique lots.');
$query = $pdo->prepare('SELECT * FROM api_request_logs WHERE trace_id IN ('.implode(',', array_fill(0, count($expected), '?')).')');
$query->execute(array_column($expected, 'trace_id'));
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$check(count($rows) === count($expected), 'Missing or unexpected audit records.');
$byTrace = [];
foreach ($rows as $row) {
    $check(! isset($byTrace[$row['trace_id']]), 'Duplicate trace in API audit.');
    $byTrace[$row['trace_id']] = $row;
}
foreach ($expected as $request) {
    $row = $byTrace[$request['trace_id']] ?? null;
    $check($row !== null, 'A benchmark request is absent from the audit.');
    $check($row['path'] === $request['path'] && $row['method'] === 'GET' && (int) $row['status_code'] === 200, 'Audit route/method/status differs from the benchmark.');
    $check($row['duration_ms'] !== null && (int) $row['duration_ms'] >= 0, 'Missing request duration.');
    $check($row['request_query'] === null && $row['request_body'] === null && $row['error_message'] === null, 'Residential audit must not retain query/body/raw errors.');
    $check($row['user_id'] === null && $row['role_slug'] === null, 'Public benchmark unexpectedly has an authenticated actor.');
}
$durations = array_column($rows, 'duration_ms');
sort($durations, SORT_NUMERIC);
echo json_encode([
    'status' => 'passed', 'requests' => count($expected), 'audit_records' => count($rows),
    'stored_audit_records' => (int) $pdo->query('SELECT COUNT(*) FROM api_request_logs')->fetchColumn(),
    'unique_traces' => count($byTrace), 'query_body_error_fields' => 'all null',
    'audit_duration_p95_ms' => $durations[(int) ceil(count($durations) * 0.95) - 1],
    'note' => 'Audit duration excludes the audit insert; use HTTP benchmark timings for end-to-end cost.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
