<?php
declare(strict_types=1);

// The unprivileged runtime writes SQL to stdout. The parent opens the protected backup.
umask(0077);
$repo = '/var/www/aura-estate';
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = $app['db']->connection()->getConfig();
if (($config['driver'] ?? '') !== 'mysql') {
    throw new RuntimeException('Expected MySQL for the deployment backup');
}
$escape = static fn ($value): string => '"'.str_replace(
    ["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string) $value
).'"';
$file = tempnam(sys_get_temp_dir(), 'aura-db-');
if ($file === false) throw new RuntimeException('Cannot open temporary database options');
try {
    $options = "[client]\n";
    foreach (['user'=>'username', 'password'=>'password', 'host'=>'host', 'port'=>'port', 'socket'=>'unix_socket'] as $option => $key) {
        if (isset($config[$key]) && $config[$key] !== '') $options .= $option.'='.$escape($config[$key])."\n";
    }
    if (file_put_contents($file, $options) === false) throw new RuntimeException('Cannot write database options');
    $process = proc_open(['/usr/bin/mysqldump', '--defaults-extra-file='.$file, '--single-transaction',
        '--quick', '--skip-lock-tables', '--no-tablespaces', '--routines', '--triggers', '--events', '--', $config['database']],
        [0 => ['file','/dev/null','r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Database backup failed');
} finally {
    unlink($file);
}
