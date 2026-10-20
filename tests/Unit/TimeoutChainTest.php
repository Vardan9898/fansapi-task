<?php

namespace Tests\Unit;

use App\Jobs\RefreshProfile;
use Tests\TestCase;

/**
 * The timeouts must nest, or a still-running job can be handed to a second worker:
 *   HTTP (connect + request)  <  job timeout  <  Horizon worker timeout  <  redis retry_after
 * and anything that guards a running job must outlive it.
 */
class TimeoutChainTest extends TestCase
{
    public function test_timeouts_nest_from_http_to_retry_after(): void
    {
        $http = config('upstream.connect_timeout') + config('upstream.timeout');
        $job = RefreshProfile::TIMEOUT;
        $worker = config('horizon.defaults.supervisor-refresh.timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertLessThan($job, $http, 'HTTP must give up before the job timeout');
        $this->assertLessThan($worker, $job, 'job timeout must be below the worker timeout');
        $this->assertLessThan($retryAfter, $worker, 'worker timeout must be below retry_after');
    }

    public function test_locks_outlive_the_job(): void
    {
        $this->assertGreaterThanOrEqual(RefreshProfile::TIMEOUT, config('refresh.slot_ttl_seconds'), 'a dead worker frees its account slot only after the job could have finished');
    }

    public function test_overlap_lock_covers_a_running_job_but_is_gone_before_redelivery(): void
    {
        $worker = config('horizon.defaults.supervisor-refresh.timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertGreaterThanOrEqual($worker, RefreshProfile::OVERLAP_LOCK_SECONDS, 'lock must cover the longest possible run');
        $this->assertLessThan($retryAfter, RefreshProfile::OVERLAP_LOCK_SECONDS, 'a crashed job redelivered after retry_after must not find the dead worker\'s lock and be dropped');
    }

    public function test_fake_timeout_scenario_really_exceeds_the_client_timeout(): void
    {
        $this->assertGreaterThan(config('upstream.timeout'), config('upstream.fake.timeout_sleep'));
    }
}
