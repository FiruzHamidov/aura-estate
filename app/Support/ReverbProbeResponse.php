<?php

namespace App\Support;

final class ReverbProbeResponse
{
    /** Read the upgrade and first Pusher event; HTTP 101 alone is not readiness. */
    public static function read($stream, string $key): string
    {
        $status = fgets($stream, 512);
        if (! is_string($status) || ! preg_match('/^HTTP\/1\.[01] (\d{3})\b/', $status, $matches)) {
            return 'failed';
        }

        $headers = [];
        $bytes = strlen($status);
        while (($line = fgets($stream, 4096)) !== false) {
            $bytes += strlen($line);
            if ($bytes > 16384) {
                return 'failed';
            }
            if ($line === "\r\n") {
                break;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        if ($line !== "\r\n") {
            return 'failed';
        }
        if ($matches[1] === '403') {
            return 'origin_rejected';
        }
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($matches[1] !== '101' || ! hash_equals($accept, $headers['sec-websocket-accept'] ?? '')) {
            return 'failed';
        }

        $head = self::readExactly($stream, 2);
        // Reverb's initial event is one unmasked text frame, without extensions.
        if ($head === null || ord($head[0]) !== 0x81 || (ord($head[1]) & 0x80)) {
            return 'failed';
        }
        $length = ord($head[1]);
        if ($length === 126) {
            $extended = self::readExactly($stream, 2);
            if ($extended === null) {
                return 'failed';
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            return 'failed';
        }
        if ($length > 4096 || ($payload = self::readExactly($stream, $length)) === null) {
            return 'failed';
        }
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return 'failed';
        }
        $data = $event['data'] ?? null;
        $data = is_string($data) ? json_decode($data, true) : $data;
        if (! is_array($data)) {
            return 'failed';
        }
        if (($event['event'] ?? '') === 'pusher:connection_established'
            && is_string($data['socket_id'] ?? null)
            && preg_match('/^\d+\.\d+$/', $data['socket_id'])) {
            return 'connected';
        }
        if (($event['event'] ?? '') === 'pusher:error' && ($data['code'] ?? null) === 4009) {
            return 'origin_rejected';
        }

        return 'failed';
    }

    private static function readExactly($stream, int $length): ?string
    {
        $result = '';
        while (strlen($result) < $length) {
            $part = fread($stream, $length - strlen($result));
            if ($part === false || $part === '') {
                return null;
            }
            $result .= $part;
        }

        return $result;
    }
}
