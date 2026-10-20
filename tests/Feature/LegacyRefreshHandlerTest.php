<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\Fake\FakeUpstream;
use App\Upstream\UpstreamTimeout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization: records what the broken production handler ACTUALLY does, case by case.
 * These pass on purpose; they are the written evidence of the incident (see docs/evidence).
 */
class LegacyRefreshHandlerTest extends TestCase
{
    use RefreshDatabase;

    private FakeUpstream $upstream;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        config(['refresh.handler' => 'legacy']);
        $this->upstream = $this->routeUpstreamToFake();
        $this->profile = Profile::factory()
            ->for(Account::factory()->create(['name' => 'agency-a']))
            ->refreshed(likes: 120000, revision: 10)
            ->create(['username' => 'madison420ivy', 'last_success_at' => now()->subHour()]);
    }

    private function refreshWith(array ...$steps): Profile
    {
        foreach ($steps as $step) {
            $this->upstream->push('agency-a', 'madison420ivy', $step);
        }

        foreach ($steps as $step) {
            RefreshProfile::dispatchSync($this->profile->id);
        }

        return $this->profile->refresh();
    }

    public function test_old_format_works(): void
    {
        $profile = $this->refreshWith(['type' => 'old_format', 'likes' => 120500, 'revision' => 11]);

        $this->assertSame(120500, $profile->likes);
    }

    public function test_new_format_wipes_likes_to_zero_and_reports_success(): void
    {
        $profile = $this->refreshWith(['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $this->assertSame(0, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame(RefreshOutcome::Updated, $profile->last_outcome);
        $this->assertTrue($profile->last_success_at->isToday() && $profile->last_success_at->gt(now()->subMinute()));
        $this->assertSame(['likes' => [120000, 0], 'revision' => [10, 11]], $profile->refreshAttempts()->sole()->changes);
    }

    public function test_rate_limit_with_empty_body_wipes_likes_and_reports_success(): void
    {
        $profile = $this->refreshWith(['type' => 'rate_limited']);

        $this->assertSame(0, $profile->likes);
        $this->assertSame(RefreshOutcome::Updated, $profile->refreshAttempts()->sole()->outcome);
        $this->assertSame(429, $profile->refreshAttempts()->sole()->http_status);
        $this->assertSame(0, $profile->consecutive_failures);
    }

    public function test_server_error_with_empty_body_wipes_likes_and_reports_success(): void
    {
        $profile = $this->refreshWith(['type' => 'server_error']);

        $this->assertSame(0, $profile->likes);
        $this->assertSame(RefreshOutcome::Updated, $profile->refreshAttempts()->sole()->outcome);
    }

    public function test_response_without_likes_wipes_likes(): void
    {
        $profile = $this->refreshWith(['type' => 'invalid', 'revision' => 12]);

        $this->assertSame(0, $profile->likes);
        $this->assertSame(12, $profile->revision);
    }

    public function test_older_response_arriving_last_overwrites_newer_data(): void
    {
        $profile = $this->refreshWith(
            ['type' => 'old_format', 'likes' => 120500, 'revision' => 11],
            ['type' => 'old_format', 'likes' => 120000, 'revision' => 10],
        );

        $this->assertSame(120000, $profile->likes);
        $this->assertSame(10, $profile->revision); // went backwards
    }

    public function test_duplicate_execution_is_recorded_as_two_successful_updates(): void
    {
        $this->refreshWith(
            ['type' => 'old_format', 'likes' => 120500, 'revision' => 11],
            ['type' => 'old_format', 'likes' => 120500, 'revision' => 11],
        );

        $this->assertSame(
            [RefreshOutcome::Updated, RefreshOutcome::Updated],
            $this->profile->refreshAttempts()->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_timeout_throws_and_leaves_no_record(): void
    {
        try {
            $this->refreshWith(['type' => 'timeout']);
            $this->fail('Legacy handler should let the timeout escape');
        } catch (UpstreamTimeout) {
        }

        $this->assertSame(0, $this->profile->refreshAttempts()->count());
        $this->assertSame(120000, $this->profile->refresh()->likes);
    }
}
