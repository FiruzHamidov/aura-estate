<?php

namespace Tests\Unit;

use App\Support\ReverbProbeResponse;
use PHPUnit\Framework\TestCase;

class ReverbProbeResponseTest extends TestCase
{
    private const KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    private function probe(string $response): string
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $response);
        rewind($stream);
        try {
            return ReverbProbeResponse::read($stream, self::KEY);
        } finally {
            fclose($stream);
        }
    }

    private function response(string $event, array $data): string
    {
        $json = json_encode(['event' => $event, 'data' => json_encode($data)]);
        $frame = "\x81".(strlen($json) < 126 ? chr(strlen($json)) : "\x7e".pack('n', strlen($json))).$json;

        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n".$frame;
    }

    public function test_only_a_valid_pusher_connection_is_ready(): void
    {
        $this->assertSame('connected', $this->probe($this->response('pusher:connection_established', [
            'socket_id' => '123456.789012', 'activity_timeout' => 30,
        ])));
        $this->assertSame('failed', $this->probe($this->response('pusher:connection_established', [])));
    }

    public function test_origin_error_after_http_upgrade_is_an_explicit_rejection(): void
    {
        $this->assertSame('origin_rejected', $this->probe($this->response('pusher:error', [
            'code' => 4009, 'message' => 'Origin not allowed',
        ])));
        $this->assertSame('origin_rejected', $this->probe("HTTP/1.1 403 Forbidden\r\n\r\n"));
    }

    public function test_wrong_app_or_backend_failure_is_not_an_origin_check_pass(): void
    {
        $this->assertSame('failed', $this->probe($this->response('pusher:error', [
            'code' => 4001, 'message' => 'Application does not exist',
        ])));
        $this->assertSame('failed', $this->probe("HTTP/1.1 502 Bad Gateway\r\n\r\n"));
    }

    public function test_incomplete_or_forged_upgrade_never_passes(): void
    {
        $valid = $this->response('pusher:connection_established', ['socket_id' => '123.456']);
        $this->assertSame('failed', $this->probe(substr($valid, 0, -3)));
        $this->assertSame('failed', $this->probe(str_replace('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'invalid', $valid)));
        $this->assertSame('failed', $this->probe("HTTP/1.1 101 Switching Protocols\r\n"));
    }
}
