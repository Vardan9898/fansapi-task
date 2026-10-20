<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Monolog\Handler\NullHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real refresh log shown in /log-viewer.
        // Tests that assert on logging use Log::spy() / Log::shouldReceive() instead.
        config(['logging.channels.refresh' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
    }
}
