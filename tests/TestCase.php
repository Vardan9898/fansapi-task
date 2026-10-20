<?php

namespace Tests;

use App\Logging\RefreshLog;
use App\Upstream\Fake\FakeResponses;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    /**
     * Serve upstream HTTP calls in-process from the same Redis-scripted scenarios as the live
     * fake server (no web server needed). A "timeout" step becomes a curl timeout error.
     */
    protected function routeUpstreamToFake(): FakeUpstream
    {
        $fake = app(FakeUpstream::class);
        $fake->reset();

        Http::fake(function (Request $request) use ($fake) {
            preg_match('#/fake-upstream/([^/]+)/profiles/([^/?]+)#', $request->url(), $match);
            $step = $fake->next(rawurldecode($match[1]), rawurldecode($match[2]));

            if ($step['type'] === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out (fake)');
            }

            ['status' => $status, 'body' => $body] = FakeResponses::render($step);

            return Http::response($body, $status);
        });

        return $fake;
    }
}
