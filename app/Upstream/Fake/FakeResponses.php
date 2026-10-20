<?php

namespace App\Upstream\Fake;

use InvalidArgumentException;

/**
 * The response shapes from the brief. tests/Fixtures/upstream/*.json are the canonical
 * examples; FakeResponsesTest keeps this renderer and the fixtures in sync.
 */
class FakeResponses
{
    public const TYPES = [
        'old_format',   // 200 {"likes": N, "revision": R}            (before the upstream change)
        'new_format',   // 200 {"profile": {"likes": N}, "revision": R} (after the change)
        'invalid',      // 200 {"revision": R}  - likes missing in both places
        'rate_limited', // 429, empty body, no Retry-After
        'server_error', // 500, empty body
        'not_found',    // 404
        'unauthorized', // 401 (bad credentials: blocks the account)
        'timeout',      // sleeps past the client timeout
        'oversized',    // 200 with a body bigger than upstream.max_body_bytes (memory guard)
    ];

    /**
     * @param  array{type: string, likes?: int, revision?: int}  $step
     * @return array{status: int, body: string}
     */
    public static function render(array $step): array
    {
        $likes = $step['likes'] ?? 0;
        $revision = $step['revision'] ?? 0;

        return match ($step['type']) {
            'old_format' => ['status' => 200, 'body' => json_encode(['likes' => $likes, 'revision' => $revision])],
            'new_format' => ['status' => 200, 'body' => json_encode(['profile' => ['likes' => $likes], 'revision' => $revision])],
            'invalid' => ['status' => 200, 'body' => json_encode(['revision' => $revision])],
            'rate_limited' => ['status' => 429, 'body' => ''],
            'server_error' => ['status' => 500, 'body' => ''],
            'not_found' => ['status' => 404, 'body' => json_encode(['error' => 'Profile not found'])],
            'unauthorized' => ['status' => 401, 'body' => json_encode(['error' => 'Unauthenticated'])],
            'timeout' => ['status' => 200, 'body' => '{}'], // only reached after the client gave up
            'oversized' => ['status' => 200, 'body' => json_encode(['likes' => $likes, 'revision' => $revision, 'padding' => str_repeat('x', config('upstream.max_body_bytes') + 1)])],
            default => throw new InvalidArgumentException("Unknown fake response type [{$step['type']}]"),
        };
    }
}
