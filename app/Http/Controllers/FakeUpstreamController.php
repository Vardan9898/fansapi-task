<?php

namespace App\Http\Controllers;

use App\Logging\RefreshLog;
use App\Upstream\Fake\FakeResponses;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Http\Response;

/**
 * Plays the upstream profile API: GET /fake-upstream/{account}/profiles/{username}.
 * Registered without the web middleware (no sessions/cookies), and never in production.
 */
class FakeUpstreamController
{
    public function __invoke(string $account, string $username, FakeUpstream $fake): Response
    {
        $step = $fake->next($account, $username);

        RefreshLog::debug('fake_upstream.served', ['fake_account' => $account, 'fake_username' => $username, ...$step]);

        if ($step['type'] === 'timeout') {
            sleep(config('upstream.fake.timeout_sleep'));
        }

        ['status' => $status, 'body' => $body] = FakeResponses::render($step);

        return response($body, $status, $body === '' ? [] : ['Content-Type' => 'application/json']);
    }
}
