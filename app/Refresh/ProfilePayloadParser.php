<?php

namespace App\Refresh;

use App\Upstream\UpstreamResponse;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a 2xx upstream body into a ParsedProfile, or throws InvalidPayload.
 *
 * Accepted shapes:
 *   top_level: {"likes": 120000, "revision": 10}              (the brief's upstream, before the change)
 *   nested:    {"profile": {"likes": 121000}, "revision": 11}  (the brief's upstream, after the change)
 *   fansapi:   {"data": {"favoritedCount": 609771, ...}, "_meta": {"_cache": {...}}}   (real FansAPI)
 *
 * Rules: a missing value is an error, never 0; likes and revision must be integers >= 0;
 * if both brief shapes are present they must agree.
 */
class ProfilePayloadParser
{
    /**
     * Optional FansAPI profile fields we keep: our column => [FansAPI key, type].
     */
    private const FANSAPI_DETAILS = [
        'upstream_id' => ['id', 'int'],
        'name' => ['name', 'string'],
        'about' => ['about', 'string'],
        'avatar_url' => ['avatar', 'url'],
        'header_url' => ['header', 'url'],
        'posts_count' => ['postsCount', 'int'],
        'photos_count' => ['photosCount', 'int'],
        'videos_count' => ['videosCount', 'int'],
        'audios_count' => ['audiosCount', 'int'],
        'medias_count' => ['mediasCount', 'int'],
        'is_verified' => ['isVerified', 'bool'],
        'subscribe_price' => ['subscribePrice', 'number'],
        'join_date' => ['joinDate', 'datetime'],
    ];

    public function parse(UpstreamResponse $response): ParsedProfile
    {
        if ($response->truncated) {
            throw new InvalidPayload('Response body exceeds the size limit');
        }

        $data = $response->json();

        if ($data === null) {
            throw new InvalidPayload($response->body === '' ? 'Empty response body' : 'Response body is not a JSON object');
        }

        if (is_array($data['data'] ?? null)) {
            return $this->parseFansApi($data, $response);
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

        $likes = $this->likes($format === 'nested' ? $data['profile']['likes'] : $data['likes'], $format);

        $revision = $data['revision'] ?? null;

        if (! is_int($revision) || $revision < 0) {
            throw new InvalidPayload('revision must be an integer >= 0, got '.json_encode($revision), $format);
        }

        return new ParsedProfile($likes, $revision, $format);
    }

    /**
     * FansAPI has no revision. The data's own timestamp is used instead (milliseconds):
     *   cached answer -> _meta._cache.cached_at (when FansAPI fetched it from OnlyFans)
     *   fresh answer  -> the response's Date header (same server clock)
     * so an older cached copy arriving after newer data is recognised as stale.
     */
    private function parseFansApi(array $data, UpstreamResponse $response): ParsedProfile
    {
        $profile = $data['data'];

        if (! array_key_exists('favoritedCount', $profile)) {
            throw new InvalidPayload('data.favoritedCount (likes) not found', 'fansapi');
        }

        $likes = $this->likes($profile['favoritedCount'], 'fansapi');

        $cache = $data['_meta']['_cache'] ?? [];
        $versionedAt = ($cache['is_cached'] ?? false) ? ($cache['cached_at'] ?? null) : $response->date;
        $revision = $this->timestampMs($versionedAt);

        if ($revision === null) {
            throw new InvalidPayload('no data version: '.(($cache['is_cached'] ?? false) ? 'cached response without cached_at' : 'response without Date header'), 'fansapi');
        }

        $details = [];
        foreach (self::FANSAPI_DETAILS as $column => [$key, $type]) {
            $value = $profile[$key] ?? null;

            $valid = match ($type) {
                'int' => is_int($value) && $value >= 0,
                'bool' => is_bool($value),
                'number' => (is_int($value) || is_float($value)) && $value >= 0 && $value < 1_000_000,
                'string' => is_string($value) && mb_strlen($value) <= 10_000,
                'url' => is_string($value) && strlen($value) <= 1024 && str_starts_with($value, 'https://'),
                'datetime' => $this->timestampMs($value) !== null,
            };

            if ($valid) {
                $details[$column] = $type === 'datetime' ? CarbonImmutable::parse($value) : $value;
            }
        }

        // "Full profile information": the whole validated profile object is kept as JSON (bounded by the
        // 1 MB body cap); the columns above are the typed, queryable subset.
        $details['upstream_payload'] = $profile;

        return new ParsedProfile($likes, $revision, 'fansapi', $details);
    }

    private function likes(mixed $likes, string $format): int
    {
        if (! is_int($likes) || $likes < 0) {
            throw new InvalidPayload('likes must be an integer >= 0, got '.json_encode($likes), $format);
        }

        return $likes;
    }

    private function timestampMs(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (int) CarbonImmutable::parse($value)->getTimestampMs();
        } catch (Throwable) {
            return null;
        }
    }
}
