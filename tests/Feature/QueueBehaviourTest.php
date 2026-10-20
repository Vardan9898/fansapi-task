<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\Middleware\AccountIsolation;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\AccountThrottle;
use App\Refresh\PermanentRefreshFailure;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retry policy, account isolation and overlap protection, run the way a worker runs the job:
 * through its middleware, with queue interactions (release/fail/delete) recorded.
 */
class QueueBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private FakeUpstream $upstream;

    protected function setUp(): void
    {
        parent::setUp();

        config(['refresh.handler' => 'fixed', 'refresh.cooldown.base_seconds' => 5, 'refresh.max_retries' => 2]);
        $this->upstream = $this->routeUpstreamToFake();
    }

    private function profile(string $account = 'agency-a', string $username = 'madison420ivy'): Profile
    {
        return Profile::factory()
            ->for(Account::firstWhere('name', $account) ?? Account::factory()->create(['name' => $account]))
            ->refreshed(likes: 120000, revision: 10)
            ->create(['username' => $username]);
    }

    /**
     * Run one job like CallQueuedHandler does (middleware pipeline, then handle).
     */
    private function work(Profile $profile, int $attempt = 1): RefreshProfile
    {
        $job = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        (new Pipeline(app()))
            ->send($job)
            ->through($job->middleware())
            ->then(fn (RefreshProfile $job) => app()->call([$job, 'handle']));

        return $job;
    }

    // --- 429: account cooldown -----------------------------------------------------------------

    public function test_rate_limit_starts_a_randomized_account_cooldown_and_requeues_the_job(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'rate_limited']);

        $job = $this->work($profile);

        $cooldown = app(AccountThrottle::class)->cooldownRemaining($profile->account);
        $this->assertGreaterThanOrEqual(3, $cooldown); // first 429: between base/2 and base (5s)
        $this->assertLessThanOrEqual(5, $cooldown);
        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual($cooldown, $job->job->releaseDelay); // comes back after the cooldown
        $this->assertFalse($job->job->hasFailed());
    }

    public function test_consecutive_rate_limits_grow_the_cooldown_exponentially(): void
    {
        $throttle = app(AccountThrottle::class);
        $account = Account::factory()->create();

        $first = $throttle->rateLimited($account, null);
        $throttle->reset($account);
        $throttle->rateLimited($account, null); // streak 1
        $second = $throttle->rateLimited($account, null); // streak 2: ceiling 10s
        $third = $throttle->rateLimited($account, null); // streak 3: ceiling 20s

        $this->assertLessThanOrEqual(5, $first);
        $this->assertGreaterThanOrEqual(5, $second);
        $this->assertLessThanOrEqual(10, $second);
        $this->assertGreaterThanOrEqual(10, $third);
        $this->assertLessThanOrEqual(20, $third);
    }

    public function test_retry_after_header_is_honoured_when_present(): void
    {
        $account = Account::factory()->create();

        $this->assertSame(17, app(AccountThrottle::class)->rateLimited($account, '17'));
    }

    public function test_jobs_for_a_cooling_account_are_requeued_without_calling_upstream(): void
    {
        $profile = $this->profile();
        app(AccountThrottle::class)->rateLimited($profile->account, '30');
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $job = $this->work($profile);

        Http::assertNothingSent();
        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(30, $job->job->releaseDelay);
        $this->assertSame(0, $profile->refreshAttempts()->count()); // not an upstream attempt
    }

    public function test_one_account_cooling_down_does_not_affect_another(): void
    {
        $busy = $this->profile('agency-a');
        $healthy = $this->profile('agency-b');
        app(AccountThrottle::class)->rateLimited($busy->account, '60');
        $this->upstream->push('agency-b', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $job = $this->work($healthy);

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(121000, $healthy->refresh()->likes);
    }

    public function test_success_ends_the_rate_limit_streak(): void
    {
        $profile = $this->profile();
        $throttle = app(AccountThrottle::class);
        $throttle->rateLimited($profile->account, null);
        $throttle->rateLimited($profile->account, null);
        $throttle->reset($profile->account); // cooldown over, streak would remain...
        $throttle->rateLimited($profile->account, '1');
        sleep(1); // ...let the 1s cooldown expire
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $this->work($profile);

        $this->assertLessThanOrEqual(5, $throttle->rateLimited($profile->account, null)); // back to streak 1
    }

    // --- concurrency slots -------------------------------------------------------------------------

    public function test_account_using_all_its_slots_requeues_extra_jobs_without_calling_upstream(): void
    {
        config(['refresh.account_concurrency' => 2]);
        $profile = $this->profile();
        $throttle = app(AccountThrottle::class);
        $job = null;

        // Two jobs of this account are "running" (holding both slots) while a third arrives
        // (regular closures: an arrow fn would capture $job by value and lose the assignment)
        $throttle->withSlot($profile->account, function () use ($throttle, $profile, &$job) {
            $throttle->withSlot($profile->account, function () use ($profile, &$job) {
                $job = $this->work($profile);
            });
        });

        Http::assertNothingSent();
        $this->assertTrue($job->job->isReleased());
        $this->assertLessThanOrEqual(3, $job->job->releaseDelay);
    }

    public function test_slots_are_per_account(): void
    {
        config(['refresh.account_concurrency' => 1]);
        $busy = $this->profile('agency-a');
        $other = $this->profile('agency-b');
        $throttle = app(AccountThrottle::class);
        $this->upstream->push('agency-b', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);
        $job = null;

        $throttle->withSlot($busy->account, function () use ($other, &$job) {
            $job = $this->work($other);
        });

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(121000, $other->refresh()->likes);
    }

    public function test_slot_is_released_even_when_the_job_throws(): void
    {
        config(['refresh.account_concurrency' => 1]);
        $account = Account::factory()->create();
        $throttle = app(AccountThrottle::class);

        try {
            $throttle->withSlot($account, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertTrue($throttle->withSlot($account, fn () => null));
    }

    // --- 5xx / timeout: backoff with a retry limit ---------------------------------------------

    public function test_server_error_is_retried_with_backoff(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'server_error']);

        $job = $this->work($profile);

        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(1, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(2, $job->job->releaseDelay); // first retry: base 2s with jitter
        $this->assertSame(120000, $profile->refresh()->likes);
    }

    public function test_timeout_is_retried_with_backoff(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'timeout']);

        $this->assertTrue($this->work($profile)->job->isReleased());
    }

    public function test_server_errors_stop_after_max_retries_and_fail_the_job(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'server_error']);

        $job = $this->work($profile, attempt: 3); // max_retries = 2: the 3rd failure gives up

        $this->assertFalse($job->job->isReleased());
        $this->assertTrue($job->job->hasFailed());
        $this->assertInstanceOf(PermanentRefreshFailure::class, $job->job->failedWith);
        $this->assertStringContainsString('retries exhausted', $job->job->failedWith->getMessage());
    }

    // --- permanent failures ------------------------------------------------------------------------

    public function test_not_found_fails_immediately_without_retry(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'not_found']);

        $job = $this->work($profile);

        $this->assertFalse($job->job->isReleased());
        $this->assertTrue($job->job->hasFailed());
        $this->assertStringContainsString('HTTP 404', $job->job->failedWith->getMessage());
    }

    public function test_invalid_payload_fails_immediately_without_retry(): void
    {
        $profile = $this->profile();
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'invalid', 'revision' => 12]);

        $job = $this->work($profile);

        $this->assertTrue($job->job->hasFailed());
        $this->assertSame(120000, $profile->refresh()->likes);
    }

    public function test_unauthorized_blocks_the_account_and_later_jobs_are_skipped(): void
    {
        $profile = $this->profile();
        $sibling = $this->profile(username: 'creator_x');
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'unauthorized']);

        $job = $this->work($profile);

        $this->assertTrue($job->job->hasFailed());
        $this->assertTrue($profile->account->refresh()->isBlocked());
        $this->assertStringContainsString('401', $profile->account->blocked_reason);

        Http::fake(); // anything sent from here on would be a bug
        $siblingJob = $this->work($sibling);

        Http::assertNothingSent();
        $this->assertFalse($siblingJob->job->isReleased());
        $this->assertFalse($siblingJob->job->hasFailed());
        $this->assertSame(120000, $sibling->refresh()->likes);
    }

    public function test_forbidden_fails_the_profile_but_does_not_block_the_account(): void
    {
        $profile = $this->profile();
        Http::fake(['*' => Http::response('', 403)]);

        $job = $this->work($profile);

        $this->assertTrue($job->job->hasFailed());
        $this->assertFalse($profile->account->refresh()->isBlocked());
        $this->assertSame(RefreshOutcome::Failed, $profile->refresh()->last_outcome);
    }

    // --- overlap protection and legacy mode ----------------------------------------------------------

    public function test_second_job_for_a_profile_already_being_refreshed_is_dropped(): void
    {
        $profile = $this->profile();
        $lock = Cache::lock((new WithoutOverlapping("profile:{$profile->id}"))->getLockKey(new RefreshProfile($profile->id)), 30);
        $lock->get(); // another worker is running this profile

        $job = $this->work($profile);

        // dontRelease(): the job returns without running and the worker acknowledges it as done
        Http::assertNothingSent();
        $this->assertFalse($job->job->isReleased());
        $this->assertFalse($job->job->hasFailed());
        $this->assertSame(0, $profile->refreshAttempts()->count());
        $lock->release();
    }

    public function test_legacy_mode_runs_without_isolation_middleware(): void
    {
        config(['refresh.handler' => 'legacy']);
        $this->assertSame([], (new RefreshProfile(1))->middleware());

        config(['refresh.handler' => 'fixed']);
        $classes = array_map(fn ($middleware) => $middleware::class, (new RefreshProfile(1))->middleware());
        $this->assertSame([WithoutOverlapping::class, AccountIsolation::class], $classes);
    }

    public function test_job_goes_to_the_refresh_queue_after_commit_with_a_retry_window(): void
    {
        config(['refresh.retry_window_minutes' => 30]);
        $job = new RefreshProfile(1);

        $this->assertSame('refresh', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $job->retryUntil()->getTimestamp(), 2);
    }
}
