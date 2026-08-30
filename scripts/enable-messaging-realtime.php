#!/usr/bin/env php
<?php

$environmentPath = dirname(__DIR__).'/.env';
$contents = @file_get_contents($environmentPath);

if (! is_string($contents)) {
    fwrite(STDERR, "Application environment file is unavailable.\n");
    exit(1);
}

$updates = [
    'BROADCAST_CONNECTION' => 'reverb',
    'MESSAGING_REALTIME_BROADCAST_ENABLED' => 'true',
];

foreach ($updates as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $contents) === 1) {
        $contents = (string) preg_replace($pattern, $key.'='.$value, $contents, 1);
    } else {
        $contents = rtrim($contents).PHP_EOL.$key.'='.$value.PHP_EOL;
    }
}

$temporaryPath = $environmentPath.'.realtime-'.bin2hex(random_bytes(6));
$permissions = fileperms($environmentPath);

if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to prepare the application environment update.\n");
    exit(1);
}

if (is_int($permissions)) {
    chmod($temporaryPath, $permissions & 0777);
}

if (! rename($temporaryPath, $environmentPath)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Unable to activate the application environment update.\n");
    exit(1);
}

fwrite(STDOUT, "Messaging realtime environment flags enabled.\n");
