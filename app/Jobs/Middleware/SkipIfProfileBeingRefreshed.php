<?php

namespace App\Jobs\Middleware;

use App\Logging\RefreshLog;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Redis;

/**
 * Laravel's WithoutOverlapping(dontRelease), but a skipped job is logged and counted instead of
 * vanishing silently: a second job for a profile that is being refreshed right now is dropped
 * (the running one fetches fresh data).
 */
class SkipIfProfileBeingRefreshed extends WithoutOverlapping
{
    public function __construct(int $profileId, private int $accountId, int $lockSeconds)
    {
        parent::__construct("profile:{$profileId}", expiresAfter: $lockSeconds);

        $this->dontRelease();
    }

    public function handle($job, $next)
    {
        $lock = app(Cache::class)->lock($this->getLockKey($job), $this->expiresAfter);

        if (! $lock->get()) {
            RefreshLog::info('refresh.skipped_overlapping', ['profile_id' => $job->profileId, 'reason' => 'profile already being refreshed']);
            Redis::incr("refresh:stats:account:{$this->accountId}:skipped_overlapping");

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
