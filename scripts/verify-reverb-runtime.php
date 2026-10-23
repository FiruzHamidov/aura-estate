#!/usr/bin/env php
<?php

use App\Support\ReverbProbeResponse;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectEnabled = in_array('--expect-enabled', $argv, true);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$queue = (string) config('queue.default');
$connection = (array) config('broadcasting.connections.reverb', []);
$server = (array) config('reverb.servers.reverb', []);
$application = (array) config('reverb.apps.apps.0', []);
$options = (array) ($connection['options'] ?? []);
$appOptions = (array) ($application['options'] ?? []);
$host = (string) ($options['host'] ?? '');
$port = (int) ($options['port'] ?? 0);
$scheme = (string) ($options['scheme'] ?? '');
$key = (string) ($connection['key'] ?? '');
$allowedOrigins = array_values(array_filter((array) ($application['allowed_origins'] ?? [])));

$check(! in_array($queue, ['', 'sync', 'null'], true), 'QUEUE_CONNECTION must be durable and asynchronous.');
$check((string) ($connection['driver'] ?? '') === 'reverb', 'The Reverb broadcast connection is missing.');
$check($host !== '' && $port > 0, 'The public Reverb host and port are required.');
$check($host === 'backend.aura.tj' && $port === 443, 'Production Reverb must use backend.aura.tj:443.');
$check($scheme === 'https', 'Production Reverb must use TLS.');
$check($key !== '', 'REVERB_APP_KEY is required.');
$check((string) ($connection['secret'] ?? '') !== '', 'REVERB_APP_SECRET is required.');
$check((string) ($connection['app_id'] ?? '') !== '', 'REVERB_APP_ID is required.');
$check(hash_equals($key, (string) ($application['key'] ?? '')), 'Broadcaster and Reverb application keys do not match.');
$check(hash_equals((string) ($connection['app_id'] ?? ''), (string) ($application['app_id'] ?? '')), 'Broadcaster and Reverb application IDs do not match.');
$check(in_array('aura.tj', $allowedOrigins, true), 'REVERB_ALLOWED_ORIGINS must include the hostname aura.tj (without a scheme).');
$check(! in_array('*', $allowedOrigins, true), 'Wildcard Reverb origins are forbidden.');
$check((string) ($application['accept_client_events_from'] ?? 'none') === 'none', 'Client-originated Reverb events must be disabled.');
$check((string) ($server['host'] ?? '') !== '' && (int) ($server['port'] ?? 0) > 0, 'The internal Reverb listener is not configured.');
$check((string) ($server['host'] ?? '') === '127.0.0.1', 'Production Reverb must listen on loopback behind Nginx.');
$check((int) ($server['port'] ?? 0) === 8080, 'The internal Reverb listener must use port 8080.');

if ($expectEnabled) {
    $check(config('broadcasting.default') === 'reverb', 'BROADCAST_CONNECTION must be reverb.');
    $check((bool) config('messaging.realtime_broadcast_enabled'), 'Messaging realtime must be enabled.');
}

if ($failures === []) {
    $handshake = static function (string $origin) use ($host, $port, $key, $server): string {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            'tls://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            8,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($socket)) {
            return 'failed';
        }

        stream_set_timeout($socket, 8);
        $pathPrefix = trim((string) ($server['path'] ?? ''), '/');
        $path = ($pathPrefix === '' ? '' : '/'.$pathPrefix)
            .'/app/'.rawurlencode($key)
            .'?protocol=7&client=aura-deploy&version=1.0&flash=false';
        $webSocketKey = base64_encode(random_bytes(16));
        $hostHeader = $port === 443 ? $host : $host.':'.$port;
        $request = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$hostHeader}\r\n"
            ."Origin: {$origin}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$webSocketKey}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($socket, $request);
        $result = ReverbProbeResponse::read($socket, $webSocketKey);
        fclose($socket);

        return $result;
    };

    $allowedStatus = $handshake('https://aura.tj');
    $deniedStatus = $handshake('https://not-allowed.invalid');
    $check($allowedStatus === 'connected', 'The approved-origin WebSocket did not establish a Pusher connection.');
    $check($deniedStatus === 'origin_rejected', 'The foreign-origin WebSocket was not explicitly rejected.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'Reverb readiness: '.$failure.PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, 'Reverb configuration, TLS proxy and origin boundary: OK'.PHP_EOL);
