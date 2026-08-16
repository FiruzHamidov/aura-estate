<?php

namespace App\Contracts;

interface AttendanceDeviceProtocol
{
    /**
     * @return array{events:list<array<string, mixed>>, rejected:list<array<string, mixed>>}
     */
    public function parse(string $payload, string $timezone): array;
}
