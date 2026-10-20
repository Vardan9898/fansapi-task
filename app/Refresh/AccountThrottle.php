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
     * Start the account cooldown after a 429. Honors Retry-After when upstream sends it; otherwise waits a
     * randomized, exponentially growing time: base * 2^(streak-1), "equal jitter" (between half and full).
     *
     * The streak counts failed probe ROUNDS, not individual 429s: concurrent workers of one account hitting
     * the same rate limit must not escalate it several times. Redis SET NX elects one of them to start the
     * cooldown; the others only extend it if upstream sent a longer Retry-After.
     *
     * @return int cooldown seconds now in effect
     */
    public function rateLimited(Account $account, ?string $retryAfter): int
    {
        $max = config('refresh.cooldown.max_seconds');
        $fromHeader = $this->parseRetryAfter($retryAfter);
        $streakKey = $this->key($account, '429_streak');
        $cooldownKey = $this->key($account, 'cooldown');

        $streak = (int) Redis::get($streakKey) + 1;

        if ($fromHeader !== null) {
            $seconds = min($fromHeader, $max);
        } else {
            $ceiling = min($max, config('refresh.cooldown.base_seconds') * 2 ** min($streak - 1, 16));
            $seconds = random_int(intdiv($ceiling + 1, 2), $ceiling);
        }

        $seconds = max(1, $seconds);

        if (! Redis::set($cooldownKey, 1, 'EX', $seconds, 'NX')) {
            // Same wave: a cooldown is already running. Never shorten it; extend only for a longer Retry-After.
            if ($fromHeader !== null && $seconds > $this->cooldownRemaining($account)) {
                Redis::setex($cooldownKey, $seconds, 1);
            }

            return $this->cooldownRemaining($account);
        }

        Redis::incr($streakKey);
        Redis::expire($streakKey, 600);

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
     * Run $callback once one of the account's concurrency slots is free, waiting at most
     * refresh.slot_wait_ms (short jobs free a slot within milliseconds; re-queueing would cost seconds).
     * The slot is released when the callback ends (or after slot_ttl_seconds if the worker died).
     *
     * @return bool false when no slot freed up in time (callback not run)
     */
    public function withSlot(Account $account, callable $callback): bool
    {
        $deadline = microtime(true) + config('refresh.slot_wait_ms') / 1000;

        do {
            if ($this->tryWithSlot($account, $callback)) {
                return true;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function tryWithSlot(Account $account, callable $callback): bool
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
     * Forget cooldown, 429 streak and concurrency slots (demo/reproduction resets; never on the hot path).
     */
    public function reset(Account $account): void
    {
        $slots = array_map(
            fn ($i) => $this->key($account, 'slots').$i, // Laravel's funnel stores slot N under "{name}N"
            range(1, max(1, config('refresh.account_concurrency'))),
        );

        Redis::del($this->key($account, 'cooldown'), $this->key($account, '429_streak'), ...$slots);
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
