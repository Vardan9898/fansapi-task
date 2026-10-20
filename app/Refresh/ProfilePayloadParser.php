<?php

namespace App\Refresh;

use App\Upstream\UpstreamResponse;

/**
 * Turns a 2xx upstream body into a ParsedProfile, or throws InvalidPayload.
 *
 * Accepted shapes (the upstream changed from the first to the second):
 *   top_level: {"likes": 120000, "revision": 10}
 *   nested:    {"profile": {"likes": 121000}, "revision": 11}
 *
 * Rules: a missing value is an error, never 0; likes and revision must be integers >= 0;
 * if both shapes are present they must agree.
 */
class ProfilePayloadParser
{
    public function parse(UpstreamResponse $response): ParsedProfile
    {
        if ($response->truncated) {
            throw new InvalidPayload('Response body exceeds the size limit');
        }

        $data = $response->json();

        if ($data === null) {
            throw new InvalidPayload($response->body === '' ? 'Empty response body' : 'Response body is not a JSON object');
        }

        $topLevel = array_key_exists('likes', $data);
        $nested = is_array($data['profile'] ?? null) && array_key_exists('likes', $data['profile']);

        $format = match (true) {
            $topLevel && $nested => 'both',
            $topLevel => 'top_level',
            $nested => 'nested',
            default => throw new InvalidPayload('likes not found at top level or in profile', 'unknown'),
        };

        if ($format === 'both') {
            if ($data['likes'] !== $data['profile']['likes']) {
                throw new InvalidPayload('likes differs between top level and profile', 'both');
            }
            $format = 'nested';
        }

        $likes = $format === 'nested' ? $data['profile']['likes'] : $data['likes'];

        if (! is_int($likes) || $likes < 0) {
            throw new InvalidPayload('likes must be an integer >= 0, got '.json_encode($likes), $format);
        }

        $revision = $data['revision'] ?? null;

        if (! is_int($revision) || $revision < 0) {
            throw new InvalidPayload('revision must be an integer >= 0, got '.json_encode($revision), $format);
        }

        return new ParsedProfile($likes, $revision, $format);
    }
}
