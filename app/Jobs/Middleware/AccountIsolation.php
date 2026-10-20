<?php

namespace App\Jobs\Middleware;

use App\Jobs\RefreshProfile;
use App\Logging\RefreshLog;
use App\Refresh\AccountThrottle;
use Closure;

/**
 * Keeps one account from consuming all workers. Runs before any upstream call:
 *  1. blocked account -> drop the job (nothing can succeed until someone moves the profile)
 *  2. account cooling down after a 429 -> re-queue for when the cooldown ends, no upstream call
 *  3. account already using all its concurrency slots -> wait up to slot_wait_ms, then re-queue in 1-2s
 * Re-queued jobs wait in Redis' delayed set, so they hold no worker while waiting.
 */
class AccountIsolation
{
    public function __construct(private AccountThrottle $throttle) {}

    public function handle(RefreshProfile $job, Closure $next): void
    {
        $profile = $job->profile();

        if ($profile === null) {
            $next($job); // the job logs and ends

            return;
        }

        RefreshLog::forProfile($profile, function () use ($job, $next, $profile) {
            $account = $profile->account;

            if ($account->isBlocked()) {
                RefreshLog::warning('refresh.skipped_blocked_account', ['reason' => $account->blocked_reason]);

                return;
            }

            if (($cooldown = $this->throttle->cooldownRemaining($account)) > 0) {
                $job->deferFor($cooldown + random_int(0, 3), 'account_cooldown');

                return;
            }

            if (! $this->throttle->withSlot($account, fn () => $next($job))) {
                $job->deferFor(random_int(1, 2), 'account_busy');
            }
        }, $job->job?->uuid(), $job->attempts());
    }
}
