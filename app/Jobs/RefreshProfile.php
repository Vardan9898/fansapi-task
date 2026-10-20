<?php

namespace App\Jobs;

use App\Enums\RefreshOutcome;
use App\Jobs\Middleware\AccountIsolation;
use App\Logging\RefreshLog;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use App\Refresh\AttemptContext;
use App\Refresh\PermanentRefreshFailure;
use App\Refresh\RefreshHandler;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Refreshes one profile. Carries only the id (no models, no config reads in the constructor);
 * everything is resolved when the job runs, so a moved profile uses its new account.
 *
 * Timeout chain (enforced by TimeoutChainTest):
 *   HTTP timeout 5s + connect 2s  <  TIMEOUT 15s  <  Horizon worker timeout 20s  <  redis retry_after 30s
 * so a slow upstream fails the attempt, a stuck job is killed by its worker, and Redis never hands a
 * job that is still running to a second worker.
 */
class RefreshProfile implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 15;

    public $timeout = self::TIMEOUT;

    /** Unexpected exceptions (DB down, bugs) retry with $backoff, at most this many times. */
    public $maxExceptions = 3;

    public $backoff = [5, 30, 60];

    private ?Profile $loaded = null;

    public function __construct(public int $profileId)
    {
        $this->onQueue('refresh');
        $this->afterCommit();
    }

    /**
     * Overall limit: after this the job fails, however many 429 deferrals it went through.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config('refresh.retry_window_minutes'));
    }

    public function middleware(): array
    {
        if (config('refresh.handler') === 'legacy') {
            return []; // production as it was: no isolation, no overlap protection
        }

        return [
            // A second job for the same profile while one runs is dropped: the running one fetches fresh data.
            // The lock outlives the job timeout, so a killed worker can't leave it stuck forever.
            (new WithoutOverlapping("profile:{$this->profileId}"))->expireAfter(self::TIMEOUT * 2)->dontRelease(),
            app(AccountIsolation::class),
        ];
    }

    public function profile(): ?Profile
    {
        return $this->loaded ??= Profile::with('account')->find($this->profileId);
    }

    public function handle(RefreshHandler $handler, AccountThrottle $throttle): void
    {
        $profile = $this->profile();

        if ($profile === null) {
            RefreshLog::warning('refresh.profile_missing', ['profile_id' => $this->profileId]);

            return;
        }

        $context = new AttemptContext($this->job?->uuid(), $this->attempts());

        RefreshLog::forProfile($profile, function () use ($handler, $throttle, $profile, $context) {
            RefreshLog::info('refresh.started', ['handler' => $handler->name()]);

            $outcome = $handler->refresh($profile, $context);

            RefreshLog::info('refresh.finished', ['handler' => $handler->name(), 'outcome' => $outcome->value]);

            $this->afterOutcome($outcome, $profile, $throttle);
        }, $context->jobUuid, $context->attempt);
    }

    /**
     * Retry policy per outcome. Success, duplicate and stale are final.
     */
    private function afterOutcome(RefreshOutcome $outcome, Profile $profile, AccountThrottle $throttle): void
    {
        match ($outcome) {
            RefreshOutcome::RateLimited => $this->deferFor(
                max(1, $throttle->cooldownRemaining($profile->account)) + random_int(0, 3),
                'rate_limited',
            ),
            RefreshOutcome::ServerError, RefreshOutcome::Timeout => $this->retryWithBackoff($outcome),
            RefreshOutcome::Failed, RefreshOutcome::InvalidPayload => $this->fail(
                new PermanentRefreshFailure("{$outcome->value}: ".($profile->refresh()->last_error ?? 'n/a')),
            ),
            default => null,
        };
    }

    /**
     * 5xx/timeout: exponential backoff with jitter, at most refresh.max_retries retries per job.
     * Counted from this job's recorded attempts, because re-queues (cooldowns) also bump attempts().
     */
    private function retryWithBackoff(RefreshOutcome $outcome): void
    {
        $failures = $this->job?->uuid()
            ? RefreshAttempt::where('job_uuid', $this->job->uuid())
                ->whereIn('outcome', [RefreshOutcome::ServerError, RefreshOutcome::Timeout])
                ->count()
            : $this->attempts();

        if ($failures > config('refresh.max_retries')) {
            RefreshLog::error('refresh.retries_exhausted', ['failures' => $failures, 'last_outcome' => $outcome->value]);
            $this->fail(new PermanentRefreshFailure("retries exhausted after {$failures} upstream failures ({$outcome->value})"));

            return;
        }

        $ceiling = min(config('refresh.backoff.max_seconds'), config('refresh.backoff.base_seconds') * 2 ** ($failures - 1));
        $this->deferFor(random_int(intdiv($ceiling + 1, 2), max(1, $ceiling)), $outcome->value);
    }

    /**
     * Put the job back on the queue for later (it holds no worker while waiting).
     */
    public function deferFor(int $seconds, string $reason): void
    {
        RefreshLog::info('refresh.deferred', ['reason' => $reason, 'delay_seconds' => $seconds]);

        $this->release($seconds);
    }
}
