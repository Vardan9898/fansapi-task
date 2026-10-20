<?php

namespace App\Refresh;

use App\Logging\RefreshLog;
use App\Models\Account;
use Illuminate\Support\Facades\Redis;

/**
 * Per-account isolation state in Redis (shared by all workers):
 *  - cooldown: after a 429 the whole account pauses; its jobs are re-queued without calling upstream
 *  - slots:    at most `account_concurrency` workers refresh one account at the same time
 */
class AccountThrottle
{
    /**
     * Seconds left on the account's cooldown (0 = none).
     */
    public function cooldownRemaining(Account $account): int
    {
        return max(0, (int) Redis::ttl($this->key($account, 'cooldown')));
    }

    /**
     * Start (or extend) the account cooldown after a 429. Honors Retry-After when upstream sends it;
     * otherwise waits a randomized, exponentially growing time based on the account's 429 streak.
     *
     * @return int cooldown seconds now in effect
     */
    public function rateLimited(Account $account, ?string $retryAfter): int
    {
        $streakKey = $this->key($account, '429_streak');
        $streak = (int) Redis::incr($streakKey);
        Redis::expire($streakKey, 600);

        $max = config('refresh.cooldown.max_seconds');
        $fromHeader = $this->parseRetryAfter($retryAfter);

        if ($fromHeader !== null) {
            $seconds = min($fromHeader, $max);
        } else {
            $ceiling = min($max, config('refresh.cooldown.base_seconds') * 2 ** min($streak - 1, 16));
            $seconds = random_int(intdiv($ceiling + 1, 2), $ceiling); // "equal jitter": spreads retries out
        }

        $seconds = max(1, $seconds);

        // Never shorten a cooldown another worker already started
        if ($seconds > $this->cooldownRemaining($account)) {
            Redis::setex($this->key($account, 'cooldown'), $seconds, 1);
        }

        RefreshLog::warning('account.cooldown_started', [
            'account_id' => $account->id,
            'seconds' => $seconds,
            'streak' => $streak,
            'retry_after_header' => $retryAfter,
        ]);

        return $this->cooldownRemaining($account);
    }

    /**
     * A successful upstream answer ends the 429 streak (the next 429 starts small again).
     */
    public function succeeded(Account $account): void
    {
        if (Redis::del($this->key($account, '429_streak')) > 0) {
            RefreshLog::info('account.rate_limit_recovered', ['account_id' => $account->id]);
        }
    }

    /**
     * Run $callback only if one of the account's concurrency slots is free. Never waits.
     * The slot is released when the callback ends (or after slot_ttl_seconds if the worker died).
     *
     * @return bool false when all slots are busy (callback not run)
     */
    public function withSlot(Account $account, callable $callback): bool
    {
        $ran = false;

        Redis::funnel($this->key($account, 'slots'))
            ->limit(config('refresh.account_concurrency'))
            ->releaseAfter(config('refresh.slot_ttl_seconds'))
            ->block(0)
            ->then(function () use ($callback, &$ran) {
                $ran = true;
                $callback();
            }, fn () => null);

        return $ran;
    }

    /**
     * Forget cooldown and 429 streak (demo/reproduction resets; not used on the hot path).
     */
    public function reset(Account $account): void
    {
        Redis::del($this->key($account, 'cooldown'), $this->key($account, '429_streak'));
    }

    /**
     * Retry-After is either delta-seconds ("120") or an HTTP date.
     */
    private function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function key(Account $account, string $suffix): string
    {
        return "refresh:account:{$account->id}:{$suffix}";
    }
}
