<?php

namespace App\Upstream\Fake;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * Scenario store for the fake upstream. Kept in Redis so every worker process and the
 * fake HTTP endpoint see the same script.
 *
 * Resolution order for each request (account, username):
 *   1. account is rate-limited          -> rate_limited (until the key's TTL expires)
 *   2. scripted steps for the profile   -> next step, consumed in order
 *   3. profile default                  -> repeated on every call
 *   4. account default ('*')            -> repeated on every call
 *   5. nothing configured               -> not_found
 *
 * A default with 'auto' => true serves new_format with a revision that increases on
 * every call (likes = 100000 + revision): an endlessly healthy upstream for the workload.
 */
class FakeUpstream
{
    private const PREFIX = 'fake-upstream:';

    private const REGISTRY = self::PREFIX.'keys';

    /**
     * Append steps to the profile's script.
     */
    public function push(string $account, string $username, array $step, int $times = 1): void
    {
        $this->assertValid($step);
        $key = $this->key($account, $username, 'script');

        for ($i = 0; $i < $times; $i++) {
            Redis::rpush($key, json_encode($step));
        }

        $this->remember($key);
    }

    /**
     * Repeated response once the script is used up. Use '*' as username for the whole account.
     */
    public function setDefault(string $account, string $username, array $step): void
    {
        $this->assertValid($step);
        $key = $this->key($account, $username, 'default');

        Redis::set($key, json_encode($step));
        $this->remember($key);
    }

    /**
     * Every request for this account returns 429 for $seconds, then recovers by itself.
     */
    public function rateLimit(string $account, int $seconds): void
    {
        $key = $this->key($account, '*', 'rate_limited');

        if ($seconds <= 0) {
            Redis::del($key);

            return;
        }

        Redis::setex($key, $seconds, 1);
        $this->remember($key);
    }

    /**
     * Every response for this account takes at least $ms (a slow, overloaded upstream). 0 clears.
     */
    public function latency(string $account, int $ms): void
    {
        $key = $this->key($account, '*', 'latency_ms');

        if ($ms <= 0) {
            Redis::del($key);

            return;
        }

        Redis::set($key, $ms);
        $this->remember($key);
    }

    public function latencyMs(string $account): int
    {
        return (int) Redis::get($this->key($account, '*', 'latency_ms'));
    }

    /**
     * Decide the response for one request (consumes one scripted step if any).
     *
     * @return array{type: string, likes?: int, revision?: int, source: string}
     */
    public function next(string $account, string $username): array
    {
        if (Redis::exists($this->key($account, '*', 'rate_limited'))) {
            return ['type' => 'rate_limited', 'source' => 'account_rate_limit'];
        }

        if ($scripted = Redis::lpop($this->key($account, $username, 'script'))) {
            return json_decode($scripted, true) + ['source' => 'script'];
        }

        foreach ([$username => 'profile_default', '*' => 'account_default'] as $who => $source) {
            if ($default = Redis::get($this->key($account, $who, 'default'))) {
                return $this->resolveAuto(json_decode($default, true), $account, $username) + ['source' => $source];
            }
        }

        return ['type' => 'not_found', 'source' => 'unconfigured'];
    }

    /**
     * Forget every scenario (tests, start of a workload run).
     */
    public function reset(): void
    {
        $keys = Redis::smembers(self::REGISTRY);

        if ($keys !== []) {
            Redis::del(...$keys);
        }

        Redis::del(self::REGISTRY);
    }

    private function resolveAuto(array $step, string $account, string $username): array
    {
        if (! ($step['auto'] ?? false)) {
            return $step;
        }

        $counter = $this->key($account, $username, 'auto_revision');
        $revision = Redis::incr($counter);
        $this->remember($counter);

        return ['type' => 'new_format', 'likes' => 100000 + $revision, 'revision' => $revision];
    }

    private function assertValid(array $step): void
    {
        if (! in_array($step['type'] ?? null, FakeResponses::TYPES, true)) {
            throw new InvalidArgumentException('Unknown fake response type ['.($step['type'] ?? '').']. Use: '.implode(', ', FakeResponses::TYPES));
        }
    }

    private function key(string $account, string $username, string $suffix): string
    {
        return self::PREFIX."{$account}:{$username}:{$suffix}";
    }

    /**
     * Track keys in a set so reset() can delete them without KEYS/SCAN (prefix-safe, O(n) in our keys only).
     */
    private function remember(string $key): void
    {
        Redis::sadd(self::REGISTRY, $key);
    }
}
