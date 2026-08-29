<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A regression run must never deliver real SMS, Telegram messages or
        // provider requests. Each integration test supplies its own HTTP fake.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
