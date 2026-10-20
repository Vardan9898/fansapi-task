<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "A worker dies after the DB write, before acknowledging the job": Redis then delivers the same
 * message again. Simulated here by running a real Redis-queued job, then pushing its exact raw
 * payload back and running it again (same job uuid). See workload:crash-replay for a real SIGKILL.
 */
class JobReplayTest extends TestCase
{
    use RefreshDatabase;

    private FakeUpstream $upstream;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        config(['refresh.handler' => 'fixed']);
        $this->upstream = $this->routeUpstreamToFake();
        $this->profile = Profile::factory()
            ->for(Account::factory()->create(['name' => 'agency-a']))
            ->refreshed(likes: 120000, revision: 10)
            ->create(['username' => 'madison420ivy']);
    }

    /**
     * Run the job once from the real Redis queue and return its raw payload (the message Redis would redeliver).
     */
    private function deliverOnce(): array
    {
        RefreshProfile::dispatch($this->profile->id)->onConnection('redis');
        $job = Queue::connection('redis')->pop('refresh');
        $job->fire();

        return [$job->getRawBody(), $job->uuid()];
    }

    private function redeliver(string $payload): string
    {
        Queue::connection('redis')->pushRaw($payload, 'refresh');
        $job = Queue::connection('redis')->pop('refresh');
        $job->fire();

        return $job->uuid();
    }

    public function test_replaying_a_job_after_its_db_write_changes_nothing(): void
    {
        // Upstream is unchanged between the two deliveries: same revision both times
        $this->upstream->setDefault('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        [$payload, $uuid] = $this->deliverOnce();
        $afterFirst = $this->profile->refresh()->only(['likes', 'revision', 'updated_at']);

        $this->assertSame($uuid, $this->redeliver($payload)); // same message, same job uuid

        $this->profile->refresh();
        $this->assertSame(121000, $this->profile->likes);
        $this->assertSame(11, $this->profile->revision);
        $this->assertEquals($afterFirst['updated_at'], $this->profile->updated_at, 'replay must not write the row again');

        $attempts = $this->profile->refreshAttempts()->orderBy('id')->get();
        $this->assertSame([RefreshOutcome::Updated, RefreshOutcome::Duplicate], $attempts->pluck('outcome')->all());
        $this->assertSame([$uuid, $uuid], $attempts->pluck('job_uuid')->all());
        $this->assertSame(['likes' => [120000, 121000], 'revision' => [10, 11]], $attempts[0]->changes);
        $this->assertNull($attempts[1]->changes);
    }

    public function test_replay_after_upstream_moved_on_applies_the_newer_data_once(): void
    {
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);
        $this->upstream->push('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121500, 'revision' => 12]);

        [$payload] = $this->deliverOnce();
        $this->redeliver($payload);

        $this->assertSame(121500, $this->profile->refresh()->likes);
        $this->assertSame(
            [RefreshOutcome::Updated, RefreshOutcome::Updated],
            $this->profile->refreshAttempts()->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_legacy_handler_replay_reapplies_and_records_a_second_success(): void
    {
        config(['refresh.handler' => 'legacy']);
        $this->upstream->setDefault('agency-a', 'madison420ivy', ['type' => 'old_format', 'likes' => 121000, 'revision' => 11]);

        [$payload] = $this->deliverOnce();
        $this->redeliver($payload);

        $this->assertSame(
            [RefreshOutcome::Updated, RefreshOutcome::Updated], // a replay is indistinguishable from a real refresh
            $this->profile->refreshAttempts()->orderBy('id')->pluck('outcome')->all(),
        );
    }
}
