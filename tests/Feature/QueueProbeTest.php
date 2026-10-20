<?php

namespace Tests\Feature;

use App\Jobs\RefreshProfile;
use App\Workload\QueueProbe;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class QueueProbeTest extends TestCase
{
    public function test_measures_oldest_waiting_job_per_account_across_ready_and_delayed(): void
    {
        RefreshProfile::dispatch(1)->onConnection('redis');               // busy, ready
        RefreshProfile::dispatch(2)->onConnection('redis')->delay(60);    // healthy, deferred

        $sample = app(QueueProbe::class)->sample([1 => 'busy', 2 => 'healthy', 3 => 'healthy'], now: time() + 10);

        $this->assertSame(1, $sample['ready']);
        $this->assertSame(1, $sample['delayed']);
        $this->assertSame(0, $sample['reserved']);
        $this->assertSame(['busy' => 10, 'healthy' => 10], $sample['oldest']);
    }

    public function test_empty_queue_reports_no_waiting_jobs(): void
    {
        $sample = app(QueueProbe::class)->sample([1 => 'busy']);

        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest' => ['busy' => null]], $sample);
    }

    public function test_parses_profile_id_and_dispatch_time_from_a_payload(): void
    {
        RefreshProfile::dispatch(42)->onConnection('redis');
        $payload = Redis::lindex('queues:refresh', 0);

        [$profileId, $createdAt] = app(QueueProbe::class)->parse($payload);

        $this->assertSame(42, $profileId);
        $this->assertEqualsWithDelta(time(), $createdAt, 2);
    }
}
