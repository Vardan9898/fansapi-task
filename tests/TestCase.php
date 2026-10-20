<?php

namespace Tests;

use App\Logging\RefreshLog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep test runs out of the real refresh log shown in /log-viewer.
        // Only the handler is swapped; the channel keeps its redaction tap.
        config([
            'logging.channels.refresh.driver' => 'monolog',
            'logging.channels.refresh.handler' => NullHandler::class,
        ]);
    }

    /**
     * Route the real `refresh` channel (taps included) into an in-memory handler to assert on.
     */
    protected function captureRefreshLog(): TestHandler
    {
        config(['logging.channels.refresh.handler' => TestHandler::class]);
        Log::forgetChannel(RefreshLog::CHANNEL);

        return Log::channel(RefreshLog::CHANNEL)->getLogger()->getHandlers()[0];
    }
}
